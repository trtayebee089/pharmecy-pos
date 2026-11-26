<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MembershipPlan;
use App\Models\RewardCampaign;
use App\Models\RewardLedgerEntry;
use App\Services\LoyaltySettings;
use App\Services\Memberships;
use App\Services\OnlineOrders;
use App\Services\RewardLedger;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in customer's reward wallet and membership (read-only). Points are
 * spent at checkout (`reward_points`); the quote here only checks the rules.
 * Memberships are renewed at the counter, where the fee is paid.
 */
class LoyaltyController extends V1Controller
{
    private const COLORS = ['silver' => '#64748b', 'gold' => '#b45309', 'platinum' => '#334155', 'red' => '#b91c1c'];

    public function __construct(private RewardLedger $rewards, private LoyaltySettings $settings, private Memberships $memberships) {}

    public function rewards(Request $request): JsonResponse
    {
        $c = $request->user();
        $s = $this->settings->all();
        $wallet = $this->rewards->wallet($c->id, lock: false);
        $expired = -(int) RewardLedgerEntry::where(['customer_id' => $c->id, 'type' => 'expire'])->sum('points');

        return $this->ok([
            'wallet' => [
                'available_points' => (int) $wallet->balance, 'available_value' => round($wallet->balance * (float) $s['pointValue'], 2),
                'pending_points' => 0, 'expired_points' => $expired, 'redeemed_points' => (int) $wallet->lifetime_redeemed,
                'lifetime_earned' => (int) $wallet->lifetime_earned, 'lifetime_redeemed' => (int) $wallet->lifetime_redeemed,
                'expiring_soon' => $this->rewards->expiringSoon($c->id),
            ],
            'settings' => [
                'active' => (bool) $s['active'], 'point_value' => (float) $s['pointValue'], 'spend_per_point' => (float) $s['soldAmountPerPoint'],
                'min_redeem_points' => (int) $s['minRedeemPoints'], 'min_redeem_order' => (float) $s['minRedeemSaleAmount'],
                'max_redeem_percent' => (float) $s['maxRedeemPercent'],
            ],
            'membership' => ($m = $this->memberships->active($c->id)) ? $this->membership($m) : null,
            'campaigns' => RewardCampaign::where('is_active', true)->where('ends_at', '>=', now()->toDateString())->orderBy('starts_at')->get()
                ->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'description' => $x->description, 'multiplier' => (float) $x->multiplier, 'starts_at' => $x->starts_at, 'ends_at' => $x->ends_at])->all(),
        ]);
    }

    /** Newest first, with the balance after each entry. */
    public function transactions(Request $request): JsonResponse
    {
        $id = $request->user()->id;
        $page = RewardLedgerEntry::where('customer_id', $id)->orderByDesc('id')->paginate(min(50, (int) $request->query('per_page', 20)));
        $after = $page->isEmpty() ? 0 : (int) RewardLedgerEntry::where('customer_id', $id)->where('id', '<=', $page->first()->id)->sum('points');
        $rows = [];
        foreach ($page->getCollection() as $e) {
            $rows[] = [
                'id' => $e->id, 'type' => $e->type, 'status' => 'posted', 'points' => (int) $e->points, 'balance_after' => $after,
                'value' => (float) $e->value, 'note' => $e->note, 'reference' => $e->source_type === 'online_order' ? DB::table('online_orders')->where('id', $e->source_id)->value('number') : null,
                'expires_at' => $e->points > 0 ? $e->expires_at : null, 'occurred_at' => $e->created_at?->toIso8601String(),
            ];
            $after -= (int) $e->points;
        }
        $page->setCollection(collect($rows));

        return $this->page($page);
    }

    /** What `points` would take off an order of `order_total` (goods after discount). */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'min:1'], 'order_total' => ['required', 'numeric', 'min:0']]);
        [$points, $paisa] = app(OnlineOrders::class)->pointsToRedeem($request->user(), (int) $data['points'], SaleService::paisa($data['order_total']));
        if ($points === 0) {
            throw ValidationException::withMessages(['points' => 'Reward points cannot be used right now.']);
        }

        return $this->ok(['points' => $points, 'amount' => SaleService::money($paisa) + 0]);
    }

    public function membership(CustomerMembership $m): array
    {
        $plan = $m->plan;

        return [
            'id' => $m->id, 'status' => $m->status, 'source' => $m->source, 'started_at' => $m->starts_at, 'expires_at' => $m->ends_at,
            'plan' => $this->plan($plan),
        ];
    }

    public function current(Request $request): JsonResponse
    {
        /** @var Customer $c */
        $c = $request->user();
        $m = $this->memberships->active($c->id);
        $spend = $this->memberships->annualSpend($c->id);
        $plans = MembershipPlan::where('is_active', true)->where('min_annual_spend', '>', 0)->orderBy('min_annual_spend')->get();
        $floor = $m ? (float) $m->plan->min_annual_spend : 0.0;
        $next = $plans->first(fn ($p) => (float) $p->min_annual_spend > max($floor, 0));
        $lifetime = (float) DB::table('sales')->where(['customer_id' => $c->id, 'status' => 'completed'])->sum('grand_total')
            - (float) DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')->where('sale_returns.customer_id', $c->id)->where('sales.status', 'completed')->sum('sale_returns.total');
        $mode = $this->settings->get('membershipDiscountMode');

        return $this->ok([
            'membership' => $m ? $this->membership($m) : null,
            'statistics' => ['current_year_purchase' => $spend, 'lifetime_purchase' => round($lifetime, 2), 'reward_balance' => $this->rewards->balance($c->id)],
            'progress' => [
                'next' => $next ? $this->plan($next) : null,
                'remaining_purchase' => $next ? round(max(0, (float) $next->min_annual_spend - $spend), 2) : 0,
                'percentage' => $next ? (int) min(100, floor(100 * $spend / max(1, (float) $next->min_annual_spend))) : ($m ? 100 : 0),
                'fee_required' => false, // tiers are reached by spending; a fee is paid only when renewing at the counter
            ],
            'benefits' => $m ? [
                'discount' => ['code' => 'discount', 'name' => 'Member discount', 'enabled' => $mode !== 'off' && (float) $m->plan->discount_pct > 0, 'value' => (float) $m->plan->discount_pct, 'unit' => 'percent'],
                'points_multiplier' => ['code' => 'points_multiplier', 'name' => 'Reward points', 'enabled' => (float) $m->plan->points_multiplier > 1, 'value' => (float) $m->plan->points_multiplier.'× points on every purchase', 'unit' => 'text'],
            ] : [],
            'plans' => MembershipPlan::where('is_active', true)->orderBy('sort_order')->orderBy('min_annual_spend')->get()->map(fn ($p) => $this->plan($p))->all(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $rows = CustomerMembership::with('plan')->where('customer_id', $request->user()->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'event' => ['auto' => 'upgraded', 'purchase' => 'renewed', 'manual' => 'assigned'][$m->source] ?? $m->source,
                'status' => $m->status, 'new_plan' => $this->plan($m->plan), 'reason' => $m->source === 'auto' ? 'Reached by annual purchases' : null,
                'started_at' => $m->starts_at, 'expires_at' => $m->ends_at, 'occurred_at' => $m->created_at?->toIso8601String(),
            ]);

        return $this->ok(['items' => $rows->all()]);
    }

    /** Renewal needs the plan fee in cash, so it happens at a branch. */
    public function renew(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Memberships are renewed at any of our branches, where the fee is paid.'], 422);
    }

    private function plan(MembershipPlan $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'color' => self::COLORS[strtolower($p->code)] ?? '#0f766e',
            'discount_pct' => (float) $p->discount_pct, 'points_multiplier' => (float) $p->points_multiplier, 'fee' => (float) $p->fee,
            'minimum_annual_purchase' => (float) $p->min_annual_spend, 'duration_months' => (int) $p->duration_months,
        ];
    }
}
