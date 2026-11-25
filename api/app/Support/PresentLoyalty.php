<?php

namespace App\Support;

use App\Models\CustomerMembership;
use App\Models\MembershipPlan;
use App\Models\RewardCampaign;
use App\Models\RewardLedgerEntry;
use App\Services\RewardLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty shapes for the POS (pos/app/types/people.ts: RewardWallet,
 * RewardEntry, MembershipPlan, Member, Campaign).
 */
class PresentLoyalty
{
    /**
     * Points balance and active membership for many customers at once.
     *
     * @return array{points: Collection<int, int>, tiers: Collection<int, string>, discounts: Collection<int, float>}
     */
    public static function forCustomers(Collection $customerIds): array
    {
        $today = now()->toDateString();
        $active = DB::table('customer_memberships')->join('membership_plans', 'membership_plans.id', '=', 'customer_memberships.plan_id')
            ->whereIn('customer_memberships.customer_id', $customerIds)->where('customer_memberships.status', 'active')
            ->where('customer_memberships.starts_at', '<=', $today)->where('customer_memberships.ends_at', '>=', $today)
            ->orderBy('customer_memberships.id')
            ->get(['customer_memberships.customer_id', 'membership_plans.code', 'membership_plans.discount_pct'])->keyBy('customer_id');

        return [
            'points' => DB::table('reward_wallets')->whereIn('customer_id', $customerIds)->pluck('balance', 'customer_id')->map(fn ($v) => (int) $v),
            'tiers' => $active->map(fn ($r) => $r->code),
            'discounts' => $active->map(fn ($r) => (float) $r->discount_pct),
        ];
    }

    public static function wallets(Collection $wallets): array
    {
        $loyalty = self::forCustomers($wallets->pluck('customer_id'));
        $ledger = app(RewardLedger::class);

        return $wallets->map(fn ($w) => [
            'id' => $w->id, 'customerId' => $w->customer_id, 'customer' => $w->customer?->name ?? '', 'phone' => $w->customer?->phone,
            'balance' => (int) $w->balance, 'lifetimeEarned' => (int) $w->lifetime_earned, 'lifetimeRedeemed' => (int) $w->lifetime_redeemed,
            'tier' => $loyalty['tiers'][$w->customer_id] ?? null,
            'expiringSoon' => $ledger->expiringSoon($w->customer_id),
            'updatedAt' => $w->updated_at?->toIso8601String(),
        ])->values()->all();
    }

    public static function entries(int $customerId, int $limit = 200): array
    {
        return RewardLedgerEntry::where('customer_id', $customerId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn (RewardLedgerEntry $e) => [
                'id' => $e->id, 'date' => $e->created_at->toIso8601String(), 'type' => $e->type, 'points' => $e->points,
                'ref' => $e->source_type ? (string) $e->note : '',
                'note' => $e->source_type ? self::label($e) : (string) $e->note,
            ])->all();
    }

    public static function plan(MembershipPlan $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'minAnnualSpend' => (float) $p->min_annual_spend,
            'discountPct' => (float) $p->discount_pct, 'pointsMultiplier' => (float) $p->points_multiplier, 'fee' => (float) $p->fee,
            'durationMonths' => (int) $p->duration_months, 'benefits' => $p->benefits ?? [], 'isActive' => $p->is_active,
            'members' => CustomerMembership::where(['plan_id' => $p->id, 'status' => 'active'])->where('ends_at', '>=', now()->toDateString())->count(),
        ];
    }

    public static function member(CustomerMembership $m): array
    {
        $status = $m->status === 'active' && $m->ends_at < now()->toDateString() ? 'expired' : $m->status;

        return [
            'id' => $m->id, 'customerId' => $m->customer_id, 'customer' => $m->customer?->name ?? '', 'phone' => $m->customer?->phone,
            'plan' => $m->plan?->code, 'startsAt' => $m->starts_at, 'endsAt' => $m->ends_at, 'status' => $status, 'source' => $m->source,
        ];
    }

    public static function campaign(RewardCampaign $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'multiplier' => (float) $c->multiplier, 'startsAt' => $c->starts_at, 'endsAt' => $c->ends_at,
            'isActive' => $c->is_active, 'description' => (string) $c->description,
        ];
    }

    private static function label(RewardLedgerEntry $e): string
    {
        return match ($e->source_type) {
            'sale' => $e->type === 'earn' ? 'Earned on sale' : ($e->type === 'redeem' ? 'Paid with points' : 'Sale voided'),
            'sale_return_redeem' => 'Points paid back on return',
            'sale_return_earn' => 'Earned points taken back on return',
            default => (string) $e->note,
        };
    }
}
