<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\FinanceEntry;
use App\Models\Holiday;
use App\Models\SalaryAdvance;
use App\Services\Finance;
use App\Services\Payroll;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = posUser('Admin');
    $this->dept = Department::create(['name' => 'Counter']);
    $this->desig = Designation::create(['department_id' => $this->dept->id, 'name' => 'Pharmacist']);
    $this->period = now()->subMonthNoOverflow()->format('Y-m');
    $this->cash = Account::where('code', 'CASH')->firstOrFail();
});

function staff(string $name = 'Sabbir', float $basic = 30000): Employee
{
    $t = test();

    return Employee::create([
        'code' => 'EMP-'.uniqid(), 'name' => $name, 'phone' => '017'.random_int(10000000, 99999999), 'department_id' => $t->dept->id,
        'designation_id' => $t->desig->id, 'join_date' => '2025-01-01', 'basic_salary' => $basic, 'warehouse_id' => $t->warehouse->id,
    ]);
}

function mark(Employee $e, string $date, string $status): void
{
    Attendance::create(['employee_id' => $e->id, 'date' => $date, 'status' => $status]);
}

function day_in(string $period, int $d): string
{
    return $period.'-'.str_pad((string) $d, 2, '0', STR_PAD_LEFT);
}

function hrSettings(array $s): void
{
    app(Payroll::class)->saveSettings($s + Payroll::DEFAULTS);
}

function newRun($test, ?string $period = null)
{
    return $test->actingAs($test->admin)->postJson('/api/pos/v1/hr/payroll-runs', ['period' => $period ?? $test->period], ['Idempotency-Key' => uniqid()]);
}

function slipsOf($test, int $runId): array
{
    return $test->actingAs($test->admin)->getJson("/api/pos/v1/hr/payroll-runs/$runId")->assertOk()->json('payslips');
}

// ---------------------------------------------------------------- employees, holidays, settings

it('manages employees by department, designation and branch name', function () {
    $body = ['code' => 'EMP-001', 'name' => 'Moushumi', 'phone' => '01711111111', 'department' => 'Counter', 'designation' => 'Pharmacist',
        'joinDate' => '2025-03-01', 'basicSalary' => 25000, 'warehouse' => 'Dhanmondi', 'isActive' => true];
    $id = $this->actingAs($this->admin)->postJson('/api/pos/v1/hr/employees', $body)->assertCreated()
        ->assertJsonPath('warehouse', 'Dhanmondi')->assertJsonPath('designation', 'Pharmacist')->json('id');

    $this->actingAs($this->admin)->postJson('/api/pos/v1/hr/employees', $body)->assertJsonValidationErrors('code');
    $this->actingAs($this->admin)->putJson("/api/pos/v1/hr/employees/$id", ['isActive' => false])->assertOk()->assertJsonPath('isActive', false)->assertJsonPath('basicSalary', 25000);
    $this->actingAs($this->admin)->putJson("/api/pos/v1/hr/employees/$id", ['designation' => 'Cleaner'])->assertJsonValidationErrors('designation');
    $this->actingAs($this->admin)->deleteJson("/api/pos/v1/hr/departments/{$this->dept->id}")->assertStatus(422);
    $this->actingAs(posUser('Cashier'))->getJson('/api/pos/v1/hr/employees')->assertForbidden();
});

it('counts working days from the weekly off days and holidays in settings', function () {
    hrSettings(['weeklyOffDays' => [5]]);
    $p = '2026-02'; // 28 days, 4 Fridays
    expect(app(Payroll::class)->workingDays($p, $this->warehouse->id))->toBe(24);

    Holiday::create(['date' => '2026-02-21', 'name' => 'Language Martyrs Day']);          // Saturday, all branches
    Holiday::create(['date' => '2026-02-10', 'name' => 'Branch day', 'warehouse_id' => $this->warehouse->id]); // this branch only
    expect(app(Payroll::class)->workingDays($p, $this->warehouse->id))->toBe(22);

    hrSettings(['weeklyOffDays' => [5, 6]]); // 21st is now a Saturday off anyway
    expect(app(Payroll::class)->workingDays($p, $this->warehouse->id))->toBe(19);

    $this->actingAs(posUser('Manager'))->putJson('/api/pos/v1/hr/settings', Payroll::DEFAULTS)->assertForbidden(); // Manager has no hr.payroll
    $this->actingAs($this->admin)->putJson('/api/pos/v1/hr/settings', ['weeklyOffDays' => [], 'latesPerAbsentDay' => 3, 'advanceRecovery' => 'full'])
        ->assertOk()->assertJsonPath('latesPerAbsentDay', 3)->assertJsonPath('weeklyOffDays', []);
});

// ---------------------------------------------------------------- attendance

it('saves a day of attendance and shows it on the month grid', function () {
    $e = staff();
    $date = now()->subDay()->toDateString();
    $this->actingAs($this->admin)->postJson('/api/pos/v1/hr/attendance', ['date' => $date, 'rows' => [['employeeId' => $e->id, 'status' => 'late', 'checkIn' => '09:40', 'checkOut' => '18:00']]], ['Idempotency-Key' => 'a'])
        ->assertCreated()->assertJsonPath('saved', 1);

    $this->actingAs($this->admin)->getJson("/api/pos/v1/hr/attendance?date=$date")->assertJsonPath('0.status', 'late')->assertJsonPath('0.checkIn', '09:40');
    $month = $this->actingAs($this->admin)->getJson('/api/pos/v1/hr/attendance/month?employee_id='.$e->id.'&year='.now()->subDay()->year.'&month='.now()->subDay()->month)->json();
    expect($month[$date])->toBe('late');

    $this->actingAs($this->admin)->postJson('/api/pos/v1/hr/attendance', ['date' => now()->addDay()->toDateString(), 'rows' => []], ['Idempotency-Key' => 'b'])->assertJsonValidationErrors('date');
});

// ---------------------------------------------------------------- payroll

it('deducts basic ÷ 30 per absent day and applies the late rule from settings', function () {
    $e = staff('Sabbir', 30000);
    mark($e, day_in($this->period, 3), 'absent');
    mark($e, day_in($this->period, 4), 'absent');
    foreach ([5, 6, 7] as $d) {
        mark($e, day_in($this->period, $d), 'late');
    }
    mark($e, day_in($this->period, 8), 'leave');

    $id = newRun($this)->assertCreated()->assertJsonPath('status', 'draft')->json('id');
    $slip = slipsOf($this, $id)[0];
    expect($slip['deductions'])->toEqual(2000) // 30000/30 × 2 absences; leave and late are paid
        ->and($slip['net'])->toEqual(28000)->and($slip['presentDays'])->toBe(3)->and($slip['lateDays'])->toBe(3);

    hrSettings(['latesPerAbsentDay' => 3]); // 3 lates = 1 more absent day
    $this->actingAs($this->admin)->deleteJson("/api/pos/v1/hr/payroll-runs/$id")->assertOk();
    $slip = slipsOf($this, newRun($this)->json('id'))[0];
    expect($slip['deductions'])->toEqual(3000)->and($slip['net'])->toEqual(27000);

    newRun($this)->assertJsonValidationErrors('period');
});

it('adds bonuses in draft, approves, pays and then locks the run and its attendance', function () {
    $e = staff('Sabbir', 30000);
    $id = newRun($this)->json('id');
    $slipId = slipsOf($this, $id)[0]['id'];

    $this->actingAs($this->admin)->putJson("/api/pos/v1/hr/payroll-runs/$id/payslips/$slipId", ['additions' => 1500, 'note' => 'Overtime'])
        ->assertOk()->assertJsonPath('net', 31500)->assertJsonPath('additionNote', 'Overtime');
    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/payroll-runs/$id/pay", ['account' => $this->cash->name], ['Idempotency-Key' => 'p0'])
        ->assertJsonValidationErrors('status'); // must be approved first

    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/payroll-runs/$id/approve")->assertOk()->assertJsonPath('status', 'approved');
    $this->actingAs($this->admin)->postJson('/api/pos/v1/hr/attendance', ['date' => day_in($this->period, 9), 'rows' => [['employeeId' => $e->id, 'status' => 'absent']]], ['Idempotency-Key' => 'a'])
        ->assertJsonValidationErrors('date'); // locked
    $this->actingAs($this->admin)->putJson("/api/pos/v1/hr/payroll-runs/$id/payslips/$slipId", ['additions' => 0])->assertJsonValidationErrors('status');

    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/payroll-runs/$id/pay", ['account' => $this->cash->name], ['Idempotency-Key' => 'p1'])
        ->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('paidFrom', $this->cash->name)->assertJsonPath('net', 31500);

    expect((float) AccountTransaction::where(['account_id' => $this->cash->id, 'source_type' => 'payslip'])->sum('amount'))->toBe(31500.0)
        ->and((float) FinanceEntry::where('source_type', 'payroll_run')->value('amount'))->toBe(31500.0);
    $this->actingAs($this->admin)->deleteJson("/api/pos/v1/hr/payroll-runs/$id")->assertJsonValidationErrors('status');
    $this->actingAs($this->admin)->getJson('/api/pos/v1/finance/accounts/payout')->assertOk()->assertJsonFragment([$this->cash->name]);
});

it('blocks voiding the payroll salary expense from finance', function () {
    staff();
    $id = newRun($this)->json('id');
    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/payroll-runs/$id/approve");
    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/payroll-runs/$id/pay", ['account' => $this->cash->id], ['Idempotency-Key' => 'p']);

    expect(fn () => app(Finance::class)->voidEntry($this->admin, FinanceEntry::where('source_type', 'payroll_run')->firstOrFail(), 'oops'))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------- advances

it('recovers advances by installment, books the expense as earned and pays out only the net', function () {
    hrSettings(['advanceRecovery' => 'installment']);
    $e = staff('Shuvo', 20000);
    $this->actingAs($this->admin)->postJson('/api/pos/v1/hr/salary-advances', ['employeeId' => $e->id, 'amount' => 6000, 'installment' => 2000, 'account' => $this->cash->name], ['Idempotency-Key' => 'g'])
        ->assertCreated()->assertJsonPath('installment', 2000);
    expect((float) AccountTransaction::where('source_type', 'salary_advance')->value('amount'))->toBe(6000.0);

    $id = newRun($this)->json('id');
    expect(slipsOf($this, $id)[0]['advanceRecovery'])->toEqual(2000)->and(slipsOf($this, $id)[0]['net'])->toEqual(18000);
    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/payroll-runs/$id/approve");
    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/payroll-runs/$id/pay", ['account' => $this->cash->name], ['Idempotency-Key' => 'p']);

    expect((float) FinanceEntry::where('source_type', 'payroll_run')->value('amount'))->toBe(20000.0) // earned
        ->and((float) AccountTransaction::where('source_type', 'payslip')->sum('amount'))->toBe(18000.0)   // cash out
        ->and(Payroll::remaining(SalaryAdvance::first()))->toBe(4000.0);
    $this->actingAs($this->admin)->getJson('/api/pos/v1/hr/salary-advances')->assertJsonPath('0.recovered', 2000)->assertJsonPath('0.status', 'open');

    $bs = collect(app(Finance::class)->balanceSheet(now()->toDateString())['assets'])->firstWhere('label', 'Staff salary advances');
    expect($bs['amount'])->toEqual(4000);
});

it('recovers the whole balance in full mode, never more than the net pay', function () {
    hrSettings(['advanceRecovery' => 'full']);
    $e = staff('Rakib', 10000);
    app(Payroll::class)->giveAdvance($this->admin, $e, 15000, null, $this->cash);

    $slip = slipsOf($this, newRun($this)->json('id'))[0];
    expect($slip['advanceRecovery'])->toEqual(10000)->and($slip['net'])->toEqual(0);
});

it('takes cash repayments and voids an advance only before any recovery', function () {
    $e = staff();
    $a = app(Payroll::class)->giveAdvance($this->admin, $e, 5000, 1000, $this->cash);
    $b = app(Payroll::class)->giveAdvance($this->admin, $e, 3000, null, $this->cash);

    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/salary-advances/{$a->id}/recoveries", ['amount' => 6000], ['Idempotency-Key' => 'r0'])->assertJsonValidationErrors('amount');
    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/salary-advances/{$a->id}/recoveries", ['amount' => 5000], ['Idempotency-Key' => 'r1'])->assertCreated()->assertJsonPath('recovered', 5000);
    expect($a->fresh()->status)->toBe('recovered');

    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/salary-advances/{$a->id}/void", ['reason' => 'mistake'])->assertJsonValidationErrors('recovered');
    $this->actingAs($this->admin)->postJson("/api/pos/v1/hr/salary-advances/{$b->id}/void", ['reason' => 'Given twice'])->assertOk();
    expect($b->fresh()->status)->toBe('void')
        ->and((float) AccountTransaction::where('source_type', 'salary_advance_void')->value('amount'))->toBe(3000.0);
});
