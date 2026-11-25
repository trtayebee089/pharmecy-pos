<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AdvanceRecovery;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\Holiday;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance and payroll (owner decisions, 25 Nov 2026):
 *
 * - Absence deduction = basic ÷ 30 × absent days (capped at the basic).
 * - Admin settings (`settings` key `hr_payroll`): weekly off days (used for the
 *   working-day count shown on payslips), late rule (every N late days count as
 *   one absent day; 0 = lates are paid) and how advances are recovered
 *   (`installment` = the monthly installment set on each advance, `full` = the
 *   whole open balance in the next run). Recovery never exceeds the net pay.
 * - A run is draft → approved → paid. Drafts can take manual additions and be
 *   deleted; paid runs are immutable. Attendance in a period with an approved or
 *   paid run is locked.
 * - Paying posts one cash-out per payslip (net) from the chosen account, one
 *   "Salaries & wages" expense for what was earned (net + advance recovered;
 *   linked to the run, it cannot be voided alone) and the advance recoveries.
 * - Advances are paid out from an account (staff receivable, not an expense) and
 *   recovered through payroll or in cash into Cash in hand.
 */
class Payroll
{
    public const SETTINGS_KEY = 'hr_payroll';

    public const DEFAULTS = ['weeklyOffDays' => [5], 'latesPerAbsentDay' => 0, 'advanceRecovery' => 'installment'];

    public function __construct(private DocumentNumbers $numbers) {}

    // ---------------------------------------------------------------- settings

    public function settings(): array
    {
        $stored = Setting::where(['scope' => 'global', 'scope_id' => 0, 'key' => self::SETTINGS_KEY])->value('value');
        $stored = is_string($stored) ? json_decode($stored, true) : (array) $stored;

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function saveSettings(array $values): array
    {
        $clean = array_merge(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS));
        $clean['weeklyOffDays'] = array_values(array_unique(array_map('intval', $clean['weeklyOffDays'])));
        Setting::updateOrCreate(['scope' => 'global', 'scope_id' => 0, 'key' => self::SETTINGS_KEY], ['value' => $clean]);

        return $this->settings();
    }

    // ---------------------------------------------------------------- attendance

    public function assertAttendanceOpen(string $date): void
    {
        $period = substr($date, 0, 7);
        if (PayrollRun::where('period', $period)->whereIn('status', ['approved', 'paid'])->exists()) {
            throw ValidationException::withMessages(['date' => "Payroll for $period is approved; its attendance is locked."]);
        }
    }

    /** @param  array<int, array{employeeId: int, status: ?string, checkIn?: ?string, checkOut?: ?string}>  $rows */
    public function saveAttendance(User $user, string $date, array $rows): int
    {
        $this->assertAttendanceOpen($date);
        $ids = Employee::where('is_active', true)->pluck('id')->flip();

        return DB::transaction(function () use ($user, $date, $rows, $ids) {
            $n = 0;
            foreach ($rows as $i => $r) {
                if (! isset($ids[$r['employeeId']])) {
                    throw ValidationException::withMessages(["rows.$i.employeeId" => 'Unknown or inactive employee.']);
                }
                if (empty($r['status'])) {
                    Attendance::where(['employee_id' => $r['employeeId'], 'date' => $date])->delete();

                    continue;
                }
                Attendance::updateOrCreate(['employee_id' => $r['employeeId'], 'date' => $date], [
                    'status' => $r['status'], 'check_in' => $r['checkIn'] ?: null, 'check_out' => $r['checkOut'] ?: null, 'recorded_by' => $user->id,
                ]);
                $n++;
            }

            return $n;
        });
    }

    /** Working days in a month for an employee: days minus weekly off days minus holidays (theirs or all branches). */
    public function workingDays(string $period, ?int $warehouseId): int
    {
        $start = Carbon::parse($period.'-01');
        $off = $this->settings()['weeklyOffDays'];
        $holidays = Holiday::whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->where(fn ($q) => $q->whereNull('warehouse_id')->when($warehouseId, fn ($q) => $q->orWhere('warehouse_id', $warehouseId)))
            ->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->flip();
        $days = 0;
        for ($d = $start->copy(); $d->month === $start->month; $d->addDay()) {
            if (! in_array($d->dayOfWeek, $off, true) && ! isset($holidays[$d->toDateString()])) {
                $days++;
            }
        }

        return $days;
    }

    // ---------------------------------------------------------------- payroll runs

    public function createRun(User $user, string $period): PayrollRun
    {
        if (PayrollRun::where('period', $period)->exists()) {
            throw ValidationException::withMessages(['period' => "A payroll run for $period already exists."]);
        }
        $end = Carbon::parse($period.'-01')->endOfMonth()->toDateString();
        $employees = Employee::where('is_active', true)->where('join_date', '<=', $end)->orderBy('name')->get();
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['period' => 'There are no active employees for this period.']);
        }

        return DB::transaction(function () use ($user, $period, $employees) {
            $run = PayrollRun::create(['period' => $period, 'status' => 'draft', 'created_by' => $user->id]);
            foreach ($employees as $e) {
                $slip = new Payslip(['payroll_run_id' => $run->id, 'employee_id' => $e->id, 'basic' => $e->basic_salary, 'additions' => 0]);
                $this->compute($slip, $e, $period);
                $slip->save();
            }
            AuditLog::record($user->id, 'payroll.created', 'payroll_run', $run->id, ['period' => $period]);

            return $run;
        });
    }

    /** Draft only: manual addition (bonus, overtime); recovery and net are recomputed. */
    public function updatePayslip(PayrollRun $run, Payslip $slip, float $additions, ?string $note): Payslip
    {
        $this->assertStatus($run, 'draft');
        $slip->additions = round($additions, 2);
        $slip->addition_note = $note;
        $this->compute($slip, $slip->employee, $run->period);
        $slip->save();

        return $slip;
    }

    public function approve(User $user, PayrollRun $run): PayrollRun
    {
        $this->assertStatus($run, 'draft');
        // Attendance may have changed since the draft: recompute before locking it.
        foreach ($run->payslips()->with('employee')->get() as $slip) {
            $this->compute($slip, $slip->employee, $run->period);
            $slip->save();
        }
        $run->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
        AuditLog::record($user->id, 'payroll.approved', 'payroll_run', $run->id, ['period' => $run->period]);

        return $run;
    }

    public function pay(User $user, PayrollRun $run, Account $account): PayrollRun
    {
        return DB::transaction(function () use ($user, $run, $account) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($run, 'approved');
            $slips = $run->payslips()->with('employee')->get();
            $date = now()->toDateString();
            $earned = 0;
            foreach ($slips as $slip) {
                $net = SaleService::paisa($slip->net);
                if ($net > 0) {
                    AccountTransaction::create([
                        'account_id' => $account->id, 'txn_date' => $date, 'direction' => 'out', 'amount' => SaleService::money($net),
                        'source_type' => 'payslip', 'source_id' => $slip->id, 'note' => "Salary {$run->period}: {$slip->employee->name}",
                    ]);
                }
                $this->applyRecovery($user, $slip);
                $earned += $net + SaleService::paisa($slip->advance_recovery);
            }
            $expense = null;
            if ($earned > 0) {
                $category = FinanceCategory::firstOrCreate(['kind' => 'expense', 'name' => 'Salaries & wages'], ['is_active' => true]);
                $warehouse = Warehouse::where('is_active', true)->orderBy('id')->firstOrFail();
                // The cash already left per payslip above: this entry records the expense only (no transaction of its own).
                $expense = FinanceEntry::create([
                    'kind' => 'expense', 'number' => $this->numbers->next('expense', $warehouse), 'warehouse_id' => $warehouse->id,
                    'category_id' => $category->id, 'account_id' => $account->id, 'amount' => SaleService::money($earned), 'entry_date' => $date,
                    'note' => "Payroll {$run->period}", 'source_type' => 'payroll_run', 'source_id' => $run->id, 'status' => 'posted', 'created_by' => $user->id,
                ]);
            }
            $run->update(['status' => 'paid', 'paid_by' => $user->id, 'paid_at' => $date, 'account_id' => $account->id, 'expense_entry_id' => $expense?->id]);
            AuditLog::record($user->id, 'payroll.paid', 'payroll_run', $run->id, ['period' => $run->period, 'account' => $account->name]);

            return $run;
        });
    }

    public function deleteRun(User $user, PayrollRun $run): void
    {
        $this->assertStatus($run, 'draft', 'Only draft payroll runs can be deleted.');
        $run->delete();
        AuditLog::record($user->id, 'payroll.deleted', 'payroll_run', $run->id, ['period' => $run->period]);
    }

    // ---------------------------------------------------------------- advances

    public static function remaining(SalaryAdvance $a): float
    {
        return round((float) $a->amount - (float) $a->recoveries()->sum('amount'), 2);
    }

    public function giveAdvance(User $user, Employee $employee, float $amount, ?float $installment, Account $account): SalaryAdvance
    {
        if (! Employee::whereKey($employee->id)->value('is_active')) {
            throw ValidationException::withMessages(['employeeId' => 'This employee is inactive.']);
        }
        if ($this->settings()['advanceRecovery'] === 'installment' && $installment !== null && $installment > $amount) {
            throw ValidationException::withMessages(['installment' => 'The monthly installment cannot be more than the advance.']);
        }

        return DB::transaction(function () use ($user, $employee, $amount, $installment, $account) {
            $a = SalaryAdvance::create([
                'employee_id' => $employee->id, 'amount' => round($amount, 2), 'installment' => $installment ? round($installment, 2) : null,
                'given_at' => now()->toDateString(), 'account_id' => $account->id, 'status' => 'open', 'created_by' => $user->id,
            ]);
            AccountTransaction::create([
                'account_id' => $account->id, 'txn_date' => now()->toDateString(), 'direction' => 'out', 'amount' => $a->amount,
                'source_type' => 'salary_advance', 'source_id' => $a->id, 'note' => 'Salary advance: '.$employee->name,
            ]);
            AuditLog::record($user->id, 'advance.given', 'employee', $employee->id, ['amount' => (float) $a->amount]);

            return $a;
        });
    }

    /** Cash repayment outside payroll, into Cash in hand. */
    public function recordRecovery(User $user, SalaryAdvance $a, float $amount): void
    {
        DB::transaction(function () use ($user, $a, $amount) {
            $a = SalaryAdvance::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($a->status !== 'open') {
                throw ValidationException::withMessages(['amount' => 'This advance is not open.']);
            }
            $left = self::remaining($a);
            if ($amount <= 0 || SaleService::paisa($amount) > SaleService::paisa($left)) {
                throw ValidationException::withMessages(['amount' => 'Only '.SaleService::money(SaleService::paisa($left)).' is left to recover.']);
            }
            $cash = Account::where('type', 'cash')->orderByDesc('is_default')->firstOrFail();
            $r = AdvanceRecovery::create(['salary_advance_id' => $a->id, 'amount' => round($amount, 2), 'source' => 'cash', 'account_id' => $cash->id, 'created_by' => $user->id, 'created_at' => now()]);
            AccountTransaction::create([
                'account_id' => $cash->id, 'txn_date' => now()->toDateString(), 'direction' => 'in', 'amount' => $r->amount,
                'source_type' => 'advance_recovery', 'source_id' => $r->id, 'note' => 'Advance repaid: '.$a->employee->name,
            ]);
            $this->closeIfRecovered($a);
        });
    }

    public function voidAdvance(User $user, SalaryAdvance $a, string $reason): void
    {
        DB::transaction(function () use ($user, $a, $reason) {
            $a = SalaryAdvance::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($a->status === 'void') {
                throw ValidationException::withMessages(['reason' => 'This advance is already void.']);
            }
            if ($a->recoveries()->exists()) {
                throw ValidationException::withMessages(['recovered' => 'Advances with recoveries cannot be voided.']);
            }
            AccountTransaction::create([
                'account_id' => $a->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'in', 'amount' => $a->amount,
                'source_type' => 'salary_advance_void', 'source_id' => $a->id, 'note' => 'Void advance: '.$reason,
            ]);
            $a->update(['status' => 'void', 'void_reason' => $reason]);
            AuditLog::record($user->id, 'advance.voided', 'employee', $a->employee_id, ['reason' => $reason]);
        });
    }

    // ---------------------------------------------------------------- internals

    private function compute(Payslip $slip, Employee $e, string $period): void
    {
        $s = $this->settings();
        $start = $period.'-01';
        $end = Carbon::parse($start)->endOfMonth()->toDateString();
        $counts = Attendance::where('employee_id', $e->id)->whereBetween('date', [$start, $end])
            ->groupBy('status')->pluck(DB::raw('COUNT(*)'), 'status');
        $absent = (int) ($counts['absent'] ?? 0);
        $late = (int) ($counts['late'] ?? 0);
        $lateAsAbsent = (int) $s['latesPerAbsentDay'] > 0 ? intdiv($late, (int) $s['latesPerAbsentDay']) : 0;

        $basic = SaleService::paisa($slip->basic);
        $deduction = min($basic, (int) round($basic / 30 * ($absent + $lateAsAbsent)));
        $beforeRecovery = max(0, $basic + SaleService::paisa($slip->additions) - $deduction);

        $recovery = 0;
        foreach (SalaryAdvance::where(['employee_id' => $e->id, 'status' => 'open'])->orderBy('given_at')->orderBy('id')->get() as $a) {
            $left = SaleService::paisa(self::remaining($a));
            $take = $s['advanceRecovery'] === 'full' || $a->installment === null ? $left : min($left, SaleService::paisa($a->installment));
            $recovery += $take;
        }
        $recovery = min($recovery, $beforeRecovery);

        $slip->fill([
            'absent_days' => $absent, 'late_days' => $late,
            'present_days' => (int) ($counts['present'] ?? 0) + $late,
            'working_days' => $this->workingDays($period, $e->warehouse_id),
            'deductions' => SaleService::money($deduction), 'advance_recovery' => SaleService::money($recovery),
            'net' => SaleService::money($beforeRecovery - $recovery),
        ]);
    }

    /** At payment: spreads the payslip's recovery over the employee's oldest open advances. */
    private function applyRecovery(User $user, Payslip $slip): void
    {
        $left = SaleService::paisa($slip->advance_recovery);
        foreach (SalaryAdvance::where(['employee_id' => $slip->employee_id, 'status' => 'open'])->orderBy('given_at')->orderBy('id')->lockForUpdate()->get() as $a) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, SaleService::paisa(self::remaining($a)));
            if ($take <= 0) {
                continue;
            }
            AdvanceRecovery::create(['salary_advance_id' => $a->id, 'amount' => SaleService::money($take), 'source' => 'payroll', 'payslip_id' => $slip->id, 'created_by' => $user->id, 'created_at' => now()]);
            $left -= $take;
            $this->closeIfRecovered($a);
        }
    }

    private function closeIfRecovered(SalaryAdvance $a): void
    {
        if (self::remaining($a) <= 0) {
            $a->update(['status' => 'recovered']);
        }
    }

    private function assertStatus(PayrollRun $run, string $status, ?string $message = null): void
    {
        if ($run->status !== $status) {
            throw ValidationException::withMessages(['status' => $message ?? "This payroll run is {$run->status}, not {$status}."]);
        }
    }
}
