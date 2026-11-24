<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\MoneyTransfer;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts, money transfers, expenses and income.
 *
 * - An account's balance is always derived: opening balance + Σ in − Σ out of
 *   its account_transactions. Nothing stores a running balance.
 * - Money never leaves an account it is not in: transfers and expenses are
 *   refused when the source would go negative; voids that take money back out
 *   (a transfer's destination, an income) are refused the same way.
 * - Documents are voided with a reason and reversing transactions, never
 *   deleted or edited.
 */
class Finance
{
    public const KINDS = ['expenses' => 'expense', 'incomes' => 'income'];

    public function __construct(private DocumentNumbers $numbers) {}

    /** @return Collection<int, int> account id => balance in paisa (up to $asOf when given) */
    public static function balances(?string $asOf = null, ?array $ids = null): Collection
    {
        $moves = DB::table('account_transactions')
            ->when($asOf, fn ($q) => $q->where('txn_date', '<=', $asOf))
            ->when($ids, fn ($q) => $q->whereIn('account_id', $ids))
            ->groupBy('account_id')
            ->selectRaw("account_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net")
            ->pluck('net', 'account_id');

        return Account::query()->when($ids, fn ($q) => $q->whereIn('id', $ids))->get(['id', 'opening_balance'])
            ->mapWithKeys(fn ($a) => [$a->id => SaleService::paisa($a->opening_balance) + SaleService::paisa($moves[$a->id] ?? 0)]);
    }

    public static function balance(int $accountId): int
    {
        return (int) self::balances(null, [$accountId])->get($accountId, 0);
    }

    // ------------------------------------------------------------ accounts

    public function saveAccount(User $user, array $data, ?Account $account = null): Account
    {
        $account ??= new Account;
        if ($account->exists && array_key_exists('openingBalance', $data)
            && SaleService::paisa($data['openingBalance']) !== SaleService::paisa($account->opening_balance)
            && AccountTransaction::where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['openingBalance' => 'The opening balance cannot change once the account has transactions.']);
        }
        $account->fill([
            'code' => strtoupper(trim($data['code'])), 'name' => trim($data['name']), 'type' => $data['type'],
            'account_no' => ($data['accountNo'] ?? '') !== '' ? $data['accountNo'] : null,
        ] + (array_key_exists('openingBalance', $data) ? ['opening_balance' => $data['openingBalance'] ?? 0] : []))->save();
        AuditLog::record($user->id, $account->wasRecentlyCreated ? 'account.created' : 'account.updated', 'account', $account->id, ['code' => $account->code]);

        return $account;
    }

    public function setActive(User $user, Account $account, bool $active): void
    {
        if (! $active) {
            if ($account->is_default) {
                throw ValidationException::withMessages(['account' => 'Make another account the default first.']);
            }
            if (self::balance($account->id) !== 0) {
                throw ValidationException::withMessages(['balance' => 'Move the remaining balance out before deactivating.']);
            }
            $used = PaymentMethod::where(['account_id' => $account->id, 'is_active' => true])->pluck('name');
            if ($used->isNotEmpty()) {
                throw ValidationException::withMessages(['account' => 'Payment methods still post to this account: '.$used->join(', ').'.']);
            }
        }
        $account->update(['is_active' => $active]);
        AuditLog::record($user->id, $active ? 'account.activated' : 'account.deactivated', 'account', $account->id, null);
    }

    public function setDefault(User $user, Account $account): void
    {
        if (! $account->is_active) {
            throw ValidationException::withMessages(['account' => 'Only an active account can be the default.']);
        }
        DB::transaction(function () use ($account) {
            Account::where('id', '!=', $account->id)->update(['is_default' => false]);
            $account->update(['is_default' => true]);
        });
        AuditLog::record($user->id, 'account.default', 'account', $account->id, null);
    }

    /** StatementLine[] with an opening line, oldest first. */
    public function statement(Account $account, string $from, string $to): array
    {
        $opening = (int) self::balances(Carbon::parse($from)->subDay()->toDateString(), [$account->id])->get($account->id, 0);
        $rows = AccountTransaction::where('account_id', $account->id)->whereBetween('txn_date', [$from, $to])
            ->orderBy('txn_date')->orderBy('id')->get();
        $balance = $opening;
        $lines = [['date' => $from, 'description' => 'Opening balance', 'ref' => '', 'inAmount' => 0, 'outAmount' => 0, 'balance' => $opening / 100]];
        foreach ($rows as $r) {
            $amount = SaleService::paisa($r->amount);
            $balance += $r->direction === 'in' ? $amount : -$amount;
            $lines[] = [
                'date' => substr((string) $r->txn_date, 0, 10), 'description' => $this->describe($r), 'ref' => (string) $r->note,
                'inAmount' => $r->direction === 'in' ? $amount / 100 : 0, 'outAmount' => $r->direction === 'out' ? $amount / 100 : 0,
                'balance' => $balance / 100,
            ];
        }

        return $lines;
    }

    // ------------------------------------------------------------ transfers

    public function transfer(User $user, array $data): MoneyTransfer
    {
        if ((int) $data['fromAccountId'] === (int) $data['toAccountId']) {
            throw ValidationException::withMessages(['toAccountId' => 'Choose two different accounts.']);
        }
        if ($data['date'] > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'The date cannot be in the future.']);
        }

        return DB::transaction(function () use ($user, $data) {
            [$from, $to] = [$this->activeAccount($data['fromAccountId'], 'fromAccountId'), $this->activeAccount($data['toAccountId'], 'toAccountId')];
            $paisa = SaleService::paisa($data['amount']);
            $this->mustCover($from, $paisa, 'amount');
            $t = MoneyTransfer::create([
                'number' => $this->numbers->next('money_transfer', $this->warehouseOf($user)),
                'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => SaleService::money($paisa),
                'transfer_date' => $data['date'], 'note' => ($data['note'] ?? '') ?: null, 'status' => 'posted', 'created_by' => $user->id,
            ]);
            $this->txn($from->id, 'out', $paisa, 'money_transfer', $t->id, $t->number, $data['date']);
            $this->txn($to->id, 'in', $paisa, 'money_transfer', $t->id, $t->number, $data['date']);
            AuditLog::record($user->id, 'money_transfer.posted', 'money_transfer', $t->id, ['amount' => $t->amount]);

            return $t;
        });
    }

    public function voidTransfer(User $user, MoneyTransfer $t, string $reason): MoneyTransfer
    {
        return DB::transaction(function () use ($user, $t, $reason) {
            $t = MoneyTransfer::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'posted') {
                throw ValidationException::withMessages(['transfer' => 'This transfer is already void.']);
            }
            $paisa = SaleService::paisa($t->amount);
            $this->mustCover(Account::lockForUpdate()->findOrFail($t->to_account_id), $paisa, 'transfer');
            $this->txn($t->to_account_id, 'out', $paisa, 'money_transfer_void', $t->id, 'Void '.$t->number);
            $this->txn($t->from_account_id, 'in', $paisa, 'money_transfer_void', $t->id, 'Void '.$t->number);
            $t->update(['status' => 'void', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::record($user->id, 'money_transfer.voided', 'money_transfer', $t->id, ['reason' => $reason]);

            return $t;
        });
    }

    // ------------------------------------------------------------ expenses & income

    public function postEntry(User $user, string $kind, array $data): FinanceEntry
    {
        if ($data['date'] > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'The date cannot be in the future.']);
        }
        $category = FinanceCategory::where(['kind' => $kind, 'is_active' => true])->find($data['categoryId']);
        if (! $category) {
            throw ValidationException::withMessages(['categoryId' => 'Choose an active '.$kind.' category.']);
        }

        return DB::transaction(function () use ($user, $kind, $data, $category) {
            $account = $this->activeAccount($data['accountId'], 'accountId');
            $paisa = SaleService::paisa($data['amount']);
            if ($kind === 'expense') {
                $this->mustCover($account, $paisa, 'amount');
            }
            $warehouse = $this->warehouseOf($user);
            $e = FinanceEntry::create([
                'kind' => $kind, 'number' => $this->numbers->next($kind, $warehouse), 'warehouse_id' => $warehouse->id,
                'category_id' => $category->id, 'account_id' => $account->id, 'amount' => SaleService::money($paisa),
                'entry_date' => $data['date'], 'note' => ($data['note'] ?? '') ?: null, 'status' => 'posted', 'created_by' => $user->id,
            ]);
            $this->txn($account->id, $kind === 'expense' ? 'out' : 'in', $paisa, $kind, $e->id, $e->number, $data['date']);
            AuditLog::record($user->id, "$kind.posted", $kind, $e->id, ['amount' => $e->amount]);

            return $e;
        });
    }

    public function voidEntry(User $user, FinanceEntry $e, string $reason): FinanceEntry
    {
        return DB::transaction(function () use ($user, $e, $reason) {
            $e = FinanceEntry::whereKey($e->id)->lockForUpdate()->firstOrFail();
            if ($e->status !== 'posted') {
                throw ValidationException::withMessages(['entry' => 'This entry is already void.']);
            }
            $paisa = SaleService::paisa($e->amount);
            if ($e->kind === 'income') {
                $this->mustCover(Account::lockForUpdate()->findOrFail($e->account_id), $paisa, 'entry');
            }
            $this->txn($e->account_id, $e->kind === 'expense' ? 'in' : 'out', $paisa, $e->kind.'_void', $e->id, 'Void '.$e->number);
            $e->update(['status' => 'void', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::record($user->id, "{$e->kind}.voided", $e->kind, $e->id, ['reason' => $reason]);

            return $e;
        });
    }

    // ------------------------------------------------------------ balance sheet

    /** BalanceSheet as of a date; profit & loss for the month to that date. */
    public function balanceSheet(string $asOf): array
    {
        $accounts = Account::all()->keyBy('id');
        $balances = self::balances($asOf);
        $byType = fn (string $type) => $accounts->where('type', $type)->keys()->sum(fn ($id) => $balances[$id] ?? 0) / 100;

        // Inventory at cost from the stock history up to the date: every movement carries its unit cost.
        $stock = (float) DB::table('stock_movements as m')->join('batches as b', 'b.id', '=', 'm.batch_id')
            ->whereDate('m.created_at', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('b.expiry_date')->orWhere('b.expiry_date', '>=', $asOf))
            ->selectRaw('COALESCE(SUM(m.qty_base * m.unit_cost), 0) AS v')->value('v');

        $parties = fn (string $type, string $sign) => DB::query()->fromSub(
            DB::table('party_ledger_entries')->where('party_type', $type)->where('entry_date', '<=', $asOf)
                ->groupBy('party_id')->selectRaw("party_id, SUM($sign) AS bal"), 'p');
        $receivable = (float) $parties('customer', 'debit - credit')->where('bal', '>', 0)->sum('bal');
        $customerCredit = (float) -$parties('customer', 'debit - credit')->where('bal', '<', 0)->sum('bal');
        $payable = (float) $parties('supplier', 'credit - debit')->where('bal', '>', 0)->sum('bal');
        $supplierAdvance = (float) -$parties('supplier', 'credit - debit')->where('bal', '<', 0)->sum('bal');

        $assets = [
            ['label' => 'Cash in hand', 'amount' => $byType('cash')],
            ['label' => 'Bank accounts', 'amount' => $byType('bank')],
            ['label' => 'Mobile wallets', 'amount' => $byType('mobile_wallet'), 'note' => 'bKash, Nagad, Rocket'],
            ['label' => 'Inventory at batch cost', 'amount' => round($stock, 2), 'note' => 'Stock history to this date, expired batches excluded'],
            ['label' => 'Customer receivables', 'amount' => round($receivable, 2), 'note' => 'Customer ledger balances'],
        ];
        if ($supplierAdvance > 0) {
            $assets[] = ['label' => 'Supplier advances and credits', 'amount' => round($supplierAdvance, 2), 'note' => 'Returns and payments beyond invoices'];
        }
        $liabilities = [['label' => 'Supplier payables', 'amount' => round($payable, 2), 'note' => 'Supplier ledger balances']];
        if ($customerCredit > 0) {
            $liabilities[] = ['label' => 'Customer credit', 'amount' => round($customerCredit, 2), 'note' => 'Store credit owed to customers'];
        }
        $capital = round($accounts->sum(fn ($a) => (float) $a->opening_balance), 2);
        $assetsTotal = round(collect($assets)->sum('amount'), 2);
        $liabilitiesTotal = round(collect($liabilities)->sum('amount'), 2);

        return [
            'asOf' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities,
            'equity' => [
                ['label' => 'Opening capital', 'amount' => $capital, 'note' => 'Opening balances of the accounts'],
                ['label' => 'Retained earnings (balancing)', 'amount' => round($assetsTotal - $liabilitiesTotal - $capital, 2)],
            ],
            'pnl' => $this->pnl(Carbon::parse($asOf)->startOfMonth()->toDateString(), $asOf),
        ];
    }

    private function pnl(string $from, string $to): array
    {
        $range = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        $sales = DB::table('sales')->where('status', 'completed')->whereBetween('sale_date', $range);
        $returns = DB::table('sale_returns')->whereBetween('created_at', $range);
        // Restocked pieces go back into inventory at the cost they were sold at.
        $restockedCost = (float) DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.sale_return_id')
            ->join('sale_item_batches as sib', 'sib.id', '=', 'ri.sale_item_batch_id')
            ->whereBetween('r.created_at', $range)->where('ri.restock', true)
            ->selectRaw('COALESCE(SUM(ri.qty_base * sib.unit_cost), 0) AS v')->value('v');
        $entries = fn (string $kind) => (float) DB::table('finance_entries')->where(['kind' => $kind, 'status' => 'posted'])
            ->whereBetween('entry_date', [$from, $to])->sum('amount');

        return [
            'from' => $from, 'to' => $to,
            'sales' => round((float) (clone $sales)->sum('grand_total'), 2),
            'returns' => round((float) $returns->sum('total'), 2),
            'cogs' => round((float) (clone $sales)->sum('cost_total') - $restockedCost, 2),
            'expenses' => round($entries('expense'), 2),
            'income' => round($entries('income'), 2),
        ];
    }

    // ------------------------------------------------------------ helpers

    private function activeAccount(int $id, string $field): Account
    {
        $a = Account::where('is_active', true)->lockForUpdate()->find($id);
        if (! $a) {
            throw ValidationException::withMessages([$field => 'Choose an active account.']);
        }

        return $a;
    }

    private function mustCover(Account $a, int $paisa, string $field): void
    {
        $have = self::balance($a->id);
        if ($paisa <= 0 || $paisa > $have) {
            throw ValidationException::withMessages([$field => "{$a->name} holds ".SaleService::money(max(0, $have)).'; this needs '.SaleService::money($paisa).'.']);
        }
    }

    private function txn(int $accountId, string $direction, int $paisa, string $source, int $sourceId, string $note, ?string $date = null): void
    {
        AccountTransaction::create([
            'account_id' => $accountId, 'txn_date' => $date ?? now()->toDateString(), 'direction' => $direction,
            'amount' => SaleService::money($paisa), 'source_type' => $source, 'source_id' => $sourceId, 'note' => $note,
        ]);
    }

    private function warehouseOf(User $user): Warehouse
    {
        return Warehouse::find($user->default_warehouse_id) ?? Warehouse::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function describe(AccountTransaction $r): string
    {
        return match ($r->source_type) {
            'payment' => $r->direction === 'in' ? 'Payment received' : 'Payment made',
            'payment_void' => 'Payment voided',
            'money_transfer' => $r->direction === 'in' ? 'Transfer in' : 'Transfer out',
            'money_transfer_void' => 'Transfer voided',
            'expense' => 'Expense',
            'expense_void' => 'Expense voided',
            'income' => 'Income',
            'income_void' => 'Income voided',
            default => ucfirst(str_replace('_', ' ', $r->source_type)),
        };
    }
}
