<?php

namespace App\Services;

use App\Models\OnlineOrder;
use App\Models\OnlinePayment;
use App\Services\Payments\Bkash;
use App\Services\Payments\SslCommerz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Online payment of website/app orders (owner decision 26 Nov 2026: SSLCommerz
 * and bKash). The order is placed first and waits (`payment_status = pending`);
 * staff cannot confirm it until it is paid. A payment is marked paid only after
 * the gateway confirms it server to server, for the exact amount due. Repeated
 * callbacks (browser + IPN) are harmless. A paid order that is later cancelled
 * shows `refund_due` (refund in the gateway's merchant panel).
 */
class OnlinePayments
{
    public function __construct(private Integrations $integrations, private OnlineOrders $orders) {}

    /** Starts a payment and returns the gateway page to send the customer to. */
    public function start(OnlineOrder $order, string $gateway): string
    {
        if (! in_array($gateway, OnlineOrders::ONLINE_METHODS, true) || ! $this->integrations->enabled($gateway)) {
            throw ValidationException::withMessages(['payment_method' => 'This payment method is not available.']);
        }
        if ($order->status === 'cancelled' || in_array($order->payment_status, ['paid', 'refund_due'], true)) {
            throw ValidationException::withMessages(['payment_method' => $order->payment_status === 'paid' ? 'This order is already paid.' : 'This order is closed.']);
        }
        $amount = OnlineOrders::payable($order);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['payment_method' => 'Nothing to pay on this order.']);
        }
        $order->update(['payment_method' => $gateway, 'payment_status' => 'pending']);
        $p = OnlinePayment::create([
            'online_order_id' => $order->id, 'gateway' => $gateway, 'amount' => $amount, 'status' => 'initiated',
            'reference' => 'RP'.$order->id.'-'.strtoupper(Str::random(8)),
        ]);
        $api = url('/api/v1/payments');
        try {
            $r = $gateway === 'sslcommerz'
                ? app(SslCommerz::class)->init($p, $order, [
                    'success' => "$api/sslcommerz/success", 'fail' => "$api/sslcommerz/fail", 'cancel' => "$api/sslcommerz/cancel", 'ipn' => "$api/sslcommerz/ipn",
                ])
                : app(Bkash::class)->create($p, $order, "$api/bkash/callback");
        } catch (Throwable $e) {
            $p->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);
            Log::warning("Payment start failed for {$order->number}: ".$e->getMessage());
            throw ValidationException::withMessages(['payment_method' => 'The payment could not be started. Try again, or choose cash on delivery.']);
        }
        $p->update(['gateway_id' => $r['id'], 'payload' => $r['payload']]);

        return $r['url'];
    }

    /** SSLCommerz success page or IPN: trusted only after the validation API agrees. */
    public function completeSslcommerz(?string $tranId, ?string $valId): ?OnlinePayment
    {
        $p = $tranId ? OnlinePayment::where(['reference' => $tranId, 'gateway' => 'sslcommerz'])->first() : null;
        if (! $p || ! $valId) {
            return $p;
        }
        $v = app(SslCommerz::class)->validate($valId);
        $ok = in_array($v['status'] ?? '', ['VALID', 'VALIDATED'], true) && ($v['tran_id'] ?? null) === $p->reference
            && ($v['currency_type'] ?? $v['currency'] ?? 'BDT') === 'BDT' && $this->sameAmount($v['currency_amount'] ?? $v['amount'] ?? null, $p->amount);

        return $ok ? $this->markPaid($p, $v['bank_tran_id'] ?? $valId, $v) : $this->fail($p, 'SSLCommerz did not validate the payment ('.($v['status'] ?? 'no answer').').', $v);
    }

    public function failSslcommerz(?string $tranId, string $outcome): ?OnlinePayment
    {
        $p = $tranId ? OnlinePayment::where(['reference' => $tranId, 'gateway' => 'sslcommerz'])->first() : null;

        return $p ? $this->fail($p, $outcome === 'cancel' ? 'Cancelled by the customer.' : 'The payment failed at SSLCommerz.', [], $outcome === 'cancel' ? 'cancelled' : 'failed') : null;
    }

    /** bKash callback: execute the payment server side (or read its status if already executed). */
    public function completeBkash(?string $paymentId, ?string $status): ?OnlinePayment
    {
        $p = $paymentId ? OnlinePayment::where(['gateway_id' => $paymentId, 'gateway' => 'bkash'])->first() : null;
        if (! $p) {
            return null;
        }
        if ($p->status === 'paid') {
            return $p;
        }
        if ($status !== 'success') {
            return $this->fail($p, $status === 'cancel' ? 'Cancelled by the customer.' : 'The payment failed at bKash.', [], $status === 'cancel' ? 'cancelled' : 'failed');
        }
        $bkash = app(Bkash::class);
        $r = $bkash->execute($paymentId);
        if (($r['transactionStatus'] ?? null) !== 'Completed') {
            $r = $bkash->status($paymentId); // e.g. executed already by an earlier callback
        }
        $ok = ($r['transactionStatus'] ?? null) === 'Completed' && $this->sameAmount($r['amount'] ?? null, $p->amount)
            && ($r['merchantInvoiceNumber'] ?? $p->reference) === $p->reference;

        return $ok ? $this->markPaid($p, $r['trxID'] ?? null, $r) : $this->fail($p, 'bKash did not complete the payment ('.($r['statusMessage'] ?? $r['transactionStatus'] ?? 'no answer').').', $r);
    }

    /** The customer gives up paying online: the order becomes cash on delivery. */
    public function switchToCod(OnlineOrder $order): OnlineOrder
    {
        return DB::transaction(function () use ($order) {
            $order = OnlineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status !== 'pending' || $order->status === 'cancelled') {
                throw ValidationException::withMessages(['payment_method' => $order->payment_status === 'paid' ? 'This order is already paid.' : 'This order cannot be changed.']);
            }
            if (! ($this->integrations->public('storefront')['codEnabled'] ?? true)) {
                throw ValidationException::withMessages(['payment_method' => 'Cash on delivery is not available.']);
            }
            $order->update(['payment_method' => 'cod', 'payment_status' => 'cod']);
            $this->orders->history($order, $order->status, 'Customer switched to cash on delivery', null);

            return $order;
        });
    }

    private function markPaid(OnlinePayment $p, ?string $txn, array $payload): OnlinePayment
    {
        return DB::transaction(function () use ($p, $txn, $payload) {
            $p = OnlinePayment::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'paid') {
                return $p; // browser return and IPN both arrive
            }
            $order = OnlineOrder::whereKey($p->online_order_id)->lockForUpdate()->firstOrFail();
            $p->update(['status' => 'paid', 'transaction_id' => $txn, 'paid_at' => now(), 'payload' => $this->trim($payload), 'failure_reason' => null]);
            // Paid after the order was cancelled (or a second payment): the money is owed back.
            $refund = $order->status === 'cancelled' || in_array($order->payment_status, ['paid', 'refund_due'], true);
            $order->update($refund ? ['payment_status' => 'refund_due'] : ['payment_status' => 'paid', 'payment_method' => $p->gateway, 'paid_online' => $p->amount]);
            $label = $p->gateway === 'bkash' ? 'bKash' : 'SSLCommerz';
            $this->orders->history($order, $order->status, ($refund ? 'Payment received after cancellation, refund due: ' : 'Paid online: ')."৳{$p->amount} by {$label}".($txn ? " ({$txn})" : ''), null);

            return $p;
        });
    }

    private function fail(OnlinePayment $p, string $reason, array $payload = [], string $status = 'failed'): OnlinePayment
    {
        if ($p->status === 'paid') {
            return $p; // a late failure message never undoes a confirmed payment
        }
        $p->update(['status' => $status, 'failure_reason' => mb_substr($reason, 0, 255), 'payload' => $payload ? $this->trim($payload) : $p->payload]);

        return $p;
    }

    private function sameAmount(mixed $reported, mixed $expected): bool
    {
        return is_numeric($reported) && SaleService::paisa((float) $reported) === SaleService::paisa((float) $expected);
    }

    /** Keeps what support needs, never card data. */
    private function trim(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'status', 'tran_id', 'val_id', 'amount', 'currency_amount', 'currency', 'currency_type', 'bank_tran_id', 'card_type', 'card_brand', 'risk_level', 'risk_title',
            'paymentID', 'trxID', 'transactionStatus', 'merchantInvoiceNumber', 'statusCode', 'statusMessage', 'customerMsisdn',
        ]));
    }
}
