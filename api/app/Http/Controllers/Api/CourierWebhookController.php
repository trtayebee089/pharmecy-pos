<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Services\CourierBookings;
use App\Services\Integrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parcel status from the couriers. Each call must carry the secret set in
 * Administration › Integrations › Couriers (Steadfast: `Authorization: Bearer
 * <token>`; Pathao: `X-PATHAO-Signature`), otherwise it is refused.
 */
class CourierWebhookController extends Controller
{
    /** The reply Pathao expects when the webhook URL is registered in its merchant panel. */
    public const PATHAO_INTEGRATION_HEADER = 'X-Pathao-Merchant-Webhook-Integration-Secret';

    public const PATHAO_INTEGRATION_VALUE = 'f3992ecc-59da-4cbe-a049-a13da2018d51';

    public function __construct(private CourierBookings $couriers, private Integrations $integrations) {}

    public function steadfast(Request $request): JsonResponse
    {
        $secret = (string) $this->integrations->config('steadfast')['webhookToken'];
        if ($secret === '' || ! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        $order = OnlineOrder::where('courier', 'steadfast')
            ->where(fn ($q) => $q->where('courier_consignment_id', (string) $request->input('consignment_id'))->orWhere('number', (string) $request->input('invoice')))
            ->first();
        if ($order && $request->input('status')) {
            $fee = is_numeric($request->input('delivery_charge')) ? (float) $request->input('delivery_charge') : null;
            $this->couriers->apply($order, (string) $request->input('status'), $fee);
        }

        return response()->json(['status' => 'success', 'message' => 'Webhook received']);
    }

    public function pathao(Request $request): JsonResponse
    {
        $secret = (string) $this->integrations->config('pathao')['webhookSecret'];
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-PATHAO-Signature'))) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $cid = (string) ($request->input('consignment_id') ?? '');
        $status = (string) ($request->input('order_status') ?? $request->input('event') ?? '');
        $order = $cid !== '' ? OnlineOrder::where(['courier' => 'pathao', 'courier_consignment_id' => $cid])->first() : null;
        if ($order && $status !== '' && $status !== 'webhook_integration') {
            $fee = is_numeric($request->input('delivery_fee')) ? (float) $request->input('delivery_fee') : null;
            $this->couriers->apply($order, $status, $fee);
        }

        return response()->json(['message' => 'OK'], 202, [self::PATHAO_INTEGRATION_HEADER => self::PATHAO_INTEGRATION_VALUE]);
    }
}
