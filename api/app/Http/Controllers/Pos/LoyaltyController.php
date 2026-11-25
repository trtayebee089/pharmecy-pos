<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MembershipPlan;
use App\Models\RewardCampaign;
use App\Models\RewardWallet;
use App\Services\LoyaltySettings;
use App\Services\Memberships;
use App\Services\RewardLedger;
use App\Support\Present;
use App\Support\PresentLoyalty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoyaltyController extends Controller
{
    // ---------------------------------------------------------------- wallets

    public function wallets(): JsonResponse
    {
        $wallets = RewardWallet::with('customer')->orderByDesc('balance')->limit(1000)->get();

        return response()->json(PresentLoyalty::wallets($wallets));
    }

    public function ledger(Customer $customer): JsonResponse
    {
        return response()->json(PresentLoyalty::entries($customer->id));
    }

    public function adjust(Request $request, Customer $customer, RewardLedger $rewards): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        if ($customer->is_walk_in) {
            throw ValidationException::withMessages(['points' => 'The walk-in customer has no wallet.']);
        }
        DB::transaction(function () use ($request, $customer, $rewards, $data) {
            $points = (int) $data['points'];
            $points > 0
                ? $rewards->credit($customer->id, 'adjust', $points, [], trim($data['reason']), $request->user()->id)
                : $rewards->debit($customer->id, 'adjust', -$points, [], trim($data['reason']), $request->user()->id);
        });
        AuditLog::record($request->user()->id, 'loyalty.adjusted', 'customer', $customer->id, ['points' => (int) $data['points'], 'reason' => $data['reason']], $request);

        return response()->json(['balance' => $rewards->balance($customer->id)], 201);
    }

    // ---------------------------------------------------------------- campaigns

    public function campaigns(Request $request): JsonResponse
    {
        $q = RewardCampaign::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->orderByDesc('starts_at');
        if (filter_var($request->query('all'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($q->get()->map(fn ($c) => PresentLoyalty::campaign($c))->values());
        }
        $page = $q->paginate((int) $request->query('perPage', 25), ['*'], 'page', (int) $request->query('page', 1));

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($c) => PresentLoyalty::campaign($c))->all()));
    }

    public function campaign(RewardCampaign $campaign): JsonResponse
    {
        return response()->json(PresentLoyalty::campaign($campaign));
    }

    public function saveCampaign(Request $request, ?RewardCampaign $campaign = null): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'startsAt' => ['required', 'date_format:Y-m-d'],
            'endsAt' => ['required', 'date_format:Y-m-d', 'after_or_equal:startsAt'],
            'isActive' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $attrs = ['name' => trim($d['name']), 'multiplier' => $d['multiplier'], 'starts_at' => $d['startsAt'], 'ends_at' => $d['endsAt'],
            'is_active' => $d['isActive'] ?? true, 'description' => $d['description'] ?? null];
        $campaign = $campaign?->exists ? tap($campaign)->update($attrs) : RewardCampaign::create($attrs);
        AuditLog::record($request->user()->id, 'loyalty.campaign_saved', 'reward_campaign', $campaign->id, $attrs, $request);

        return response()->json(PresentLoyalty::campaign($campaign->fresh()), $campaign->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteCampaign(Request $request, RewardCampaign $campaign): JsonResponse
    {
        // Past points keep their ledger rows; a campaign only changes future earning.
        $campaign->delete();
        AuditLog::record($request->user()->id, 'loyalty.campaign_deleted', 'reward_campaign', $campaign->id, ['name' => $campaign->name], $request);

        return response()->json(['message' => 'Campaign deleted.']);
    }

    // ---------------------------------------------------------------- membership plans

    public function plans(): JsonResponse
    {
        return response()->json(MembershipPlan::orderBy('sort_order')->orderBy('min_annual_spend')->get()->map(fn ($p) => PresentLoyalty::plan($p))->values());
    }

    public function savePlan(Request $request, MembershipPlan $plan): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'minAnnualSpend' => ['required', 'numeric', 'min:0'],
            'discountPct' => ['required', 'numeric', 'min:0', 'max:50'],
            'pointsMultiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'fee' => ['required', 'numeric', 'min:0'],
            'durationMonths' => ['required', 'integer', 'min:1', 'max:60'],
            'benefits' => ['nullable', 'array', 'max:20'],
            'benefits.*' => ['string', 'max:120'],
            'isActive' => ['required', 'boolean'],
        ]);
        $attrs = [
            'name' => trim($d['name']), 'min_annual_spend' => $d['minAnnualSpend'], 'discount_pct' => $d['discountPct'],
            'points_multiplier' => $d['pointsMultiplier'], 'fee' => $d['fee'], 'duration_months' => $d['durationMonths'],
            'benefits' => array_values($d['benefits'] ?? []), 'is_active' => $d['isActive'],
        ];
        $plan->update($attrs);
        AuditLog::record($request->user()->id, 'loyalty.plan_saved', 'membership_plan', $plan->id, $attrs, $request);

        return response()->json(PresentLoyalty::plan($plan->fresh()));
    }

    public function deletePlan(Request $request, MembershipPlan $plan): JsonResponse
    {
        if (CustomerMembership::where('plan_id', $plan->id)->exists()) {
            throw ValidationException::withMessages(['plan' => 'This plan has or had members. Pause it (inactive) instead of deleting.']);
        }
        $plan->delete();
        AuditLog::record($request->user()->id, 'loyalty.plan_deleted', 'membership_plan', $plan->id, ['code' => $plan->code], $request);

        return response()->json(['message' => 'Plan deleted.']);
    }

    // ---------------------------------------------------------------- members

    public function members(): JsonResponse
    {
        $rows = CustomerMembership::with('customer', 'plan')
            ->whereIn('id', CustomerMembership::selectRaw('MAX(id)')->groupBy('customer_id'))
            ->orderByDesc('id')->limit(2000)->get();

        return response()->json($rows->map(fn ($m) => PresentLoyalty::member($m))->values());
    }

    public function assign(Request $request, Customer $customer): JsonResponse
    {
        $d = $request->validate(['plan' => ['required', 'string', Rule::exists('membership_plans', 'code')->where('is_active', true)], 'reason' => ['required', 'string', 'min:3', 'max:255']]);
        if ($customer->is_walk_in) {
            throw ValidationException::withMessages(['plan' => 'The walk-in customer cannot have a membership.']);
        }
        $plan = MembershipPlan::where('code', $d['plan'])->firstOrFail();
        $m = DB::transaction(function () use ($request, $customer, $plan, $d) {
            CustomerMembership::where(['customer_id' => $customer->id, 'status' => 'active'])->update(['status' => 'cancelled', 'reason' => 'Replaced by '.$plan->code]);

            return CustomerMembership::create([
                'customer_id' => $customer->id, 'plan_id' => $plan->id, 'starts_at' => now()->toDateString(),
                'ends_at' => now()->addMonths($plan->duration_months)->toDateString(), 'status' => 'active', 'source' => 'manual',
                'reason' => $d['reason'], 'assigned_by' => $request->user()->id,
            ]);
        });
        AuditLog::record($request->user()->id, 'membership.assigned', 'customer', $customer->id, ['plan' => $plan->code, 'reason' => $d['reason']], $request);

        return response()->json(PresentLoyalty::member($m->load('customer', 'plan')), 201);
    }

    public function renew(Request $request, CustomerMembership $member, Memberships $memberships): JsonResponse
    {
        $warehouseId = (int) ($request->user()->default_warehouse_id ?? $request->user()->warehouses()->value('warehouses.id') ?? 1);
        $m = $memberships->renew($request->user(), $member, $warehouseId);

        return response()->json(['endsAt' => $m->ends_at]);
    }

    public function changePlan(Request $request, CustomerMembership $member, Memberships $memberships): JsonResponse
    {
        $d = $request->validate(['plan' => ['required', 'string'], 'reason' => ['required', 'string', 'min:3', 'max:255']]);
        $m = $memberships->changePlan($request->user(), $member, $d['plan'], trim($d['reason']));

        return response()->json(PresentLoyalty::member($m->load('customer', 'plan')));
    }

    public function cancel(Request $request, CustomerMembership $member, Memberships $memberships): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $memberships->cancel($request->user(), $member, trim($d['reason']));

        return response()->json(['message' => 'Membership cancelled.']);
    }

    // ---------------------------------------------------------------- settings

    public function settings(LoyaltySettings $settings): JsonResponse
    {
        return response()->json($settings->all());
    }

    public function saveSettings(Request $request, LoyaltySettings $settings): JsonResponse
    {
        $d = $request->validate([
            'active' => ['required', 'boolean'],
            'soldAmountPerPoint' => ['required', 'numeric', 'gt:0'],
            'minimumSoldAmount' => ['required', 'numeric', 'min:0'],
            'expiryDuration' => ['required', 'integer', 'min:0', 'max:3650'],
            'expiryType' => ['required', 'in:Day,Month,Year'],
            'pointValue' => ['required', 'numeric', 'gt:0'],
            'minRedeemPoints' => ['required', 'integer', 'min:0'],
            'minRedeemSaleAmount' => ['nullable', 'numeric', 'min:0'],
            'maxRedeemPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'excludeDiscountedItems' => ['required', 'boolean'],
            'membershipDiscountMode' => ['nullable', 'in:off,larger,stack'],
        ]);
        $saved = $settings->save($d + ['minRedeemSaleAmount' => 0, 'membershipDiscountMode' => 'off']);
        AuditLog::record($request->user()->id, 'settings.reward_points', 'settings', null, $saved, $request);

        return response()->json($saved);
    }
}
