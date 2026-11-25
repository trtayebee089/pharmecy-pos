<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Reward point and membership discount rules (owner decisions, 25 Nov 2026):
 * stored in `settings` (global, key reward_points) and edited by an admin.
 *
 * - Earn: floor(base / soldAmountPerPoint × multipliers) where base is the bill
 *   after all discounts, minus the part paid with points and custom items
 *   (and discounted lines when excludeDiscountedItems). Only bills of at least
 *   minimumSoldAmount earn; walk-ins never earn.
 * - Redeem: points are a POS payment. The customer needs at least
 *   minRedeemPoints, the bill must be at least minRedeemSaleAmount, and points
 *   may pay at most maxRedeemPercent of the bill. 1 point = pointValue taka.
 * - membershipDiscountMode: off (website only, as legacy) | larger (larger of
 *   group and membership %) | stack (both, compounded).
 */
class LoyaltySettings
{
    public const KEY = 'reward_points';

    public const DEFAULTS = [
        'active' => false,
        'soldAmountPerPoint' => 100,
        'minimumSoldAmount' => 0,
        'expiryDuration' => 12,
        'expiryType' => 'Month',
        'pointValue' => 1,
        'minRedeemPoints' => 0,
        'minRedeemSaleAmount' => 0,
        'maxRedeemPercent' => 100,
        'excludeDiscountedItems' => false,
        'membershipDiscountMode' => 'off',
    ];

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = Setting::where(['scope' => 'global', 'scope_id' => 0, 'key' => self::KEY])->value('value');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        return $this->cache = array_merge(self::DEFAULTS, array_intersect_key((array) $stored, self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key];
    }

    public function save(array $values): array
    {
        $clean = array_merge(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS));
        Setting::updateOrCreate(['scope' => 'global', 'scope_id' => 0, 'key' => self::KEY], ['value' => $clean]);
        $this->cache = null;

        return $this->all();
    }

    /** Expiry date for points earned now, or null when points never expire. */
    public function expiryFrom(?Carbon $from = null): ?string
    {
        $n = (int) $this->get('expiryDuration');
        if ($n <= 0) {
            return null;
        }
        $from = ($from ?? now())->copy();

        $until = match ($this->get('expiryType')) {
            'Day' => $from->addDays($n),
            'Year' => $from->addYears($n),
            default => $from->addMonths($n),
        };

        return $until->toDateString();
    }
}
