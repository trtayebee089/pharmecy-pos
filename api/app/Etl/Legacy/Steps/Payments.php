<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Illuminate\Support\Str;

/**
 * Legacy payments (all cash) with one allocation each, then paid/due on the
 * imported sales and purchases. payments.id = legacy id. They post no account
 * transactions: account balances start from the opening balance the business
 * confirms at cutover.
 *
 * Sale: paid = Σ payments, due = max(0, total − paid − returns). A return
 * beyond the open due was refunded in cash (legacy recorded no refund payment).
 * Purchase (received): due = max(0, total − paid − returned); ordered: 0.
 */
class Payments
{
    public function run(Context $c): void
    {
        $c->src->table('payments')->chunkById(10000, function ($rows) use ($c) {
            $payments = [];
            $allocations = [];
            foreach ($rows as $p) {
                if ($p->sale_id) {
                    $party = $c->saleCustomer[$p->sale_id] ?? null;
                    [$type, $direction, $target, $partyType] = ['sale', 'in', $p->sale_id, 'customer'];
                } else {
                    $party = $c->purchaseSupplier[$p->purchase_id] ?? null;
                    [$type, $direction, $target, $partyType] = ['purchase', 'out', $p->purchase_id, 'supplier'];
                }
                if (! $party) {
                    $c->reject('payments', $p->id, "{$type} not imported", ['sale_id' => $p->sale_id, 'purchase_id' => $p->purchase_id, 'amount' => $p->amount]);

                    continue;
                }
                $date = Context::ts($p->created_at, now()->toDateTimeString());
                $amount = Context::money($p->amount);
                $payments[] = [
                    'id' => $p->id, 'ulid' => Context::ulid(), 'number' => $c->number('payment', $p->payment_reference, 'LEGACY-PAY-', $p->id),
                    'direction' => $direction, 'party_type' => $partyType, 'party_id' => $party, 'payment_method_id' => $c->cashMethod,
                    'account_id' => $c->mapped('account', (int) $p->account_id) ?? $c->cashAccount, 'amount' => $amount, 'reference' => null,
                    'paid_at' => $date, 'session_id' => null, 'status' => 'posted',
                    'note' => Str::limit(trim(strip_tags((string) $p->payment_note)), 1000, '') ?: null,
                    'created_by' => MasterData::userOr($c, $p->user_id), 'created_at' => $date, 'updated_at' => $date,
                ];
                $allocations[] = ['payment_id' => $p->id, 'allocatable_type' => $type, 'allocatable_id' => $target, 'amount' => $amount, 'created_at' => $date, 'updated_at' => $date];
                $c->count('payments', "imported ({$type})");
                $c->count('payments', "Σ amount ({$type})", round((float) $p->amount, 2));
                if ((float) $p->amount < 0) {
                    $c->count('payments', 'negative amount');
                }
            }
            $c->insert('payments', $payments);
            $c->insert('payment_allocations', $allocations, 2000);
        });
        $c->flush();

        $c->dst->statement("UPDATE sales s
            LEFT JOIN (SELECT allocatable_id id, SUM(amount) a FROM payment_allocations WHERE allocatable_type = 'sale' GROUP BY allocatable_id) p ON p.id = s.id
            LEFT JOIN (SELECT sale_id, SUM(total) r FROM sale_returns GROUP BY sale_id) r ON r.sale_id = s.id
            SET s.paid_total = COALESCE(p.a, 0),
                s.due_total = GREATEST(0, s.grand_total - COALESCE(p.a, 0) - COALESCE(r.r, 0)),
                s.payment_status = CASE WHEN s.grand_total - COALESCE(p.a, 0) - COALESCE(r.r, 0) <= 0 THEN 'paid'
                                        WHEN COALESCE(p.a, 0) > 0 THEN 'partial' ELSE 'unpaid' END
            WHERE s.is_legacy = 1");
        $c->dst->statement("UPDATE purchases pu
            LEFT JOIN (SELECT allocatable_id id, SUM(amount) a FROM payment_allocations WHERE allocatable_type = 'purchase' GROUP BY allocatable_id) p ON p.id = pu.id
            LEFT JOIN (SELECT purchase_id, SUM(amount) r FROM purchase_return_allocations GROUP BY purchase_id) r ON r.purchase_id = pu.id
            SET pu.paid_total = COALESCE(p.a, 0), pu.returned_total = COALESCE(r.r, 0),
                pu.due_total = CASE WHEN pu.status = 'received' THEN GREATEST(0, pu.grand_total - COALESCE(p.a, 0) - COALESCE(r.r, 0)) ELSE 0 END
            WHERE pu.is_legacy = 1");
    }
}
