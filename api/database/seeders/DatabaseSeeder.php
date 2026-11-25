<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Customer;
use App\Models\MembershipPlan;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Setting;
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
        // Default permissions: a new role gets its full default set; an existing role only gets permissions that
        // are new since the last run, so an admin's changes in Administration › Roles are never undone.
        $known = (array) (Setting::where(['scope' => 'global', 'scope_id' => 0, 'key' => 'permissions_seeded'])->value('value') ?? []);
        foreach (config('pos.roles') as $role => $perms) {
            $granted = isset($perms['*except']) ? array_values(array_diff($all, $perms['*except'])) : $perms;
            $existing = Role::where(['name' => $role, 'guard_name' => 'web'])->first();
            if (! $existing) {
                Role::create(['name' => $role, 'guard_name' => 'web'])->syncPermissions($granted);
            } elseif ($new = array_values(array_diff($granted, $known))) {
                $existing->givePermissionTo($new);
            }
        }
        Setting::updateOrCreate(['scope' => 'global', 'scope_id' => 0, 'key' => 'permissions_seeded'], ['value' => $all]);

        // Default discount limits, only where a role has none yet.
        $limits = ['Manager' => [15, 15, true], 'Cashier' => [5, 5, false], 'Inventory' => [0, 0, false]];
        foreach ($limits as $role => [$line, $order, $override]) {
            $roleId = Role::findByName($role, 'web')->id;
            if (! DB::table('role_limits')->where('role_id', $roleId)->exists()) {
                DB::table('role_limits')->insert(['role_id' => $roleId, 'max_line_discount_pct' => $line, 'max_order_discount_pct' => $order,
                    'can_override_batch' => $override, 'updated_at' => now(), 'created_at' => now()]);
            }
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
        // Online card / mobile banking through SSLCommerz (bKash checkout books to the bKash method above).
        $ssl = Account::firstOrCreate(['code' => 'SSLCZ'], ['name' => 'SSLCommerz merchant', 'type' => 'bank']);
        PaymentMethod::firstOrCreate(['code' => 'sslcommerz'], ['name' => 'SSLCommerz', 'account_id' => $ssl->id, 'requires_reference' => true]);
        // Reward points as a tender: no money account, the reward ledger carries it.
        PaymentMethod::updateOrCreate(['code' => 'points'], ['name' => 'Reward points', 'account_id' => null, 'requires_reference' => false]);

        // The POS edits three fixed tiers. They start inactive with no discount until an admin configures them.
        foreach ([['Silver', 'Silver', 1], ['Gold', 'Gold', 2], ['Platinum', 'Platinum', 3]] as [$code, $name, $order]) {
            MembershipPlan::firstOrCreate(['code' => $code], [
                'name' => $name, 'min_annual_spend' => 0, 'discount_pct' => 0, 'points_multiplier' => 1, 'fee' => 0,
                'duration_months' => 12, 'benefits' => [], 'is_active' => false, 'sort_order' => $order,
            ]);
        }

        if (! Customer::where('is_walk_in', true)->exists()) {
            Customer::create(['code' => 'C-0001', 'name' => 'Walk-in customer', 'is_walk_in' => true]);
        }
    }
}
