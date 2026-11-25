<?php

namespace App\Console\Commands;

use App\Etl\Legacy\Context;
use App\Etl\Legacy\LegacyMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports the legacy POS database (connection `legacy`, read only) into this
 * API's database. The target must be freshly migrated and seeded:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan legacy:migrate --cutover-date=2026-12-01
 *
 * Rules and mapping: docs/rebuild/data-migration-plan.md and app/Etl/Legacy.
 */
class LegacyMigrate extends Command
{
    protected $signature = 'legacy:migrate
        {--cutover-date= : Date of the opening balances (default today)}
        {--warehouse-code=DHK1 : Code of the imported branch}
        {--stock-source=products : Opening stock from products.qty (products) or Σ product_warehouse.qty (warehouse)}
        {--force : Run without the confirmation prompt}';

    protected $description = 'Migrate the legacy POS database into the new schema (history + opening balances)';

    public function handle(): int
    {
        $source = $this->option('stock-source');
        if (! in_array($source, ['products', 'warehouse'], true)) {
            $this->error('--stock-source must be products or warehouse.');

            return self::INVALID;
        }
        $code = strtoupper((string) $this->option('warehouse-code'));
        if (! preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
            $this->error('--warehouse-code must be 2–10 capital letters or digits.');

            return self::INVALID;
        }
        $legacy = config('database.connections.legacy.database');
        $target = config('database.connections.'.config('database.default').'.database');
        $this->line("Legacy (read only): <info>{$legacy}</info>  →  target: <info>{$target}</info>");
        if (! $this->option('force') && ! $this->confirm('Import into this target database?')) {
            return self::FAILURE;
        }

        ini_set('memory_limit', '2G');
        $context = new Context(
            cutoverDate: $this->option('cutover-date') ?: now()->toDateString(),
            warehouseCode: $code,
            stockSource: $source,
            log: fn (string $m) => $this->line($m),
        );
        try {
            $paths = app(LegacyMigrator::class)->run($context);
        } catch (Throwable $e) {
            $this->error(Str::limit($e->getMessage(), 600));

            return self::FAILURE;
        }
        $this->info('Done in '.$context->report['timing (s)']['total'].' s.');
        $this->line('Report: '.$paths['markdown']);

        return self::SUCCESS;
    }
}
