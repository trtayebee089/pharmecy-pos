<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\CashRegisterMovement;
use App\Models\CashRegisterSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Till sessions. A cashier opens a register with a float; cash sales, cash
 * refunds and petty cash in/out are recorded against the session; closing
 * compares counted cash with what the session expects.
 *
 * Expected cash = opening float + cash sales − cash refunds + cash in − cash out.
 */
class RegisterService
{
    /** The user's open session in this warehouse, if any (sales and refunds attach to it). */
    public function current(User $user, int $warehouseId): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('status', 'open')
            ->where('opened_by', $user->id)
            ->whereHas('register', fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->latest('opened_at')
            ->first();
    }

    public function open(User $user, int $registerId, float $openingFloat): CashRegisterSession
    {
        $register = CashRegister::where('is_active', true)->find($registerId);
        if (! $register || ! $user->canAccessWarehouse($register->warehouse_id)) {
            throw ValidationException::withMessages(['register_id' => 'Register not found for your warehouses.']);
        }

        return DB::transaction(function () use ($user, $register, $openingFloat) {
            CashRegister::whereKey($register->id)->lockForUpdate()->first();
            if (CashRegisterSession::where(['cash_register_id' => $register->id, 'status' => 'open'])->exists()) {
                throw ValidationException::withMessages(['register_id' => "{$register->name} already has an open session."]);
            }
            if ($this->current($user, $register->warehouse_id)) {
                throw ValidationException::withMessages(['register_id' => 'You already have an open register session in this warehouse.']);
            }

            return CashRegisterSession::create([
                'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opened_at' => now(),
                'opening_float' => round($openingFloat, 2), 'status' => 'open',
            ]);
        });
    }

    public function movement(User $user, CashRegisterSession $session, string $type, float $amount, string $reason): CashRegisterMovement
    {
        $this->assertOpen($session);

        return DB::transaction(function () use ($user, $session, $type, $amount, $reason) {
            if ($type === 'cash_out' && $amount > $this->figures($session)['expectedCash']) {
                throw ValidationException::withMessages(['amount' => 'Cash out is more than the cash expected in the drawer.']);
            }
            $m = CashRegisterMovement::create([
                'session_id' => $session->id, 'type' => $type, 'amount' => round($amount, 2), 'reason' => $reason, 'created_by' => $user->id,
            ]);
            if ($cash = $this->cashAccountId()) {
                AccountTransaction::create([
                    'account_id' => $cash, 'txn_date' => now()->toDateString(), 'direction' => $type === 'cash_in' ? 'in' : 'out',
                    'amount' => $m->amount, 'source_type' => 'register_movement', 'source_id' => $m->id, 'note' => $reason,
                ]);
            }

            return $m;
        });
    }

    public function close(User $user, CashRegisterSession $session, float $counted, ?string $note): CashRegisterSession
    {
        return DB::transaction(function () use ($user, $session, $counted, $note) {
            $session = CashRegisterSession::whereKey($session->id)->lockForUpdate()->first();
            $this->assertOpen($session);
            $expected = $this->figures($session)['expectedCash'];
            $session->update([
                'status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now(),
                'expected_cash' => $expected, 'counted_cash' => round($counted, 2),
                'difference' => round($counted - $expected, 2), 'note' => $note,
            ]);
            AuditLog::record($user->id, 'register.closed', 'cash_register_session', $session->id, ['expected' => $expected, 'counted' => round($counted, 2)]);

            return $session;
        });
    }

    /** @return array{cashSales: float, cashRefunds: float, cashIn: float, cashOut: float, expectedCash: float, nonCash: array} */
    public function figures(CashRegisterSession $session): array
    {
        $payments = DB::table('payments')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.session_id', $session->id)->where('payments.status', 'posted')
            ->groupBy('payment_methods.code', 'payment_methods.name', 'payments.direction')
            ->selectRaw('payment_methods.code AS code, payment_methods.name AS name, payments.direction AS direction, SUM(payments.amount) AS total')
            ->get();
        $sum = fn (string $dir, bool $cash) => (float) $payments->where('direction', $dir)->filter(fn ($p) => ($p->code === 'cash') === $cash)->sum('total');
        $moves = DB::table('cash_register_movements')->where('session_id', $session->id)->groupBy('type')->pluck(DB::raw('SUM(amount)'), 'type');

        $cashSales = round($sum('in', true), 2);
        $cashRefunds = round($sum('out', true), 2);
        $cashIn = round((float) ($moves['cash_in'] ?? 0), 2);
        $cashOut = round((float) ($moves['cash_out'] ?? 0), 2);

        return [
            'cashSales' => $cashSales, 'cashRefunds' => $cashRefunds, 'cashIn' => $cashIn, 'cashOut' => $cashOut,
            'expectedCash' => round((float) $session->opening_float + $cashSales - $cashRefunds + $cashIn - $cashOut, 2),
            'nonCash' => $payments->where('direction', 'in')->where('code', '!=', 'cash')
                ->map(fn ($p) => ['method' => $p->name, 'amount' => round((float) $p->total, 2)])->values()->all(),
        ];
    }

    private function assertOpen(?CashRegisterSession $session): void
    {
        if (! $session || $session->status !== 'open') {
            throw ValidationException::withMessages(['session' => 'This register session is already closed.']);
        }
    }

    private function cashAccountId(): ?int
    {
        return Account::where('type', 'cash')->orderByDesc('is_default')->value('id');
    }
}
