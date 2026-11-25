<?php

namespace App\Services;

use App\Models\OnlineOrder;

/**
 * SMS to the customer when an online order is confirmed, on its way, delivered
 * or cancelled. Only when SMS and "order updates" are switched on in
 * Administration › Integrations › SMS. Sent after the response; a failure is
 * logged in sms_messages and never blocks the order.
 */
class OrderNotifier
{
    public const STATUSES = ['confirmed', 'in_transit', 'delivered', 'cancelled'];

    public function __construct(private Integrations $integrations, private SmsGateway $sms) {}

    public function wants(string $status): bool
    {
        return in_array($status, self::STATUSES, true) && $this->sms->enabled() && $this->integrations->public('sms')['sendOrderUpdates'];
    }

    public function statusChanged(OnlineOrder $order): void
    {
        if (! $this->wants($order->status) || ! $order->phone) {
            return;
        }
        $shop = $this->integrations->public('storefront');
        $name = $shop['siteName'] ?: 'Red Pharma';
        $track = $this->integrations->websiteUrl().'/track-order?code='.$order->tracking_code;
        $cod = OnlineOrders::codAmount($order);
        $text = match ($order->status) {
            'confirmed' => "{$name}: your order {$order->number} is confirmed.".($cod > 0 ? ' Pay Tk '.number_format($cod, 2).' on delivery.' : '')." Track: {$track}",
            'in_transit' => "{$name}: your order {$order->number} is on the way".($order->courier ? ' with '.(CourierBookings::COURIERS[$order->courier] ?? $order->courier) : '').". Track: {$track}",
            'delivered' => "{$name}: your order {$order->number} has been delivered. Thank you!",
            default => "{$name}: your order {$order->number} was cancelled.".($shop['phone'] ? " Questions? Call {$shop['phone']}." : ''),
        };
        $this->sms->send($order->phone, $text, 'order_update');
    }
}
