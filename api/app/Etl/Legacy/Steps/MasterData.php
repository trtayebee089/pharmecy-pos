<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Warehouse, accounts, staff users, customer groups, customers, suppliers.
 *
 * - Staff only: legacy roles Admin/Owner → Admin, staff and online-order
 *   users → Cashier. Supplier, customer and advertiser logins are not
 *   imported. bcrypt hashes are kept but every user must change the password
 *   at the next sign-in (the hashes were exposed in public backups).
 * - Customers: phone normalised to 01XXXXXXXXX; duplicates by phone are
 *   merged into the most recently updated profile (the others map to it).
 *   Customer passwords and tokens are not imported: customers use "forgot
 *   password" on the website. The legacy walk-in maps to the system walk-in.
 */
class MasterData
{
    public const ROLE_MAP = ['admin' => 'Admin', 'owner' => 'Admin', 'staff' => 'Cashier', 'online orders' => 'Cashier'];

    public function run(Context $c): void
    {
        $this->warehouse($c);
        $this->accounts($c);
        $this->users($c);
        $this->customerGroups($c);
        $this->customers($c);
        $this->suppliers($c);
        $c->flush();
    }

    private function warehouse(Context $c): void
    {
        $legacy = $c->src->table('warehouses')->orderBy('id')->get();
        foreach ($legacy as $i => $w) {
            $code = $i === 0 ? $c->warehouseCode : $c->warehouseCode.($i + 1);
            $id = $c->dst->table('warehouses')->insertGetId([
                'code' => $code, 'name' => $w->name, 'type' => 'store', 'address' => Str::limit((string) $w->address, 250, '') ?: null,
                'phone' => $w->phone ?: null, 'is_active' => (bool) $w->is_active, 'created_at' => Context::ts($w->created_at, now()), 'updated_at' => now(),
            ]);
            $c->dst->table('cash_registers')->insert(['warehouse_id' => $id, 'name' => 'Counter 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $c->map('warehouse', $w->id, $id);
            $c->count('warehouses', 'imported');
        }
        $c->defaultWarehouse = $c->mapped('warehouse', (int) $legacy->first()->id);
    }

    /** Every legacy payment is cash; the single legacy account maps to Cash in hand. */
    private function accounts(Context $c): void
    {
        $cash = (int) $c->dst->table('accounts')->where('code', 'CASH')->value('id');
        $c->cashAccount = $cash;
        $c->cashMethod = (int) $c->dst->table('payment_methods')->where('code', 'cash')->value('id');
        foreach ($c->src->table('accounts')->get() as $a) {
            $c->map('account', $a->id, $cash);
            $c->count('accounts', 'mapped to Cash in hand');
            $c->fact('accounts', 'legacy '.$a->id.' total_balance', (float) $a->total_balance);
        }
        $c->map('account', 0, $cash, false);
    }

    private function users(Context $c): void
    {
        $roles = $c->src->table('roles')->pluck('name', 'id')->map(fn ($n) => strtolower(trim($n)));
        $usernames = $c->dst->table('users')->pluck('username')->map(fn ($u) => strtolower($u))->flip()->all();
        $emails = $c->dst->table('users')->pluck('email')->map(fn ($e) => strtolower($e))->flip()->all();

        // Owner of legacy documents whose user is gone or was not imported.
        $c->systemUser = $c->dst->table('users')->insertGetId([
            'name' => 'Legacy import', 'username' => 'legacy.import', 'email' => 'legacy.import@invalid.local',
            'password' => Hash::make(Str::random(40)), 'is_active' => false, 'must_change_password' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($c->src->table('users')->orderBy('id')->get() as $u) {
            $legacyRole = $roles[$u->role_id] ?? 'unknown';
            $role = self::ROLE_MAP[$legacyRole] ?? null;
            if (! $role) {
                $c->count('users', "skipped: role {$legacyRole}");

                continue;
            }
            $username = Str::slug($u->name, '.') ?: 'user';
            $username = Str::limit($username, 40, '');
            for ($n = 2, $base = $username; isset($usernames[$username]); $n++) {
                $username = $base.$n;
            }
            $usernames[$username] = true;
            $email = strtolower(trim((string) $u->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($emails[$email])) {
                $email = "legacy-user-{$u->id}@invalid.local";
                $c->count('users', 'email replaced (empty or duplicate)');
            }
            $emails[$email] = true;
            $hash = str_starts_with((string) $u->password, '$2y$') ? $u->password : Hash::make(Str::random(40));
            $id = $c->dst->table('users')->insertGetId([
                'name' => $u->name, 'username' => $username, 'email' => $email, 'phone' => $u->phone ?: null,
                'password' => $hash, 'is_active' => $u->is_active && ! $u->is_deleted, 'must_change_password' => true,
                'default_warehouse_id' => $c->defaultWarehouse,
                'created_at' => Context::ts($u->created_at, now()), 'updated_at' => now(),
            ]);
            $c->dst->table('model_has_roles')->insert(['role_id' => Role::findByName($role, 'web')->id, 'model_type' => 'App\\Models\\User', 'model_id' => $id]);
            $c->dst->table('user_warehouses')->insert(['user_id' => $id, 'warehouse_id' => $c->defaultWarehouse]);
            $c->map('user', $u->id, $id);
            $c->count('users', "imported as {$role}");
        }
    }

    public static function userOr(Context $c, mixed $legacyId): int
    {
        return $c->mapped('user', $legacyId === null ? null : (int) $legacyId) ?? $c->systemUser;
    }

    private function customerGroups(Context $c): void
    {
        foreach ($c->src->table('customer_groups')->get() as $g) {
            $existing = $c->dst->table('customer_groups')->where('name', $g->name)->value('id');
            $id = $existing ?: $c->dst->table('customer_groups')->insertGetId([
                'name' => $g->name, 'discount_pct' => (float) $g->percentage, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $c->map('customer_group', $g->id, $id);
            $c->count('customer_groups', 'imported');
        }
    }

    private function customers(Context $c): void
    {
        $walkInLegacy = (int) ($c->src->table('pos_setting')->value('customer_id') ?: 1);
        $walkIn = (int) $c->dst->table('customers')->where('is_walk_in', true)->value('id');
        $c->walkIn = $walkIn;
        $c->map('customer', $walkInLegacy, $walkIn);
        $c->count('customers', 'walk-in mapped');

        $rows = $c->src->table('customers')->where('id', '!=', $walkInLegacy)
            ->orderByDesc('updated_at')->orderByDesc('id')->get();
        $byPhone = [];
        $points = [];
        foreach ($rows as $r) {
            $phone = Context::phone($r->phone_number);
            if ($phone && isset($byPhone[$phone])) {
                // Merge: keep the most recently updated profile.
                $keep = $byPhone[$phone];
                $c->map('customer', $r->id, $keep);
                $points[$keep] = ($points[$keep] ?? 0) + (float) $r->points;
                $c->count('customers', 'merged into a duplicate phone');

                continue;
            }
            if (! $phone) {
                $c->count('customers', 'phone not a valid mobile (kept raw)');
            }
            $address = trim(implode(', ', array_filter([$r->address, $r->city, $r->district ?? null])));
            $id = $c->dst->table('customers')->insertGetId([
                'code' => 'C-TMP-'.$r->id, 'name' => trim($r->name) !== '' ? trim($r->name) : 'Customer '.$r->id,
                'phone' => Str::limit(trim((string) $r->phone_number), 20, '') ?: null, 'phone_normalized' => $phone,
                'email' => filter_var(trim((string) $r->email), FILTER_VALIDATE_EMAIL) ? strtolower(trim($r->email)) : null,
                'gender' => in_array($r->gender, ['male', 'female', 'other'], true) ? $r->gender : null,
                'customer_group_id' => $c->mapped('customer_group', (int) $r->customer_group_id),
                'credit_limit' => (float) $r->due_limit > 0 ? Context::money($r->due_limit) : null,
                'is_walk_in' => false, 'is_active' => (bool) $r->is_active,
                'address' => $address !== '' ? Str::limit($address, 250, '') : null,
                'legacy_id' => $r->id, 'created_at' => Context::ts($r->created_at, now()), 'updated_at' => Context::ts($r->updated_at, now()),
            ]);
            $c->dst->table('customers')->where('id', $id)->update(['code' => 'C-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
            if ($phone) {
                $byPhone[$phone] = $id;
            }
            $points[$id] = ($points[$id] ?? 0) + (float) $r->points;
            $c->map('customer', $r->id, $id);
            $c->count('customers', 'imported');
            if ($r->password) {
                $c->count('customers', 'website password not imported (reset needed)');
            }
        }
        $c->customerPoints = array_filter($points, fn ($p) => $p > 0);
    }

    private function suppliers(Context $c): void
    {
        foreach ($c->src->table('suppliers')->orderBy('id')->get() as $s) {
            $id = $c->dst->table('suppliers')->insertGetId([
                'code' => 'S-TMP-'.$s->id, 'name' => trim($s->name) ?: 'Supplier '.$s->id, 'company_name' => $s->company_name ?: null,
                'phone' => Str::limit(trim((string) $s->phone_number), 20, '') ?: null,
                'email' => filter_var(trim((string) $s->email), FILTER_VALIDATE_EMAIL) ? trim($s->email) : null,
                'address' => Str::limit(trim(implode(', ', array_filter([$s->address, $s->city]))), 250, '') ?: null,
                'is_active' => (bool) $s->is_active, 'legacy_id' => $s->id,
                'created_at' => Context::ts($s->created_at, now()), 'updated_at' => now(),
            ]);
            $c->dst->table('suppliers')->where('id', $id)->update(['code' => 'S-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT)]);
            $c->map('supplier', $s->id, $id);
            $c->count('suppliers', 'imported');
        }
        // Purchases without a supplier (legacy allowed NULL).
        $id = $c->dst->table('suppliers')->insertGetId([
            'code' => 'S-TMP-0', 'name' => 'Unknown supplier (legacy)', 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->dst->table('suppliers')->where('id', $id)->update(['code' => 'S-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT)]);
        $c->unknownSupplier = $id;
    }
}
