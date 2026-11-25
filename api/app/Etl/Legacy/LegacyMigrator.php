<?php

namespace App\Etl\Legacy;

use App\Etl\Legacy\Steps\Catalog;
use App\Etl\Legacy\Steps\Delivery;
use App\Etl\Legacy\Steps\Finance;
use App\Etl\Legacy\Steps\Hr;
use App\Etl\Legacy\Steps\MasterData;
use App\Etl\Legacy\Steps\Openings;
use App\Etl\Legacy\Steps\Payments;
use App\Etl\Legacy\Steps\Purchases;
use App\Etl\Legacy\Steps\Sales;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Runs the legacy → new migration on an empty, migrated and seeded target
 * (docs/rebuild/data-migration-plan.md), then writes a reconciliation report
 * to storage/app/private/etl/. The legacy database is only read.
 */
class LegacyMigrator
{
    public const STEPS = [
        'masters' => MasterData::class, 'catalog' => Catalog::class, 'sales' => Sales::class, 'purchases' => Purchases::class,
        'payments' => Payments::class, 'finance' => Finance::class, 'hr' => Hr::class, 'delivery' => Delivery::class, 'openings' => Openings::class,
    ];

    /** Tables that must be empty in the target (the seeders fill only reference data). */
    public const MUST_BE_EMPTY = ['warehouses', 'products', 'sales', 'purchases', 'payments', 'suppliers', 'employees', 'legacy_id_map'];

    public function assertEmptyTarget(Context $c): void
    {
        $filled = array_filter(self::MUST_BE_EMPTY, fn ($t) => $c->dst->table($t)->exists());
        if ($c->dst->table('customers')->where('is_walk_in', false)->exists()) {
            $filled[] = 'customers';
        }
        if ($filled) {
            throw new RuntimeException('The target database is not empty ('.implode(', ', $filled).'). Run on a fresh database: php artisan migrate:fresh --seed');
        }
        foreach (['price_lists', 'payment_methods', 'accounts'] as $t) {
            if (! $c->dst->table($t)->exists()) {
                throw new RuntimeException("Reference data missing ({$t}). Run php artisan db:seed first.");
            }
        }
        $c->src->table('sales')->limit(1)->exists(); // fails early when the legacy connection is wrong
    }

    /** @return array{json: string, markdown: string} report paths */
    public function run(Context $c): array
    {
        $this->assertEmptyTarget($c);
        $started = microtime(true);
        $c->dst->disableQueryLog();
        $c->src->disableQueryLog();
        // Bulk load with foreign key checks off (ids are mapped by the steps); every link
        // is verified afterwards in "check: links", which must be all zero.
        $c->dst->statement('SET foreign_key_checks = 0');
        try {
            foreach (self::STEPS as $name => $class) {
                $t = microtime(true);
                $c->info("→ {$name}");
                $c->dst->transaction(fn () => (new $class)->run($c));
                $c->fact('timing (s)', $name, round(microtime(true) - $t, 1));
                $c->info('  '.$c->report['timing (s)'][$name].' s');
            }
        } finally {
            $c->dst->statement('SET foreign_key_checks = 1');
        }
        $c->fact('timing (s)', 'total', round(microtime(true) - $started, 1));
        $this->reconcile($c);
        $this->links($c);

        return $this->write($c);
    }

    /** Orphan counts for every link the bulk load wrote (all must be 0). */
    private function links(Context $c): void
    {
        $checks = [
            'sale_items → sales' => 'SELECT COUNT(*) n FROM sale_items i LEFT JOIN sales s ON s.id = i.sale_id WHERE s.id IS NULL',
            'sale_items → product_units' => 'SELECT COUNT(*) n FROM sale_items i LEFT JOIN product_units u ON u.id = i.product_unit_id WHERE i.product_unit_id IS NOT NULL AND u.id IS NULL',
            'sale_item_batches → sale_items / batches' => 'SELECT COUNT(*) n FROM sale_item_batches b LEFT JOIN sale_items i ON i.id = b.sale_item_id LEFT JOIN batches t ON t.id = b.batch_id WHERE i.id IS NULL OR t.id IS NULL',
            'sales → customers / users' => 'SELECT COUNT(*) n FROM sales s LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN users u ON u.id = s.cashier_id WHERE c.id IS NULL OR u.id IS NULL',
            'sale_returns → sales' => 'SELECT COUNT(*) n FROM sale_returns r LEFT JOIN sales s ON s.id = r.sale_id WHERE s.id IS NULL',
            'sale_return_items → returns / sale lines' => 'SELECT COUNT(*) n FROM sale_return_items i LEFT JOIN sale_returns r ON r.id = i.sale_return_id LEFT JOIN sale_item_batches b ON b.id = i.sale_item_batch_id WHERE r.id IS NULL OR b.id IS NULL',
            'purchase_items → purchases / units' => 'SELECT COUNT(*) n FROM purchase_items i LEFT JOIN purchases p ON p.id = i.purchase_id LEFT JOIN product_units u ON u.id = i.product_unit_id WHERE p.id IS NULL OR u.id IS NULL',
            'purchases → suppliers' => 'SELECT COUNT(*) n FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE s.id IS NULL',
            'payment_allocations → payments' => 'SELECT COUNT(*) n FROM payment_allocations a LEFT JOIN payments p ON p.id = a.payment_id WHERE p.id IS NULL',
            'payment_allocations → sale / purchase' => "SELECT COUNT(*) n FROM payment_allocations a LEFT JOIN sales s ON a.allocatable_type = 'sale' AND s.id = a.allocatable_id
                LEFT JOIN purchases p ON a.allocatable_type = 'purchase' AND p.id = a.allocatable_id WHERE a.allocatable_type IN ('sale', 'purchase') AND s.id IS NULL AND p.id IS NULL",
            'product_units / prices / barcodes → products' => 'SELECT (SELECT COUNT(*) FROM product_units u LEFT JOIN products p ON p.id = u.product_id WHERE p.id IS NULL)
                + (SELECT COUNT(*) FROM product_prices x LEFT JOIN product_units u ON u.id = x.product_unit_id WHERE u.id IS NULL)
                + (SELECT COUNT(*) FROM product_barcodes b LEFT JOIN products p ON p.id = b.product_id WHERE p.id IS NULL) n',
            'stock_balances → batches' => 'SELECT COUNT(*) n FROM stock_balances b LEFT JOIN batches t ON t.id = b.batch_id WHERE t.id IS NULL',
            'finance_entries → categories' => 'SELECT COUNT(*) n FROM finance_entries e LEFT JOIN finance_categories c ON c.id = e.category_id WHERE c.id IS NULL',
            'online_order_lines → orders' => 'SELECT COUNT(*) n FROM online_order_lines l LEFT JOIN online_orders o ON o.id = l.online_order_id WHERE o.id IS NULL',
        ];
        foreach ($checks as $name => $sql) {
            $c->fact('check: links (must be 0)', $name, (int) $c->dst->selectOne($sql)->n);
        }
    }

    private function reconcile(Context $c): void
    {
        $imported = "(s.sale_status IN (1, 4) OR (s.sale_status = 0 AND EXISTS (SELECT 1 FROM order_trackings t WHERE t.sale_id = s.id AND t.current_status IN ('delivered', 'returned'))))";
        $legacy = collect($c->src->select("SELECT DATE_FORMAT(s.created_at, '%Y-%m') m, COUNT(*) n, ROUND(SUM(s.grand_total), 2) total FROM sales s WHERE {$imported} GROUP BY m"))->keyBy('m');
        $new = collect($c->dst->select("SELECT DATE_FORMAT(sale_date, '%Y-%m') m, COUNT(*) n, SUM(grand_total) total FROM sales WHERE is_legacy = 1 GROUP BY m"))->keyBy('m');
        $rows = [];
        foreach ($legacy->keys()->merge($new->keys())->unique()->sort() as $m) {
            $l = $legacy->get($m);
            $n = $new->get($m);
            $rows[$m] = ['legacy sales' => (int) ($l->n ?? 0), 'new sales' => (int) ($n->n ?? 0),
                'legacy Σ' => round((float) ($l->total ?? 0), 2), 'new Σ' => round((float) ($n->total ?? 0), 2),
                'Δ' => round((float) ($n->total ?? 0) - (float) ($l->total ?? 0), 2)];
        }
        $c->report['check: sales per month'] = $rows;

        $lp = (float) $c->src->table('payments')->sum('amount');
        $np = (float) $c->dst->table('payments')->sum('amount');
        $rejected = (float) $c->dst->table('etl_rejects')->where('entity', 'payments')->get()->sum(fn ($r) => (float) (json_decode($r->payload)->amount ?? 0));
        $c->report['check: payments'] = ['legacy Σ' => round($lp, 2), 'new Σ' => round($np, 2), 'rejected Σ' => round($rejected, 2),
            'Δ (should be 0)' => round($np + $rejected - $lp, 2)];

        $legacySupplier = (float) ($c->src->selectOne('SELECT SUM(grand_total - paid_amount) d FROM purchases WHERE status IN (1, 2)')->d ?? 0);
        $c->report['check: supplier dues'] = ['legacy Σ(grand_total − paid_amount)' => round($legacySupplier, 2),
            'new opening Σ (after returns, never below 0 per invoice)' => round((float) $c->dst->table('party_ledger_entries')->where(['party_type' => 'supplier', 'type' => 'opening'])->sum('credit'), 2)];

        $walkInLegacy = (int) ($c->src->table('pos_setting')->value('customer_id') ?: 1);
        $c->report['check: reward points'] = ['legacy Σ customers.points (whole points, walk-in excluded)' => (int) $c->src->table('customers')->where('id', '!=', $walkInLegacy)->where('points', '>', 0)->sum($c->src->raw('FLOOR(points)')),
            'new Σ wallets' => (int) $c->dst->table('reward_wallets')->sum('balance'), 'note' => 'merged duplicate profiles sum their points before rounding down'];

        $c->report['check: stock'] = ['legacy Σ products.qty > 0' => (int) $c->src->table('products')->where('qty', '>', 0)->sum($c->src->raw('ROUND(qty)')),
            'legacy Σ product_warehouse.qty > 0 (with duplicate rows)' => (int) $c->src->selectOne('SELECT SUM(q) s FROM (SELECT SUM(qty) q FROM product_warehouse GROUP BY product_id HAVING SUM(qty) > 0) x')->s,
            'new Σ stock_balances' => (int) $c->dst->table('stock_balances')->sum('qty_base'),
            'products where the two legacy sources differ' => (int) $c->src->selectOne('SELECT COUNT(*) n FROM products p LEFT JOIN (SELECT product_id, SUM(qty) q FROM product_warehouse GROUP BY product_id) w ON w.product_id = p.id WHERE ABS(p.qty - COALESCE(w.q, 0)) > 0.001')->n];

        $c->report['check: rows'] = [
            'sales' => [$c->src->table('sales')->count(), $c->dst->table('sales')->count()],
            'sale lines (product + custom)' => [$c->src->table('product_sales')->count() + $c->src->table('custom_product_sales')->count(), $c->dst->table('sale_items')->count()],
            'sale returns' => [$c->src->table('returns')->count(), $c->dst->table('sale_returns')->count()],
            'purchases' => [$c->src->table('purchases')->count(), $c->dst->table('purchases')->count()],
            'purchase lines' => [$c->src->table('product_purchases')->count(), $c->dst->table('purchase_items')->count()],
            'payments' => [$c->src->table('payments')->count(), $c->dst->table('payments')->count()],
            'products' => [$c->src->table('products')->count(), $c->dst->table('products')->count()],
            'customers' => [$c->src->table('customers')->count(), $c->dst->table('customers')->count()],
            'rejects' => ['', $c->dst->table('etl_rejects')->count()],
        ];
    }

    /** @return array{json: string, markdown: string} */
    private function write(Context $c): array
    {
        $stamp = now()->format('Ymd-His');
        $json = "etl/legacy-migration-{$stamp}.json";
        $md = "etl/legacy-migration-{$stamp}.md";
        $disk = Storage::disk('local');
        $disk->put($json, json_encode($c->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $out = "# Legacy migration report\n\nRun {$stamp}. Cutover date {$c->cutoverDate}. Rows with `rejected:` are in the `etl_rejects` table.\n";
        foreach ($c->report as $section => $facts) {
            $out .= "\n## {$section}\n\n";
            $first = reset($facts);
            if (is_array($first) && array_is_list($first)) {
                $out .= "| | legacy | new |\n|---|---:|---:|\n";
                foreach ($facts as $k => [$l, $n]) {
                    $out .= "| {$k} | {$l} | {$n} |\n";
                }
            } elseif (is_array($first)) {
                $cols = array_keys($first);
                $out .= '| | '.implode(' | ', $cols)." |\n|---|".str_repeat('---:|', count($cols))."\n";
                foreach ($facts as $k => $row) {
                    $out .= "| {$k} | ".implode(' | ', array_map(fn ($v) => is_float($v) ? number_format($v, 2) : $v, $row))." |\n";
                }
            } else {
                foreach ($facts as $k => $v) {
                    $out .= "- {$k}: **".(is_float($v) ? number_format($v, 2) : $v)."**\n";
                }
            }
        }
        $disk->put($md, $out);

        return ['json' => $disk->path($json), 'markdown' => $disk->path($md)];
    }
}
