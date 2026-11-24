<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-role discount ceilings from role_limits. Admin is unlimited (null);
 * a role without a row may not discount at all.
 */
class DiscountLimits
{
    /** @return array{line: ?float, order: ?float, overrideBatch: bool} */
    public function for(User $user): array
    {
        if ($user->isAdmin()) {
            return ['line' => null, 'order' => null, 'overrideBatch' => true];
        }
        $roleIds = $user->roles()->pluck('id');
        $rows = DB::table('role_limits')->whereIn('role_id', $roleIds)->get();

        return [
            'line' => (float) ($rows->max('max_line_discount_pct') ?? 0),
            'order' => (float) ($rows->max('max_order_discount_pct') ?? 0),
            'overrideBatch' => (bool) $rows->max('can_override_batch'),
        ];
    }

    public function canOverrideBatch(User $user): bool
    {
        return $this->for($user)['overrideBatch'];
    }
}
