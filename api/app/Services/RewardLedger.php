<?php

namespace App\Services;

use App\Models\RewardLedgerEntry;
use App\Models\RewardWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of reward points. Every change is an append-only
 * reward_ledger row plus an update of the reward_wallets projection under a
 * row lock (call inside the caller's transaction).
 *
 * Credit rows (earn, positive adjust/reverse, opening) carry `remaining`;
 * redemptions, negative adjustments and expiry consume the oldest-expiring
 * credit first (FIFO by expiry), so expiry only removes points never used.
 * A reversal of earned points that were already spent may leave a negative
 * balance; a negative balance cannot redeem until it is earned back.
 */
class RewardLedger
{
    public function __construct(private LoyaltySettings $settings) {}

    public function wallet(int $customerId, bool $lock = true): RewardWallet
    {
        RewardWallet::firstOrCreate(['customer_id' => $customerId]);

        return RewardWallet::where('customer_id', $customerId)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function balance(int $customerId): int
    {
        return (int) RewardWallet::where('customer_id', $customerId)->value('balance');
    }

    /** Adds points (earn, positive adjust, returned redemption). */
    public function credit(int $customerId, string $type, int $points, array $source = [], ?string $note = null, ?int $userId = null): ?RewardLedgerEntry
    {
        if ($points <= 0) {
            return null;
        }
        $wallet = $this->wallet($customerId);
        $entry = RewardLedgerEntry::create([
            'customer_id' => $customerId, 'type' => $type, 'points' => $points, 'remaining' => $points,
            'expires_at' => $this->settings->expiryFrom(), 'source_type' => $source[0] ?? null, 'source_id' => $source[1] ?? null,
            'note' => $note, 'created_by' => $userId, 'created_at' => now(),
        ]);
        $wallet->balance += $points;
        if ($type === 'earn') {
            $wallet->lifetime_earned += $points;
        }
        $wallet->save();

        return $entry;
    }

    /**
     * Removes points (redeem, negative adjust, reversal of earned points).
     * $mustCover: refuse when the balance is too small (redeem, adjust).
     */
    public function debit(int $customerId, string $type, int $points, array $source = [], ?string $note = null, ?int $userId = null, float $value = 0, bool $mustCover = true): ?RewardLedgerEntry
    {
        if ($points <= 0) {
            return null;
        }
        $wallet = $this->wallet($customerId);
        if ($mustCover && $wallet->balance < $points) {
            throw ValidationException::withMessages(['points' => "Only {$wallet->balance} points are available."]);
        }
        $this->consume($customerId, $points);
        $entry = RewardLedgerEntry::create([
            'customer_id' => $customerId, 'type' => $type, 'points' => -$points, 'value' => $value,
            'source_type' => $source[0] ?? null, 'source_id' => $source[1] ?? null,
            'note' => $note, 'created_by' => $userId, 'created_at' => now(),
        ]);
        $wallet->balance -= $points;
        if ($type === 'redeem') {
            $wallet->lifetime_redeemed += $points;
        }
        $wallet->save();

        return $entry;
    }

    /** Expires credit rows whose date has passed (scheduled daily). Returns points expired. */
    public function expire(?string $today = null): int
    {
        $today ??= now()->toDateString();
        $total = 0;
        $customers = RewardLedgerEntry::where('remaining', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<', $today)
            ->distinct()->pluck('customer_id');
        foreach ($customers as $customerId) {
            DB::transaction(function () use ($customerId, $today, &$total) {
                $wallet = $this->wallet($customerId);
                $rows = RewardLedgerEntry::where('customer_id', $customerId)->where('remaining', '>', 0)
                    ->whereNotNull('expires_at')->where('expires_at', '<', $today)->lockForUpdate()->get();
                $points = (int) $rows->sum('remaining');
                // Never expire more than the wallet holds (spent-but-reversed points already lowered it).
                $points = max(0, min($points, $wallet->balance));
                foreach ($rows as $r) {
                    $r->update(['remaining' => 0]);
                }
                if ($points > 0) {
                    RewardLedgerEntry::create([
                        'customer_id' => $customerId, 'type' => 'expire', 'points' => -$points,
                        'note' => 'Points expired', 'created_at' => now(),
                    ]);
                    $wallet->balance -= $points;
                    $wallet->save();
                    $total += $points;
                }
            });
        }

        return $total;
    }

    /** Points expiring within $days (shown on the wallet). */
    public function expiringSoon(int $customerId, int $days = 30): int
    {
        return (int) RewardLedgerEntry::where('customer_id', $customerId)->where('remaining', '>', 0)
            ->whereNotNull('expires_at')->whereBetween('expires_at', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->sum('remaining');
    }

    private function consume(int $customerId, int $points): void
    {
        $left = $points;
        $rows = RewardLedgerEntry::where('customer_id', $customerId)->where('remaining', '>', 0)
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('id')
            ->lockForUpdate()->get();
        foreach ($rows as $r) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $r->remaining);
            $r->update(['remaining' => $r->remaining - $take]);
            $left -= $take;
        }
    }
}
