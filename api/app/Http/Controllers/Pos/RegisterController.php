<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Services\RegisterService;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    public function __construct(private RegisterService $registers) {}

    /** Registers the user can open, with their current state. */
    public function registers(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = CashRegister::where('is_active', true)
            ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id')))
            ->orderBy('warehouse_id')->orderBy('name')->get();
        $open = CashRegisterSession::where('status', 'open')->whereIn('cash_register_id', $rows->pluck('id'))->pluck('id', 'cash_register_id');

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'warehouseId' => $r->warehouse_id, 'openSessionId' => $open[$r->id] ?? null,
        ])->values());
    }

    /** Recent sessions (open first), newest first. */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = CashRegisterSession::query()->with('register', 'opener')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('register', fn ($r) => $r->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id'))))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('opened_at')
            ->limit(60)->get();

        return response()->json($rows->map(fn ($s) => Present::registerSession($s))->values());
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['register_id' => ['required', 'integer'], 'opening_float' => ['required', 'numeric', 'min:0', 'max:10000000']]);
        $session = $this->registers->open($request->user(), (int) $data['register_id'], (float) $data['opening_float']);

        return response()->json(Present::registerSession($session), 201);
    }

    public function movement(Request $request, CashRegisterSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);
        $data = $request->validate([
            'type' => ['required', 'in:cash_in,cash_out'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $this->registers->movement($request->user(), $session, $data['type'], (float) $data['amount'], trim($data['reason']));

        return response()->json(Present::registerSession($session->fresh()), 201);
    }

    public function close(Request $request, CashRegisterSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0', 'max:10000000'], 'note' => ['nullable', 'string', 'max:500']]);
        $session = $this->registers->close($request->user(), $session, (float) $data['counted_cash'], $data['note'] ?? null);

        return response()->json(Present::registerSession($session));
    }

    /** The cashier who opened the session, or a manager (sales.void) of that warehouse. */
    private function authorizeSession(Request $request, CashRegisterSession $session): void
    {
        $user = $request->user();
        $warehouseId = (int) $session->register()->value('warehouse_id');
        abort_unless($user->canAccessWarehouse($warehouseId), 404);
        abort_unless($session->opened_by === $user->id || $user->can('sales.void'), 403, 'Only the cashier who opened this register can use it.');
    }
}
