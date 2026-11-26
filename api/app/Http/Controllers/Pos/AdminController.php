<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Mail\TemporaryPassword;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AppSettings;
use App\Services\LoyaltySettings;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Administration (owner decisions, 25 Nov 2026): only Admins manage users,
 * roles and permissions (roles.manage); the admin sets a temporary password
 * that is emailed to the user and must be changed at the next sign-in.
 * Guards against lock-out: nobody disables or demotes themselves, and the last
 * active Admin always stays. Fixes legacy KI-010 (self-promotion to Admin).
 */
class AdminController extends Controller
{
    public function __construct(private AppSettings $settings) {}

    // ---------------------------------------------------------------- users

    public function users(): JsonResponse
    {
        return response()->json(User::with('roles', 'warehouses')->orderBy('name')->get()->map(fn ($u) => self::user($u))->values());
    }

    public function createUser(Request $request): JsonResponse
    {
        $d = $this->validateUser($request);
        $pw = $request->validate(['password' => ['required', 'string', Password::min(8)]])['password'];
        $user = DB::transaction(function () use ($d, $pw) {
            $user = User::create([
                'name' => trim($d['name']), 'username' => $d['username'], 'email' => $d['email'] ?: $d['username'].'@users.invalid',
                'password' => $pw, 'must_change_password' => true, 'is_active' => true,
            ]);
            $this->applyAccess($user, $d);

            return $user;
        });
        $this->sendPassword($user, $pw, true, $d['email'] ?? null);
        AuditLog::record($request->user()->id, 'user.created', 'user', $user->id, ['username' => $user->username, 'role' => $d['role']], $request);

        return response()->json(['id' => $user->id] + self::user($user->fresh(['roles', 'warehouses'])), 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $d = $this->validateUser($request, $user);
        if ($user->id === $request->user()->id && $d['role'] !== $user->roles->first()?->name) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }
        if ($user->isAdmin() && $d['role'] !== User::ADMIN_ROLE) {
            $this->assertNotLastAdmin($user);
        }
        DB::transaction(function () use ($user, $d) {
            $user->update(['name' => trim($d['name']), 'username' => $d['username'], 'email' => $d['email'] ?: $user->email]);
            $this->applyAccess($user, $d);
        });
        AuditLog::record($request->user()->id, 'user.updated', 'user', $user->id, ['role' => $d['role'], 'warehouses' => $d['warehouses']], $request);

        return response()->json(['id' => $user->id] + self::user($user->fresh(['roles', 'warehouses'])));
    }

    public function enable(Request $request, User $user): JsonResponse
    {
        return $this->setActive($request, $user, true);
    }

    public function disable(Request $request, User $user): JsonResponse
    {
        return $this->setActive($request, $user, false);
    }

    private function setActive(Request $request, User $user, bool $active): JsonResponse
    {
        if (! $active) {
            if ($user->id === $request->user()->id) {
                throw ValidationException::withMessages(['user' => 'You cannot disable your own account.']);
            }
            if ($user->isAdmin()) {
                $this->assertNotLastAdmin($user);
            }
            $this->revokeSessions($user);
        }
        $user->update(['is_active' => $active]);
        AuditLog::record($request->user()->id, $active ? 'user.enabled' : 'user.disabled', 'user', $user->id, null, $request);

        return response()->json(self::user($user->fresh(['roles', 'warehouses'])));
    }

    /** Admin sets a temporary password; the user must change it at next sign-in. Their sessions end. */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $pw = $request->validate(['password' => ['required', 'string', Password::min(8)]])['password'];
        $user->forceFill(['password' => $pw, 'must_change_password' => true])->save();
        $this->revokeSessions($user);
        $this->sendPassword($user, $pw, false, $user->email);
        AuditLog::record($request->user()->id, 'user.password_reset', 'user', $user->id, null, $request);

        return response()->json(['emailed' => $this->hasRealEmail($user->email)] + self::user($user->fresh(['roles', 'warehouses'])));
    }

    // ---------------------------------------------------------------- roles

    public function roles(): JsonResponse
    {
        $limits = DB::table('role_limits')->pluck('max_line_discount_pct', 'role_id');
        $groups = collect(config('pos.permission_groups'))->map(fn ($perms, $module) => [
            'module' => $module, 'permissions' => collect($perms)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ])->values();

        $holders = DB::table('model_has_roles')->where('model_type', User::class)->groupBy('role_id')->pluck(DB::raw('COUNT(*)'), 'role_id');

        return response()->json([
            'roles' => Role::with('permissions')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'description' => (string) $r->description, 'users' => (int) ($holders[$r->id] ?? 0),
                'permissions' => $r->name === User::ADMIN_ROLE ? config('pos.permissions') : $r->permissions->pluck('name')->values(),
                'maxDiscountPct' => $r->name === User::ADMIN_ROLE ? 100 : (float) ($limits[$r->id] ?? 0),
                'system' => in_array($r->name, config('pos.system_roles'), true),
            ])->values(),
            'groups' => $groups,
        ]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')], 'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'], 'permissions.*' => [Rule::in(config('pos.permissions'))], 'maxDiscountPct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $role = DB::transaction(function () use ($d) {
            $role = Role::create(['name' => trim($d['name']), 'guard_name' => 'web', 'description' => $d['description'] ?? null]);
            $role->syncPermissions($d['permissions']);
            $this->setLimit($role, (float) $d['maxDiscountPct']);

            return $role;
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AuditLog::record($request->user()->id, 'role.created', 'role', $role->id, $d, $request);

        return response()->json(['id' => $role->id], 201);
    }

    public function saveRole(Request $request, Role $role): JsonResponse
    {
        $this->assertEditable($role);
        $d = $request->validate([
            'permissions' => ['present', 'array'], 'permissions.*' => [Rule::in(config('pos.permissions'))],
            'maxDiscountPct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        DB::transaction(function () use ($role, $d) {
            $role->syncPermissions($d['permissions']);
            $this->setLimit($role, (float) $d['maxDiscountPct']);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AuditLog::record($request->user()->id, 'role.permissions_changed', 'role', $role->id, $d, $request);

        return response()->json(['ok' => true]);
    }

    public function roleDetails(Request $request, Role $role): JsonResponse
    {
        $this->assertEditable($role);
        $d = $request->validate(['name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role->id)], 'description' => ['nullable', 'string', 'max:255']]);
        $role->update(['name' => trim($d['name']), 'description' => $d['description'] ?? null]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['ok' => true]);
    }

    public function deleteRole(Request $request, Role $role): JsonResponse
    {
        $this->assertEditable($role);
        if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Users still have this role. Move them to another role first.']);
        }
        DB::table('role_limits')->where('role_id', $role->id)->delete();
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AuditLog::record($request->user()->id, 'role.deleted', 'role', $role->id, ['name' => $role->name], $request);

        return response()->json(['message' => 'Role deleted.']);
    }

    // ---------------------------------------------------------------- warehouses

    public function warehouses(Request $request): JsonResponse
    {
        $q = Warehouse::query()->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('code', $s)))->orderBy('id');
        if (filter_var($request->query('all'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($q->get()->map(fn ($w) => self::warehouseShape($w))->values());
        }
        $page = $q->paginate((int) $request->query('perPage', 25), ['*'], 'page', (int) $request->query('page', 1));

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($w) => self::warehouseShape($w))->all()));
    }

    public function warehouse(Warehouse $warehouse): JsonResponse
    {
        return response()->json(self::warehouseShape($warehouse));
    }

    public function saveWarehouse(Request $request, ?Warehouse $warehouse = null): JsonResponse
    {
        if ($warehouse?->exists) {
            $request->merge(array_merge(self::warehouseShape($warehouse), $request->all()));
        }
        $d = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/', Rule::unique('warehouses', 'code')->ignore($warehouse?->id)],
            'name' => ['required', 'string', 'max:120'], 'type' => ['required', 'in:store,warehouse'],
            'address' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'invoiceHeader' => ['nullable', 'string', 'max:500'], 'invoiceFooter' => ['nullable', 'string', 'max:500'], 'isActive' => ['nullable', 'boolean'],
            // Map position of the branch: the "store" marker for riders and the start of each delivery.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ], ['code.regex' => 'Use capital letters and digits only (it appears in document numbers).']);
        if ($warehouse?->exists && $warehouse->code !== $d['code'] && Sale::where('warehouse_id', $warehouse->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'This branch already has documents; its code cannot change.']);
        }
        if ($warehouse?->exists && ! ($d['isActive'] ?? true) && Warehouse::where('is_active', true)->whereKeyNot($warehouse->id)->doesntExist()) {
            throw ValidationException::withMessages(['isActive' => 'At least one branch must stay active.']);
        }
        $attrs = ['code' => $d['code'], 'name' => trim($d['name']), 'type' => $d['type'], 'address' => $d['address'] ?? null, 'phone' => $d['phone'] ?? null,
            'invoice_header' => $d['invoiceHeader'] ?? null, 'invoice_footer' => $d['invoiceFooter'] ?? null, 'is_active' => $d['isActive'] ?? true,
            'latitude' => $d['latitude'] ?? null, 'longitude' => $d['longitude'] ?? null];
        $created = ! $warehouse?->exists;
        $warehouse = DB::transaction(function () use ($warehouse, $attrs, $created) {
            $w = $created ? Warehouse::create($attrs) : tap($warehouse)->update($attrs);
            if ($created && $w->type === 'store') {
                CashRegister::create(['warehouse_id' => $w->id, 'name' => 'Counter 1']);
            }

            return $w;
        });
        AuditLog::record($request->user()->id, $created ? 'warehouse.created' : 'warehouse.updated', 'warehouse', $warehouse->id, $attrs, $request);

        return response()->json(self::warehouseShape($warehouse->fresh()), $created ? 201 : 200);
    }

    /** Branches with any history are deactivated, never deleted. */
    public function deleteWarehouse(Request $request, Warehouse $warehouse): JsonResponse
    {
        if (Warehouse::where('is_active', true)->whereKeyNot($warehouse->id)->doesntExist()) {
            abort(422, 'At least one branch must stay active.');
        }
        $used = DB::table('stock_movements')->where('warehouse_id', $warehouse->id)->exists() || Sale::where('warehouse_id', $warehouse->id)->exists()
            || DB::table('purchases')->where('warehouse_id', $warehouse->id)->exists() || DB::table('user_warehouses')->where('warehouse_id', $warehouse->id)->exists();
        if ($used) {
            $warehouse->update(['is_active' => false]);
            $message = 'Branch has history, so it was deactivated instead of deleted.';
        } else {
            CashRegister::where('warehouse_id', $warehouse->id)->delete();
            $warehouse->delete();
            $message = 'Branch deleted.';
        }
        AuditLog::record($request->user()->id, 'warehouse.removed', 'warehouse', $warehouse->id, ['result' => $message], $request);

        return response()->json(['message' => $message]);
    }

    // ---------------------------------------------------------------- settings

    public function generalSettings(): JsonResponse
    {
        $loyalty = app(LoyaltySettings::class)->all();

        return response()->json($this->settings->general() + [
            // Shown for reference; edited in Administration › Reward points.
            'pointsPerTaka' => (float) $loyalty['soldAmountPerPoint'] > 0 ? round(1 / (float) $loyalty['soldAmountPerPoint'], 4) : 0,
            'pointValue' => (float) $loyalty['pointValue'],
        ]);
    }

    public function saveGeneralSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nearExpiryDays' => ['required', 'integer', 'min:1', 'max:730'], 'criticalExpiryDays' => ['required', 'integer', 'min:0', 'lt:nearExpiryDays'],
            'allowNegativeStock' => ['required', 'boolean'], 'blockExpiredSale' => ['required', 'boolean'],
            'roundTo' => ['required', Rule::in([0, 0.5, 1, '0', '0.5', '1'])], 'receiptWidth' => ['nullable', 'in:58mm,80mm,A4'],
            'receiptFooter' => ['nullable', 'string', 'max:500'], 'printOnComplete' => ['nullable', 'boolean'], 'rxReminder' => ['nullable', 'boolean'],
            'defaultCustomerId' => ['nullable', 'integer', 'exists:customers,id'],
        ]);
        $d['roundTo'] = (float) $d['roundTo'];
        $saved = $this->settings->saveGeneral($d);
        AuditLog::record($request->user()->id, 'settings.general', 'settings', null, $saved, $request);

        return $this->generalSettings();
    }

    public function posSettings(): JsonResponse
    {
        return response()->json($this->settings->pos() + ['paymentMethods' => $this->paymentMethods()]);
    }

    public function savePosSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'layout' => ['required', 'in:grid,classic'], 'defaultCustomerId' => ['nullable', 'integer'], 'defaultWarehouseId' => ['nullable', 'integer'],
            'defaultBillerId' => ['nullable', 'integer'], 'productsPerPage' => ['nullable', 'integer', 'min:6', 'max:200'], 'productGrid' => ['nullable', 'in:comfortable,compact'],
            'touchKeyboard' => ['nullable', 'boolean'], 'sendSmsAfterSale' => ['nullable', 'boolean'], 'requireCustomerForDue' => ['nullable', 'boolean'],
            'invoice' => ['nullable', 'array'], 'printers' => ['nullable', 'array', 'max:50'],
            'printers.*.id' => ['required', 'integer'], 'printers.*.name' => ['required', 'string', 'max:60'],
            'printers.*.connection' => ['required', 'in:browser,qz,printnode,network'],
            'paymentMethods' => ['nullable', 'array'], 'paymentMethods.*.code' => ['required', 'string'],
            'paymentMethods.*.label' => ['required', 'string', 'max:60'], 'paymentMethods.*.enabled' => ['required', 'boolean'],
            'paymentMethods.*.account' => ['nullable', 'string'], 'paymentMethods.*.requiresReference' => ['required', 'boolean'],
            'print_node_api_key' => ['nullable', 'string', 'max:200'],
        ]);
        DB::transaction(function () use ($request, $d) {
            foreach ($d['paymentMethods'] ?? [] as $i => $m) {
                $method = PaymentMethod::where('code', $m['code'])->first();
                if (! $method || in_array($method->code, ['points', 'customer_credit'], true)) {
                    continue;
                }
                if ($method->code === 'cash' && ! $m['enabled']) {
                    throw ValidationException::withMessages(["paymentMethods.$i.enabled" => 'Cash cannot be switched off.']);
                }
                $accountId = ! empty($m['account']) ? (Account::where('name', $m['account'])->value('id') ?? $method->account_id) : $method->account_id;
                $method->update(['name' => trim($m['label']), 'is_active' => $m['enabled'], 'requires_reference' => $m['requiresReference'], 'account_id' => $accountId]);
            }
            $this->settings->savePos($request->except(['paymentMethods', 'print_node_api_key', 'printNodeConfigured']), $d['print_node_api_key'] ?? null);
        });
        // Never log the PrintNode key.
        AuditLog::record($request->user()->id, 'settings.pos', 'settings', null, ['layout' => $d['layout'], 'printers' => count($d['printers'] ?? [])], $request);

        return $this->posSettings();
    }

    /** Browser and QZ Tray print from the counter itself; server-side printing (PrintNode, network) is not built yet. */
    public function testPrint(int $printer): JsonResponse
    {
        $p = collect($this->settings->pos()['printers'])->firstWhere('id', $printer);
        if (! $p) {
            abort(404, 'Printer not found.');
        }

        return response()->json(match ($p['connection']) {
            'browser', 'qz' => ['ok' => true, 'message' => 'This printer prints from the counter computer: use Print on that computer to test it.'],
            default => ['ok' => false, 'message' => 'Server-side printing (PrintNode or network) is not set up yet.'],
        });
    }

    // ---------------------------------------------------------------- helpers

    private function validateUser(Request $request, ?User $user = null): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
            'warehouses' => ['required', 'array', 'min:1'], 'warehouses.*' => ['required'],
        ]);
        $d['warehouseIds'] = collect($d['warehouses'])->map(function ($w) {
            $found = Warehouse::where('id', is_numeric($w) ? (int) $w : 0)->orWhere('name', $w)->orWhere('code', $w)->orWhere('name', 'like', '%'.$w)->value('id');

            return $found ?? throw ValidationException::withMessages(['warehouses' => "Unknown branch: $w."]);
        })->unique()->values()->all();

        return $d;
    }

    private function applyAccess(User $user, array $d): void
    {
        $user->syncRoles([$d['role']]);
        $user->warehouses()->sync($d['warehouseIds']);
        if (! $user->default_warehouse_id || ! in_array($user->default_warehouse_id, $d['warehouseIds'], true)) {
            $user->update(['default_warehouse_id' => $d['warehouseIds'][0]]);
        }
    }

    private function assertNotLastAdmin(User $user): void
    {
        $admins = User::role(User::ADMIN_ROLE)->where('is_active', true)->whereKeyNot($user->id)->count();
        if ($admins === 0) {
            throw ValidationException::withMessages(['role' => 'This is the last active Admin. Make someone else Admin first.']);
        }
    }

    private function assertEditable(Role $role): void
    {
        if (in_array($role->name, config('pos.system_roles'), true)) {
            throw ValidationException::withMessages(['role' => 'The Admin role always has every permission and cannot be changed.']);
        }
    }

    private function setLimit(Role $role, float $pct): void
    {
        DB::table('role_limits')->updateOrInsert(['role_id' => $role->id], ['max_line_discount_pct' => $pct, 'max_order_discount_pct' => $pct, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Ends the user's sessions and tokens (signs them out everywhere). */
    private function revokeSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

    private function hasRealEmail(?string $email): bool
    {
        return $email !== null && $email !== '' && ! str_ends_with($email, '@users.invalid');
    }

    private function sendPassword(User $user, string $password, bool $isNew, ?string $email): void
    {
        if (! $this->hasRealEmail($email)) {
            return; // no email on file: the admin hands the temporary password over in person
        }
        try {
            Mail::to($email)->send(new TemporaryPassword($user->name, $user->username, $password, $isNew));
        } catch (\Throwable $e) {
            // The account change stands; never log the password itself.
            Log::warning('Temporary password email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    private function paymentMethods(): array
    {
        return PaymentMethod::with('account')->whereNotIn('code', ['points', 'customer_credit'])->orderBy('id')->get()->map(fn ($m) => [
            'code' => $m->code, 'label' => $m->name, 'enabled' => $m->is_active, 'account' => $m->account?->name ?? '', 'requiresReference' => $m->requires_reference,
        ])->values()->all();
    }

    public static function user(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'username' => $u->username,
            'email' => str_ends_with((string) $u->email, '@users.invalid') ? '' : (string) $u->email,
            'role' => $u->roles->first()?->name ?? 'No role', 'warehouses' => $u->warehouses->pluck('name')->values()->all(),
            'isActive' => $u->is_active, 'lastLoginAt' => $u->last_login_at?->toIso8601String(), 'mustChangePassword' => $u->must_change_password,
        ];
    }

    private static function warehouseShape(Warehouse $w): array
    {
        return [
            'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'type' => $w->type, 'address' => (string) $w->address, 'phone' => (string) $w->phone,
            'invoiceHeader' => (string) $w->invoice_header, 'invoiceFooter' => (string) $w->invoice_footer, 'isActive' => $w->is_active,
            'latitude' => $w->latitude, 'longitude' => $w->longitude,
        ];
    }
}
