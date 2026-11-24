<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DosageForm;
use App\Models\Generic;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DEVELOPMENT DEMO DATA. Mirrors the Nuxt mock catalogue (pos/app/mocks/seed.ts)
 * so the UI looks the same against the real API. Refuses to run in production.
 *
 * Demo logins (local only): admin / password, cashier / password, store / password.
 * Customers are fictional generated names; no legacy customer data is used.
 */
class DemoSeeder extends Seeder
{
    // [brand, strength, generic, form, manufacturer, piece price, pieces/strip, strips/box]
    private const TABLETS = [
        ['Napa', '500 mg', 'Paracetamol', 'Tablet', 'Beximco Pharmaceuticals', 1.2, 10, 20],
        ['Napa Extra', '500 mg+65 mg', 'Paracetamol + Caffeine', 'Tablet', 'Beximco Pharmaceuticals', 2.5, 10, 12],
        ['Ace', '500 mg', 'Paracetamol', 'Tablet', 'Square Pharmaceuticals', 1.2, 10, 20],
        ['Ace Plus', '500 mg+65 mg', 'Paracetamol + Caffeine', 'Tablet', 'Square Pharmaceuticals', 2.5, 10, 12],
        ['Seclo', '20 mg', 'Omeprazole', 'Capsule', 'Square Pharmaceuticals', 6, 10, 10],
        ['Maxpro', '20 mg', 'Esomeprazole', 'Tablet', 'Renata Limited', 7, 10, 10],
        ['Sergel', '20 mg', 'Esomeprazole', 'Capsule', 'Healthcare Pharmaceuticals', 7, 10, 10],
        ['Fexo', '120 mg', 'Fexofenadine', 'Tablet', 'Square Pharmaceuticals', 8, 10, 5],
        ['Alatrol', '10 mg', 'Cetirizine', 'Tablet', 'Square Pharmaceuticals', 3, 10, 10],
        ['Monas', '10 mg', 'Montelukast', 'Tablet', 'ACME Laboratories', 16, 10, 3],
        ['Montair', '10 mg', 'Montelukast', 'Tablet', 'Incepta Pharmaceuticals', 16, 10, 3],
        ['Azithrocin', '500 mg', 'Azithromycin', 'Tablet', 'Beximco Pharmaceuticals', 40, 3, 4],
        ['Zimax', '500 mg', 'Azithromycin', 'Tablet', 'Square Pharmaceuticals', 40, 3, 4],
        ['Ciprocin', '500 mg', 'Ciprofloxacin', 'Tablet', 'Square Pharmaceuticals', 15, 10, 5],
        ['Flagyl', '400 mg', 'Metronidazole', 'Tablet', 'Sanofi Bangladesh', 1.5, 10, 20],
        ['Amodis', '400 mg', 'Metronidazole', 'Tablet', 'Square Pharmaceuticals', 1.5, 10, 20],
        ['Losectil', '20 mg', 'Omeprazole', 'Capsule', 'Eskayef Pharmaceuticals', 5, 10, 10],
        ['Pantonix', '40 mg', 'Pantoprazole', 'Tablet', 'Incepta Pharmaceuticals', 7, 10, 10],
        ['Bizoran', '5 mg+20 mg', 'Amlodipine + Olmesartan', 'Tablet', 'Beximco Pharmaceuticals', 12, 10, 3],
        ['Amdocal', '5 mg', 'Amlodipine', 'Tablet', 'Beximco Pharmaceuticals', 5, 10, 10],
        ['Osartil', '50 mg', 'Losartan Potassium', 'Tablet', 'Incepta Pharmaceuticals', 8, 10, 5],
        ['Comet', '500 mg', 'Metformin', 'Tablet', 'Square Pharmaceuticals', 4, 10, 10],
        ['Secrin', '2 mg', 'Glimepiride', 'Tablet', 'Healthcare Pharmaceuticals', 6, 10, 10],
        ['Rosuva', '10 mg', 'Rosuvastatin', 'Tablet', 'Square Pharmaceuticals', 15, 10, 3],
        ['Ecosprin', '75 mg', 'Aspirin', 'Tablet', 'ACI Limited', 0.6, 14, 20],
        ['Thyrox', '50 mcg', 'Levothyroxine', 'Tablet', 'Renata Limited', 1.6, 30, 1],
        ['Calbo-D', '500 mg+200 IU', 'Calcium + Vitamin D3', 'Tablet', 'Square Pharmaceuticals', 8, 15, 2],
        ['Neuro-B', '100 mg+200 mg+200 mcg', 'Vitamin B1+B6+B12', 'Tablet', 'Square Pharmaceuticals', 8, 10, 10],
        ['Ceevit', '250 mg', 'Vitamin C', 'Chewable Tablet', 'Square Pharmaceuticals', 1.5, 10, 20],
        ['Tofen', '1 mg', 'Ketotifen', 'Tablet', 'Beximco Pharmaceuticals', 2.5, 10, 10],
        ['Deslor', '5 mg', 'Desloratadine', 'Tablet', 'Square Pharmaceuticals', 4, 10, 10],
        ['Esoral', '20 mg', 'Esomeprazole', 'Tablet', 'Eskayef Pharmaceuticals', 7, 10, 10],
        ['Ketorolac', '10 mg', 'Ketorolac Tromethamine', 'Tablet', 'Opsonin Pharma', 10, 10, 5],
        ['Tamen', '500 mg', 'Paracetamol', 'Tablet', 'Opsonin Pharma', 1, 10, 20],
        ['Domperon', '10 mg', 'Domperidone', 'Tablet', 'Beximco Pharmaceuticals', 3, 10, 10],
        ['Emistat', '8 mg', 'Ondansetron', 'Tablet', 'Square Pharmaceuticals', 10, 10, 3],
        ['Fluclox', '500 mg', 'Flucloxacillin', 'Capsule', 'ACI Limited', 10, 8, 4],
        ['Cef-3', '200 mg', 'Cefixime', 'Capsule', 'Square Pharmaceuticals', 35, 6, 1],
        ['Tryptin', '10 mg', 'Amitriptyline', 'Tablet', 'Square Pharmaceuticals', 0.85, 10, 20],
        ['Rivotril', '0.5 mg', 'Clonazepam', 'Tablet', 'Roche', 7, 10, 3],
    ];

    private const LIQUIDS = [
        ['Napa Syrup', '120 mg/5 ml', 'Paracetamol', 'Syrup', 'Beximco Pharmaceuticals', 40, 'bottle'],
        ['Alatrol Syrup', '5 mg/5 ml', 'Cetirizine', 'Syrup', 'Square Pharmaceuticals', 45, 'bottle'],
        ['Tusca Plus', '100 ml', 'Guaiphenesin + combination', 'Syrup', 'Beximco Pharmaceuticals', 85, 'bottle'],
        ['Flagyl Suspension', '200 mg/5 ml', 'Metronidazole', 'Oral Suspension', 'Sanofi Bangladesh', 60, 'bottle'],
        ['Zimax Suspension', '200 mg/5 ml', 'Azithromycin', 'Powder for Suspension', 'Square Pharmaceuticals', 180, 'bottle'],
        ['Betnovate-N', '15 g', 'Betamethasone + Neomycin', 'Cream', 'GSK Bangladesh', 95, 'tube'],
        ['Neosporin', '10 g', 'Neomycin + Polymyxin B', 'Ointment', 'GSK Bangladesh', 120, 'tube'],
        ['Ceftron', '1 g/vial', 'Ceftriaxone', 'Injection', 'Square Pharmaceuticals', 210, 'vial'],
        ['Omidon Drops', '15 ml', 'Domperidone', 'Pediatric Drops', 'Incepta Pharmaceuticals', 55, 'bottle'],
        ['Orsaline-N', '10.25 g', 'Oral Rehydration Salts', 'Oral Powder', 'SMC Enterprise', 6, 'bottle'],
    ];

    private const PRESCRIPTION = ['Azithromycin', 'Ciprofloxacin', 'Clonazepam', 'Cefixime', 'Ceftriaxone', 'Flucloxacillin', 'Amitriptyline'];

    public function run(StockLedger $stock): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must never run in production.');
        }
        $this->call(DatabaseSeeder::class);
        // One transaction: SQLite commits per statement otherwise and seeding takes minutes.
        DB::transaction(fn () => $this->seed($stock));
    }

    private function seed(StockLedger $stock): void
    {
        mt_srand(20261123);

        $dhk = Warehouse::updateOrCreate(['code' => 'DHK1'], ['name' => 'Red Pharma · Dhanmondi', 'type' => 'store']);
        $utr = Warehouse::updateOrCreate(['code' => 'UTR1'], ['name' => 'Red Pharma · Uttara', 'type' => 'store']);
        foreach ([$dhk, $utr] as $wh) {
            foreach (['Counter 1', 'Counter 2'] as $counter) {
                CashRegister::firstOrCreate(['warehouse_id' => $wh->id, 'name' => $counter]);
            }
        }

        $users = [['admin', 'Admin User', 'Admin'], ['cashier', 'Counter Cashier', 'Cashier'], ['store', 'Store Keeper', 'Inventory']];
        foreach ($users as [$username, $name, $role]) {
            $u = User::updateOrCreate(['username' => $username], [
                'name' => $name, 'email' => "$username@example.test", 'password' => 'password', 'default_warehouse_id' => $dhk->id,
            ]);
            $u->syncRoles([$role]);
            $u->warehouses()->sync($role === 'Admin' ? [$dhk->id, $utr->id] : [$dhk->id]);
        }

        if (Product::exists()) {
            return; // catalogue and stock already seeded
        }
        $retail = PriceList::where('code', 'retail')->value('id');
        $sku = 0;
        $make = function (array $row, array $units, string $category, string $skuValue) use ($retail, $dhk, $utr, $stock) {
            [$brand, $strength, $generic, $form, $mfr] = $row;
            $product = Product::create([
                'sku' => $skuValue, 'name' => $brand, 'strength' => $strength,
                'generic_id' => Generic::firstOrCreate(['name' => $generic])->id,
                'dosage_form_id' => DosageForm::firstOrCreate(['name' => $form])->id,
                'manufacturer_id' => Manufacturer::firstOrCreate(['name' => $mfr])->id,
                'category_id' => Category::firstOrCreate(['slug' => Str::slug($category)], ['name' => $category])->id,
                'requires_prescription' => in_array($generic, self::PRESCRIPTION, true),
                'reorder_level_base' => $units[min(1, count($units) - 1)]['factor'] * 5,
                'slug' => Str::slug("$brand $strength"),
            ]);
            foreach ($units as $i => $u) {
                $unit = ProductUnit::create([
                    'product_id' => $product->id, 'unit_code' => $u['code'], 'label' => $u['label'], 'factor' => $u['factor'],
                    'is_base' => $i === 0, 'is_sale_default' => $u['default'] ?? false, 'is_purchase_default' => $i === count($units) - 1, 'sort_order' => $i,
                ]);
                ProductPrice::create(['price_list_id' => $retail, 'product_unit_id' => $unit->id, 'price' => $u['price'], 'effective_from' => now()->subYear()]);
            }
            // 1–3 batches; a few expired / expiring so the expiry UX is visible.
            $piecePrice = $units[0]['price'] / $units[0]['factor'];
            $max = max(40, end($units)['factor'] * 3);
            for ($b = 0, $n = mt_rand(1, 3); $b < $n; $b++) {
                $roll = mt_rand(0, 99);
                $days = $roll < 6 ? -mt_rand(3, 60) : ($roll < 16 ? mt_rand(5, 30) : ($roll < 30 ? mt_rand(31, 90) : mt_rand(120, 900)));
                $batch = Batch::create([
                    'product_id' => $product->id,
                    'batch_no' => strtoupper(substr($brand, 0, 2)).mt_rand(10, 99).chr(65 + mt_rand(0, 25)).mt_rand(100, 999),
                    'expiry_date' => now()->addDays($days)->toDateString(),
                ]);
                foreach ([$dhk, $utr] as $wh) {
                    $stock->move([
                        'warehouse_id' => $wh->id, 'product_id' => $product->id, 'batch_id' => $batch->id,
                        'qty_base' => $b === 0 && mt_rand(0, 99) < 12 ? mt_rand(1, 8) : mt_rand(20, $max),
                        'type' => 'opening', 'unit_cost' => round($piecePrice * 0.82, 4), 'reason' => 'Demo opening stock',
                    ]);
                }
            }
        };

        foreach (self::TABLETS as $i => $row) {
            [, , , $form, , $piece, $perStrip, $stripsPerBox] = $row;
            $units = [
                ['code' => 'piece', 'label' => 'Piece', 'factor' => 1, 'price' => $piece],
                ['code' => 'strip', 'label' => "Strip ($perStrip)", 'factor' => $perStrip, 'price' => round($piece * $perStrip, 2), 'default' => true],
            ];
            if ($stripsPerBox > 1) {
                $units[] = ['code' => 'box', 'label' => "Box ({$stripsPerBox}×{$perStrip})", 'factor' => $perStrip * $stripsPerBox, 'price' => round($piece * $perStrip * $stripsPerBox, 2)];
            }
            $make($row, $units, str_contains($form, 'Capsule') ? 'Capsules' : 'Tablets', (string) (1000 + $i * 7));
        }
        foreach (self::LIQUIDS as $i => $row) {
            [, , , $form, , $price, $unit] = $row;
            $category = in_array($form, ['Cream', 'Ointment'], true) ? 'Topicals' : ($form === 'Injection' ? 'Injectables' : 'Liquids');
            $make($row, [['code' => $unit, 'label' => ucfirst($unit), 'factor' => 1, 'price' => $price, 'default' => true]], $category, (string) (5000 + $i * 11));
        }

        foreach (Manufacturer::all() as $i => $m) {
            Supplier::firstOrCreate(['code' => 'S-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)], ['name' => $m->name.' (distributor)', 'company_name' => $m->name, 'payment_terms_days' => 30]);
        }

        $first = ['Rahim', 'Karim', 'Nusrat', 'Farhana', 'Tanvir', 'Sadia', 'Arif', 'Mitu', 'Rakib', 'Sharmin', 'Imran', 'Jannat'];
        $last = ['Ahmed', 'Hossain', 'Islam', 'Rahman', 'Chowdhury', 'Akter', 'Khan', 'Sarker'];
        for ($i = 0; $i < 20; $i++) {
            $phone = '01'.[3, 5, 6, 7, 8, 9][mt_rand(0, 5)].mt_rand(10000000, 99999999);
            if (Customer::where('phone_normalized', $phone)->exists()) {
                continue;
            }
            $c = Customer::create([
                'code' => 'TMP-'.$i, 'name' => $first[mt_rand(0, 11)].' '.$last[mt_rand(0, 7)], 'phone' => $phone, 'phone_normalized' => $phone,
                'credit_limit' => mt_rand(0, 3) === 0 ? 5000 : null,
            ]);
            $c->update(['code' => 'C-'.str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)]);
        }
    }
}
