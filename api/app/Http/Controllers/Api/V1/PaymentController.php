<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OnlineOrder;
use App\Services\Integrations;
use App\Services\OnlinePayments;
use App\Support\PresentStorefront;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Online payment endpoints. Customers (guests too) act on an order with its
 * random tracking code. The gateways send the browser back here; we check the
 * payment server to server and then redirect to the website's confirmation page
 * with ?payment=paid|failed|cancelled.
 */
class PaymentController extends V1Controller
{
    public function __construct(private OnlinePayments $payments, private Integrations $integrations) {}

    /** Start (or retry) paying an order: { tracking_code, gateway } → { redirect_url }. */
    public function start(Request $request): JsonResponse
    {
        $d = $request->validate(['tracking_code' => ['required', 'string', 'max:20'], 'gateway' => ['required', 'in:sslcommerz,bkash']]);
        $order = $this->order($d['tracking_code']);

        return $this->ok(['redirect_url' => $this->payments->start($order, $d['gateway'])]);
    }

    public function cod(Request $request): JsonResponse
    {
        $d = $request->validate(['tracking_code' => ['required', 'string', 'max:20']]);

        return $this->ok(PresentStorefront::order($this->payments->switchToCod($this->order($d['tracking_code'])), forTracking: true), 'Changed to cash on delivery.');
    }

    /** SSLCommerz sends the browser to success / fail / cancel (POST form). */
    public function sslcommerz(Request $request, string $outcome): RedirectResponse
    {
        $p = $outcome === 'success'
            ? $this->payments->completeSslcommerz($request->input('tran_id'), $request->input('val_id'))
            : $this->payments->failSslcommerz($request->input('tran_id'), $outcome);

        return $this->backToSite($p?->order, $p?->status);
    }

    /** SSLCommerz's server-to-server notice: the payment counts even if the customer closed the browser. */
    public function sslcommerzIpn(Request $request): Response
    {
        $this->payments->completeSslcommerz($request->input('tran_id'), $request->input('val_id'));

        return response('OK');
    }

    /** bKash returns the browser with ?paymentID=…&status=success|failure|cancel. */
    public function bkash(Request $request): RedirectResponse
    {
        $p = $this->payments->completeBkash($request->query('paymentID'), $request->query('status'));

        return $this->backToSite($p?->order, $p?->status);
    }

    private function order(string $code): OnlineOrder
    {
        $order = OnlineOrder::where('tracking_code', strtoupper(trim($code)))->first();
        if (! $order) {
            throw ValidationException::withMessages(['tracking_code' => 'No order with that tracking code.']);
        }

        return $order;
    }

    private function backToSite(?OnlineOrder $order, ?string $status): RedirectResponse
    {
        $site = $this->integrations->websiteUrl();
        if (! $order) {
            return redirect()->away($site.'/track-order?payment=unknown');
        }
        $result = match ($status) {
            'paid' => 'paid', 'cancelled' => 'cancelled', default => 'failed'
        };

        return redirect()->away($site.'/order-confirmation/'.rawurlencode($order->number).'?payment='.$result.'&code='.rawurlencode($order->tracking_code));
    }
}
