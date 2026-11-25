<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\CashRegisterSession;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\RewardLedgerEntry;
use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Undoing sales. Documents are never edited or deleted:
 *
 * - Void: the whole sale, only while its register session is still open (or the
 *   same day when it had no session) and before any return. Stock goes back to
 *   the exact batches, payments are marked void with reversing account entries,
 *   and the customer ledger is reversed. The invoice stays on record as void.
 * - Return: part or all of the items, per sold batch, never more than sold
 *   minus already returned. The refund per piece is the line price after line
 *   and order discount. Restocked pieces go back to their batch; others are
 *   written off (they already left stock at the sale).
 */
class SaleReversalService
{
    public function __construct(
        private StockLedger $stock,
        private DocumentNumbers $numbers,
        private RegisterService $registers,
        private RewardLedger $rewards,
    ) {}

    /**
     * $forOrder: the sale behind an online order being cancelled before delivery. The
     * counter's time window (open register session / same day) does not apply: the goods
     * never left the shop, or came back with the rider.
     */
    public function void(User $user, Sale $sale, string $reason, bool $forOrder = false): Sale
    {
        return DB::transaction(function () use ($user, $sale, $reason, $forOrder) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'Only completed sales can be voided.']);
            }
            if (SaleReturn::where('sale_id', $sale->id)->exists()) {
                throw ValidationException::withMessages(['sale' => 'This sale has returns. Return the remaining items instead of voiding.']);
            }
            if (CustomerPayments::hasCollections($sale->id)) {
                throw ValidationException::withMessages(['sale' => 'A due payment was collected on this sale later. Use a return instead.']);
            }
            if ($forOrder) {
                // no time window for an undelivered online order
            } elseif ($sale->session_id) {
                if (CashRegisterSession::whereKey($sale->session_id)->value('status') !== 'open') {
                    throw ValidationException::withMessages(['sale' => 'The register session of this sale is closed. Use a return instead.']);
                }
            } elseif (! $sale->sale_date->isToday()) {
                throw ValidationException::withMessages(['sale' => 'Only sales from today can be voided. Use a return instead.']);
            }

            $batches = SaleItemBatch::with('batch')->whereIn('sale_item_id', $sale->items()->pluck('id'))->get();
            foreach ($batches as $b) {
                $this->stock->move([
                    'warehouse_id' => $sale->warehouse_id, 'product_id' => $b->batch->product_id, 'batch_id' => $b->batch_id,
                    'qty_base' => $b->qty_base, 'type' => 'sale_void', 'unit_cost' => (float) $b->unit_cost,
                    'source_type' => 'sale', 'source_id' => $sale->id, 'source_line_id' => $b->sale_item_id,
                    'reason' => $reason, 'created_by' => $user->id,
                ]);
            }

            $payments = Payment::whereIn('id', PaymentAllocation::where(['allocatable_type' => 'sale', 'allocatable_id' => $sale->id])->pluck('payment_id'))
                ->where('status', 'posted')->get();
            foreach ($payments as $p) {
                $p->update(['status' => 'void']);
                if ($p->account_id) {
                    AccountTransaction::create([
                        'account_id' => $p->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'out',
                        'amount' => $p->amount, 'source_type' => 'payment_void', 'source_id' => $p->id, 'note' => 'Void '.$sale->number,
                    ]);
                }
            }

            $this->ledger($sale->customer_id, 'void', debit: (float) $sale->paid_total, credit: (float) $sale->grand_total, sourceType: 'sale', sourceId: $sale->id, note: 'Void '.$sale->number, userId: $user->id);

            // Loyalty: spent points go back to the wallet; earned points are taken back (may go negative if already spent).
            $this->rewards->credit($sale->customer_id, 'reverse', (int) $sale->points_redeemed, ['sale', $sale->id], 'Void '.$sale->number, $user->id);
            $this->rewards->debit($sale->customer_id, 'reverse', (int) $sale->points_earned, ['sale', $sale->id], 'Void '.$sale->number, $user->id, mustCover: false);

            $sale->update(['status' => 'void', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::record($user->id, 'sale.voided', 'sale', $sale->id, ['reason' => $reason]);

            return $sale;
        });
    }

    /**
     * @param  array<int, array{saleItemBatchId: int, qtyBase: int, restock: bool}>  $items
     */
    public function createReturn(User $user, Sale $sale, array $items, string $refundMethod, string $reason): SaleReturn
    {
        $ids = array_column($items, 'saleItemBatchId');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'Each sold batch can appear only once.']);
        }
        $method = null;
        if ($refundMethod !== 'credit') {
            $method = PaymentMethod::where('is_active', true)->where('code', $refundMethod)->first();
            if (! $method) {
                throw ValidationException::withMessages(['refundMethod' => 'Refund method is not enabled.']);
            }
        }

        return DB::transaction(function () use ($user, $sale, $items, $method, $refundMethod, $reason) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'Only completed sales can be returned.']);
            }
            $saleItems = $sale->items()->get()->keyBy('id');
            $sibs = SaleItemBatch::with('batch')->whereIn('id', array_column($items, 'saleItemBatchId'))
                ->whereIn('sale_item_id', $saleItems->keys())->lockForUpdate()->get()->keyBy('id');

            // Order discount is shared across lines in proportion to their value.
            $afterLineDiscounts = (float) $sale->subtotal - (float) $sale->line_discount_total;
            $orderRatio = $afterLineDiscounts > 0 ? (float) $sale->grand_total / $afterLineDiscounts : 0;

            $lines = [];
            $total = 0;
            foreach ($items as $i => $it) {
                $sib = $sibs->get($it['saleItemBatchId']);
                if (! $sib) {
                    throw ValidationException::withMessages(["items.$i.saleItemBatchId" => 'This batch line is not part of the sale.']);
                }
                $left = $sib->qty_base - $sib->returned_qty_base;
                if ($it['qtyBase'] > $left) {
                    throw ValidationException::withMessages(["items.$i.qtyBase" => "Only {$left} pieces of batch {$sib->batch->batch_no} can still be returned."]);
                }
                $item = $saleItems->get($sib->sale_item_id);
                $perPiece = $item->qty_base > 0 ? (float) $item->line_total / $item->qty_base * $orderRatio : 0;
                $paisa = (int) round($it['qtyBase'] * $perPiece * 100);
                $total += $paisa;
                $lines[] = ['sib' => $sib, 'item' => $item, 'qty' => (int) $it['qtyBase'], 'restock' => (bool) $it['restock'], 'perPiece' => $perPiece, 'paisa' => $paisa];
            }
            $alreadyReturned = SaleService::paisa(SaleReturn::where('sale_id', $sale->id)->sum('total'));
            $total = min($total, SaleService::paisa($sale->grand_total) - $alreadyReturned);

            $warehouse = Warehouse::findOrFail($sale->warehouse_id);
            $session = $this->registers->current($user, $sale->warehouse_id);
            $return = SaleReturn::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('sale_return', $warehouse),
                'sale_id' => $sale->id, 'warehouse_id' => $sale->warehouse_id, 'customer_id' => $sale->customer_id,
                'session_id' => $session?->id, 'refund_method' => $refundMethod, 'total' => SaleService::money($total),
                'restocked_qty_base' => array_sum(array_map(fn ($l) => $l['restock'] ? $l['qty'] : 0, $lines)),
                'written_off_qty_base' => array_sum(array_map(fn ($l) => $l['restock'] ? 0 : $l['qty'], $lines)),
                'reason' => $reason, 'created_by' => $user->id,
            ]);

            foreach ($lines as $l) {
                if ($l['qty'] <= 0) {
                    continue;
                }
                SaleReturnItem::create([
                    'sale_return_id' => $return->id, 'sale_item_id' => $l['item']->id, 'sale_item_batch_id' => $l['sib']->id,
                    'batch_id' => $l['sib']->batch_id, 'qty_base' => $l['qty'], 'unit_refund' => round($l['perPiece'], 4),
                    'line_total' => SaleService::money($l['paisa']), 'restock' => $l['restock'],
                ]);
                $l['sib']->increment('returned_qty_base', $l['qty']);
                if ($l['restock']) {
                    $this->stock->move([
                        'warehouse_id' => $sale->warehouse_id, 'product_id' => $l['sib']->batch->product_id, 'batch_id' => $l['sib']->batch_id,
                        'qty_base' => $l['qty'], 'type' => 'sale_return', 'unit_cost' => (float) $l['sib']->unit_cost,
                        'source_type' => 'sale_return', 'source_id' => $return->id, 'source_line_id' => $l['item']->id,
                        'reason' => $reason, 'created_by' => $user->id,
                    ]);
                }
            }

            // Loyalty, in proportion to the returned value: the share paid with points goes back as points
            // (not cash), and the points earned on that share are taken back.
            $ratio = (float) $sale->grand_total > 0 ? $total / SaleService::paisa($sale->grand_total) : 0;
            $back = fn (string $kind) => (int) abs(RewardLedgerEntry::where(['source_type' => 'sale_return_'.$kind])
                ->whereIn('source_id', SaleReturn::where('sale_id', $sale->id)->pluck('id'))->sum('points'));
            $pointsBack = min((int) round($sale->points_redeemed * $ratio), $sale->points_redeemed - $back('redeem'));
            $pointsBackPaisa = $pointsBack > 0 ? (int) round(SaleService::paisa($sale->points_value) * $pointsBack / $sale->points_redeemed) : 0;
            $earnedBack = min((int) round($sale->points_earned * $ratio), $sale->points_earned - $back('earn'));
            $this->rewards->credit($sale->customer_id, 'reverse', $pointsBack, ['sale_return_redeem', $return->id], $return->number, $user->id);
            $this->rewards->debit($sale->customer_id, 'reverse', $earnedBack, ['sale_return_earn', $return->id], $return->number, $user->id, mustCover: false);

            $this->ledger($sale->customer_id, 'return', debit: 0, credit: (float) SaleService::money($total), sourceType: 'sale_return', sourceId: $return->id, note: $return->number, userId: $user->id);
            if ($pointsBackPaisa > 0) {
                $this->ledger($sale->customer_id, 'refund', debit: (float) SaleService::money($pointsBackPaisa), credit: 0, sourceType: 'sale_return', sourceId: $return->id, note: $return->number.' (points)', userId: $user->id);
            }
            $total -= $pointsBackPaisa; // money still to refund
            $money = SaleService::money($total);
            if ($method && $total > 0) {
                $payment = Payment::create([
                    'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', $warehouse),
                    'direction' => 'out', 'party_type' => 'customer', 'party_id' => $sale->customer_id,
                    'payment_method_id' => $method->id, 'account_id' => $method->account_id, 'amount' => $money,
                    'paid_at' => now(), 'session_id' => $session?->id, 'created_by' => $user->id, 'note' => 'Refund '.$return->number,
                ]);
                PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'sale_return', 'allocatable_id' => $return->id, 'amount' => $money]);
                if ($method->account_id) {
                    AccountTransaction::create([
                        'account_id' => $method->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'out',
                        'amount' => $money, 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $return->number,
                    ]);
                }
                $this->ledger($sale->customer_id, 'refund', debit: (float) $money, credit: 0, sourceType: 'sale_return', sourceId: $return->id, note: $return->number, userId: $user->id);
            }
            AuditLog::record($user->id, 'sale.returned', 'sale', $sale->id, ['return' => $return->number, 'total' => $money]);

            return $return;
        });
    }

    private function ledger(int $customerId, string $type, float $debit, float $credit, string $sourceType, int $sourceId, string $note, int $userId): void
    {
        if ($debit == 0 && $credit == 0) {
            return;
        }
        PartyLedgerEntry::create([
            'party_type' => 'customer', 'party_id' => $customerId, 'entry_date' => now()->toDateString(), 'type' => $type,
            'debit' => $debit, 'credit' => $credit, 'source_type' => $sourceType, 'source_id' => $sourceId, 'note' => $note, 'created_by' => $userId,
        ]);
    }
}
