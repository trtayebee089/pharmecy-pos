<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reference data every environment needs (safe to re-run, no demo records):
 * permissions and roles, discount limits, price lists, accounts and payment
 * methods, and the walk-in customer (id 1, the POS default).
 *
 * Demo catalogue, stock and users: `php artisan db:seed --class=DemoSeeder` (dev only).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $all = config('pos.permissions');
        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('Admin', 'web');
        foreach (config('pos.roles') as $role => $perms) {
            $granted = isset($perms['*except']) ? array_values(array_diff($all, $perms['*except'])) : $perms;
            Role::findOrCreate($role, 'web')->syncPermissions($granted);
        }

        $limits = ['Manager' => [15, 15, true], 'Cashier' => [5, 5, false], 'Inventory' => [0, 0, false]];
        foreach ($limits as $role => [$line, $order, $override]) {
            DB::table('role_limits')->updateOrInsert(
                ['role_id' => Role::findByName($role, 'web')->id],
                ['max_line_discount_pct' => $line, 'max_order_discount_pct' => $order, 'can_override_batch' => $override, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        PriceList::updateOrCreate(['code' => 'retail'], ['name' => 'Retail', 'is_default' => true]);
        PriceList::updateOrCreate(['code' => 'wholesale'], ['name' => 'Wholesale', 'is_default' => false]);

        $cash = Account::updateOrCreate(['code' => 'CASH'], ['name' => 'Cash in hand', 'type' => 'cash', 'is_default' => true]);
        $bank = Account::updateOrCreate(['code' => 'BANK'], ['name' => 'Bank account', 'type' => 'bank']);
        $bkash = Account::updateOrCreate(['code' => 'BKASH'], ['name' => 'bKash merchant', 'type' => 'mobile_wallet']);
        $nagad = Account::updateOrCreate(['code' => 'NAGAD'], ['name' => 'Nagad merchant', 'type' => 'mobile_wallet']);
        $rocket = Account::updateOrCreate(['code' => 'ROCKET'], ['name' => 'Rocket merchant', 'type' => 'mobile_wallet']);
        $methods = [
            ['cash', 'Cash', $cash->id, false], ['card', 'Card', $bank->id, true], ['bkash', 'bKash', $bkash->id, true],
            ['nagad', 'Nagad', $nagad->id, true], ['rocket', 'Rocket', $rocket->id, true], ['bank', 'Bank transfer', $bank->id, true],
        ];
        foreach ($methods as [$code, $name, $account, $ref]) {
            PaymentMethod::updateOrCreate(['code' => $code], ['name' => $name, 'account_id' => $account, 'requires_reference' => $ref]);
        }

        if (! Customer::where('is_walk_in', true)->exists()) {
            Customer::create(['code' => 'C-0001', 'name' => 'Walk-in customer', 'is_walk_in' => true]);
        }
    }
}
