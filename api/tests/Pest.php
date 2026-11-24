<?php

use App\Models\Batch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::create(['code' => 'DHK1', 'name' => 'Dhanmondi']);
    })
    ->in('Feature');

/** A user with the given role assigned to the test warehouse. */
function posUser(string $role = 'Cashier', ?Warehouse $warehouse = null): User
{
    $warehouse ??= test()->warehouse;
    $user = User::factory()->create(['default_warehouse_id' => $warehouse->id]);
    $user->assignRole($role);
    $user->warehouses()->attach($warehouse->id);

    return $user;
}

/**
 * Tablet with piece (1) / strip (10) / box (100) units priced at 2.00 per piece,
 * and the given batches: [[batchNo, expiry 'Y-m-d', qtyBase, unitCost]].
 *
 * @return array{product: Product, piece: ProductUnit, strip: ProductUnit, box: ProductUnit, batches: array<int, Batch>}
 */
function tabletWithStock(array $batches, float $piecePrice = 2.0, ?Warehouse $warehouse = null): array
{
    $warehouse ??= test()->warehouse;
    $product = Product::create(['sku' => 'T'.uniqid(), 'name' => 'Testamol', 'strength' => '500 mg', 'reorder_level_base' => 10]);
    $retail = PriceList::where('code', 'retail')->value('id');
    $units = [];
    foreach ([['piece', 1], ['strip', 10], ['box', 100]] as $i => [$code, $factor]) {
        $units[$code] = ProductUnit::create(['product_id' => $product->id, 'unit_code' => $code, 'label' => ucfirst($code), 'factor' => $factor, 'is_base' => $i === 0, 'is_sale_default' => $code === 'strip']);
        ProductPrice::create(['price_list_id' => $retail, 'product_unit_id' => $units[$code]->id, 'price' => $piecePrice * $factor, 'effective_from' => now()->subDay()]);
    }
    $made = [];
    foreach ($batches as [$no, $expiry, $qty, $cost]) {
        $batch = Batch::create(['product_id' => $product->id, 'batch_no' => $no, 'expiry_date' => $expiry]);
        app(StockLedger::class)->move(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'batch_id' => $batch->id, 'qty_base' => $qty, 'type' => 'opening', 'unit_cost' => $cost]);
        $made[] = $batch;
    }

    return ['product' => $product, 'batches' => $made] + $units;
}

function stockOf(Batch $batch, ?Warehouse $warehouse = null): int
{
    return (int) StockBalance::where(['batch_id' => $batch->id, 'warehouse_id' => ($warehouse ?? test()->warehouse)->id])->value('qty_base');
}

function walkIn(): Customer
{
    return Customer::where('is_walk_in', true)->firstOrFail();
}

function supplier(): Supplier
{
    return Supplier::firstOrCreate(['code' => 'S-001'], ['name' => 'Test Distributor']);
}

function day(int $offset): string
{
    return now()->addDays($offset)->toDateString();
}
