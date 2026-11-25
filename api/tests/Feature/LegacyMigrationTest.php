<?php

use App\Etl\Legacy\Context;
use App\Models\Account;
use App\Models\Batch;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\StockAdjustment;
use App\Services\Finance;
use App\Services\PurchaseService;
use App\Services\SaleReversalService;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// The full import runs against MySQL/MariaDB (`php artisan legacy:migrate`) and is checked by its
// reconciliation report; these tests cover its pure rules and the guards it relies on in the POS.

it('normalises legacy phones and numbers documents uniquely', function () {
    expect(Context::phone('+880 1711-223344'))->toBe('01711223344')
        ->and(Context::phone('8801711223344'))->toBe('01711223344')
        ->and(Context::phone('1711223344'))->toBe('01711223344')
        ->and(Context::phone('01211223344'))->toBeNull()
        ->and(Context::phone('12345'))->toBeNull();

    $c = (new ReflectionClass(Context::class))->newInstanceWithoutConstructor();
    expect($c->number('sale', '20250101-010101', 'LEGACY-S-', 1))->toBe('20250101-010101')
        ->and($c->number('sale', '20250101-010101', 'LEGACY-S-', 2))->toBe('20250101-010101-2')
        ->and($c->number('sale', '20250101-010101', 'LEGACY-S-', 3))->toBe('20250101-010101-3')
        ->and($c->number('sale', '', 'LEGACY-S-', 4))->toBe('LEGACY-S-4')
        ->and($c->number('payment', '20250101-010101', 'P-', 5))->toBe('20250101-010101');
});

it('refuses to void legacy purchases, expenses and adjustments (they posted no stock or money)', function () {
    $user = posUser('Admin');
    $purchase = Purchase::create(['ulid' => (string) Str::ulid(), 'number' => 'LEG-P-1', 'warehouse_id' => $this->warehouse->id, 'supplier_id' => supplier()->id,
        'status' => 'received', 'received_at' => now(), 'grand_total' => 100, 'due_total' => 100, 'is_legacy' => true, 'created_by' => $user->id]);
    expect(fn () => app(PurchaseService::class)->void($user, $purchase, 'Wrong supplier'))->toThrow(ValidationException::class, 'imported from the old system');

    $category = FinanceCategory::create(['kind' => 'expense', 'name' => 'Rent']);
    $entry = FinanceEntry::create(['kind' => 'expense', 'number' => 'er-1', 'warehouse_id' => $this->warehouse->id, 'category_id' => $category->id,
        'account_id' => Account::where('code', 'CASH')->value('id'), 'amount' => 500, 'entry_date' => '2026-01-10', 'source_type' => 'legacy', 'status' => 'posted', 'created_by' => $user->id]);
    expect(fn () => app(Finance::class)->voidEntry($user, $entry, 'Duplicate'))->toThrow(ValidationException::class, 'imported from the old system');

    $adj = StockAdjustment::create(['ulid' => (string) Str::ulid(), 'number' => 'adr-1', 'warehouse_id' => $this->warehouse->id, 'reason' => 'correction',
        'note' => 'Legacy adjustment', 'status' => 'posted', 'created_by' => $user->id]);
    DB::table('legacy_id_map')->insert(['entity' => 'stock_adjustment', 'legacy_id' => 7, 'new_id' => $adj->id, 'created_at' => now()]);
    expect(fn () => app(StockAdjustmentService::class)->void($user, $adj, 'Mistake'))->toThrow(ValidationException::class, 'imported from the old system');
});

it('still lets a legacy sale be returned into its LEGACY batch', function () {
    $t = tabletWithStock([]);
    $legacy = Batch::create(['product_id' => $t['product']->id, 'batch_no' => 'LEGACY', 'expiry_date' => null, 'is_legacy_opening' => true]);
    $cashier = posUser();
    $sale = Sale::create(['ulid' => (string) Str::ulid(), 'number' => '20250301-101010', 'channel' => 'pos', 'warehouse_id' => $this->warehouse->id,
        'customer_id' => walkIn()->id, 'cashier_id' => $cashier->id, 'status' => 'completed', 'sale_date' => '2025-03-01 10:10:10',
        'subtotal' => 20, 'grand_total' => 20, 'paid_total' => 20, 'payment_status' => 'paid', 'is_legacy' => true]);
    $item = SaleItem::create(['sale_id' => $sale->id, 'line_type' => 'product', 'product_id' => $t['product']->id, 'product_unit_id' => $t['piece']->id,
        'description' => 'Testamol', 'qty' => 10, 'factor' => 1, 'qty_base' => 10, 'unit_price' => 2, 'line_total' => 20, 'cost_total' => 10]);
    $sib = SaleItemBatch::create(['sale_item_id' => $item->id, 'batch_id' => $legacy->id, 'qty_base' => 10, 'unit_cost' => 1]);

    // Same-day legacy sales cannot be voided: the opening stock already reflects them.
    expect(fn () => app(SaleReversalService::class)->void($cashier, $sale, 'Wrong item'))->toThrow(ValidationException::class, 'imported from the old system');

    $return = app(SaleReversalService::class)->createReturn($cashier, $sale, [['saleItemBatchId' => $sib->id, 'qtyBase' => 4, 'restock' => true]], 'cash', 'Unopened strips');

    expect((float) $return->total)->toBe(8.0)
        ->and(stockOf($legacy))->toBe(4)
        ->and($sib->fresh()->returned_qty_base)->toBe(4);
});
