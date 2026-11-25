<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OnlineOrder;
use App\Services\CourierBookings;
use App\Services\Couriers\Pathao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/** "Send to courier" on an online order (Steadfast / Pathao). Needs `orders.fulfil`. */
class CourierController extends Controller
{
    public function __construct(private CourierBookings $couriers) {}

    public function available(): JsonResponse
    {
        return response()->json($this->couriers->available());
    }

    /** Pathao's places for the booking form: cities, or the zones of a city, or the areas of a zone. */
    public function pathaoPlaces(Request $request, Pathao $pathao): JsonResponse
    {
        $d = $request->validate(['city_id' => ['nullable', 'integer'], 'zone_id' => ['nullable', 'integer']]);
        try {
            return response()->json(match (true) {
                ! empty($d['zone_id']) => $pathao->areas((int) $d['zone_id']),
                ! empty($d['city_id']) => $pathao->zones((int) $d['city_id']),
                default => $pathao->cities(),
            });
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['courier' => $e->getMessage()]);
        }
    }

    public function book(Request $request, OnlineOrder $order): JsonResponse
    {
        $d = $request->validate([
            'courier' => ['required', 'in:steadfast,pathao'], 'city_id' => ['nullable', 'integer'], 'zone_id' => ['nullable', 'integer'],
            'area_id' => ['nullable', 'integer'], 'weight' => ['nullable', 'numeric', 'min:0.1', 'max:50'], 'note' => ['nullable', 'string', 'max:250'],
        ]);
        $order = $this->couriers->book($request->user(), $order, $d['courier'], $d);
        AuditLog::record($request->user()->id, 'order.courier', 'online_order', $order->id, ['courier' => $order->courier, 'consignment' => $order->courier_consignment_id], $request);

        return $this->show($order);
    }

    public function refresh(OnlineOrder $order): JsonResponse
    {
        return $this->show($this->couriers->refresh($order));
    }

    private function show(OnlineOrder $order): JsonResponse
    {
        $order = OnlineOrder::with('lines.unit', 'history.user', 'customer', 'rider', 'zone')->findOrFail($order->id);

        return response()->json(OnlineOrderController::presentMany(collect([$order]))[0]);
    }
}
