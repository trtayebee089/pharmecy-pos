<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Payroll;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** HR & payroll for the POS (shapes in pos/app/types/hr.ts). */
class HrController extends Controller
{
    public function __construct(private Payroll $payroll) {}

    // ---------------------------------------------------------------- generic list helper

    private function listOf(Request $request, $query, callable $shape): JsonResponse
    {
        if (filter_var($request->query('all'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($query->get()->map($shape)->values());
        }
        $page = $query->paginate((int) $request->query('perPage', 25), ['*'], 'page', (int) $request->query('page', 1));

        return response()->json(Present::paginated($page, collect($page->items())->map($shape)->all()));
    }

    /** Accepts a warehouse by id, code or (short) name; "All…" or empty = none. */
    private function warehouseId(mixed $value): ?int
    {
        if ($value === null || $value === '' || str_starts_with(strtolower((string) $value), 'all')) {
            return null;
        }
        $w = Warehouse::where('id', is_numeric($value) ? (int) $value : 0)->orWhere('code', $value)->orWhere('name', $value)
            ->orWhere('name', 'like', '%'.$value)->first();
        if (! $w) {
            throw ValidationException::withMessages(['warehouse' => 'Unknown branch.']);
        }

        return $w->id;
    }

    // ---------------------------------------------------------------- departments & designations

    public function departments(Request $request): JsonResponse
    {
        return $this->listOf($request, Department::withCount('employees')->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))->orderBy('name'),
            fn ($d) => ['id' => $d->id, 'name' => $d->name, 'employees' => (int) $d->employees_count]);
    }

    public function saveDepartment(Request $request, ?Department $department = null): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('departments', 'name')->ignore($department?->id)]]);
        $department = $department?->exists ? tap($department)->update(['name' => trim($d['name'])]) : Department::create(['name' => trim($d['name'])]);

        return response()->json(['id' => $department->id, 'name' => $department->name, 'employees' => $department->employees()->count()], $department->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteDepartment(Department $department): JsonResponse
    {
        if ($department->employees()->exists() || Designation::where('department_id', $department->id)->exists()) {
            abort(422, 'This department still has employees or designations.');
        }
        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    public function designations(Request $request): JsonResponse
    {
        return $this->listOf($request, Designation::with('department')->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))->orderBy('name'),
            fn ($d) => ['id' => $d->id, 'name' => $d->name, 'department' => $d->department?->name]);
    }

    public function saveDesignation(Request $request, ?Designation $designation = null): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'department' => ['required', 'string']]);
        $dept = Department::where('name', $d['department'])->first() ?? throw ValidationException::withMessages(['department' => 'Unknown department.']);
        $exists = Designation::where(['department_id' => $dept->id, 'name' => trim($d['name'])])->when($designation?->id, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'This department already has that designation.']);
        }
        $attrs = ['name' => trim($d['name']), 'department_id' => $dept->id];
        $designation = $designation?->exists ? tap($designation)->update($attrs) : Designation::create($attrs);

        return response()->json(['id' => $designation->id, 'name' => $designation->name, 'department' => $dept->name], $designation->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteDesignation(Designation $designation): JsonResponse
    {
        if (Employee::where('designation_id', $designation->id)->exists()) {
            abort(422, 'Employees still hold this designation.');
        }
        $designation->delete();

        return response()->json(['message' => 'Designation deleted.']);
    }

    // ---------------------------------------------------------------- employees

    public function employees(Request $request): JsonResponse
    {
        $q = Employee::with('department', 'designation', 'warehouse', 'user')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('code', $s)->orWhere('phone', 'like', "%$s%")))
            ->when($request->has('isActive') && $request->query('isActive') !== '', fn ($q) => $q->where('is_active', filter_var($request->query('isActive'), FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name');

        return $this->listOf($request, $q, fn ($e) => self::employeeShape($e));
    }

    public function employee(Employee $employee): JsonResponse
    {
        return response()->json(self::employeeShape($employee->load('department', 'designation', 'warehouse', 'user')));
    }

    public function saveEmployee(Request $request, ?Employee $employee = null): JsonResponse
    {
        if ($employee?->exists) {
            $request->merge(array_merge(self::employeeShape($employee->load('department', 'designation', 'warehouse', 'user')), $request->all()));
        }
        $d = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('employees', 'code')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'],
            'department' => ['required', 'string'], 'designation' => ['required', 'string'], 'joinDate' => ['required', 'date_format:Y-m-d'],
            'basicSalary' => ['required', 'numeric', 'min:0', 'max:10000000'], 'userAccount' => ['nullable', 'string'],
            'warehouse' => ['nullable'], 'isActive' => ['nullable', 'boolean'],
        ]);
        $dept = Department::where('name', $d['department'])->first() ?? throw ValidationException::withMessages(['department' => 'Unknown department.']);
        $desig = Designation::where(['department_id' => $dept->id, 'name' => $d['designation']])->first()
            ?? throw ValidationException::withMessages(['designation' => 'Choose a designation of the '.$dept->name.' department.']);
        $userId = null;
        if (! empty($d['userAccount'])) {
            $userId = User::where('username', $d['userAccount'])->value('id') ?? throw ValidationException::withMessages(['userAccount' => 'No POS login with that username.']);
            if (Employee::where('user_id', $userId)->when($employee?->id, fn ($q, $id) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['userAccount' => 'That login already belongs to another employee.']);
            }
        }
        $attrs = [
            'code' => trim($d['code']), 'name' => trim($d['name']), 'phone' => $d['phone'], 'email' => $d['email'] ?? null,
            'department_id' => $dept->id, 'designation_id' => $desig->id, 'join_date' => $d['joinDate'], 'basic_salary' => $d['basicSalary'],
            'user_id' => $userId, 'warehouse_id' => $this->warehouseId($d['warehouse'] ?? null), 'is_active' => $d['isActive'] ?? true,
        ];
        $employee = $employee?->exists ? tap($employee)->update($attrs) : Employee::create($attrs);
        AuditLog::record($request->user()->id, 'employee.saved', 'employee', $employee->id, array_diff_key($attrs, ['basic_salary' => 1]) + ['basic_salary' => (float) $attrs['basic_salary']], $request);

        return response()->json(self::employeeShape($employee->fresh(['department', 'designation', 'warehouse', 'user'])), $employee->wasRecentlyCreated ? 201 : 200);
    }

    // ---------------------------------------------------------------- holidays

    public function holidays(Request $request): JsonResponse
    {
        $q = Holiday::with('warehouse')->when($request->query('year'), fn ($q, $y) => $q->whereBetween('date', ["$y-01-01", "$y-12-31"]))->orderBy('date');

        return $this->listOf($request, $q, fn ($h) => self::holiday($h));
    }

    public function saveHoliday(Request $request, ?Holiday $holiday = null): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'name' => ['required', 'string', 'max:100'], 'warehouse' => ['nullable']]);
        $attrs = ['date' => $d['date'], 'name' => trim($d['name']), 'warehouse_id' => $this->warehouseId($d['warehouse'] ?? null)];
        $holiday = $holiday?->exists ? tap($holiday)->update($attrs) : Holiday::create($attrs);

        return response()->json(self::holiday($holiday->fresh('warehouse')), $holiday->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteHoliday(Holiday $holiday): JsonResponse
    {
        $this->payroll->assertAttendanceOpen(substr((string) $holiday->date, 0, 10));
        $holiday->delete();

        return response()->json(['message' => 'Holiday deleted.']);
    }

    // ---------------------------------------------------------------- attendance

    public function attendanceDay(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $saved = Attendance::where('date', $date)->get()->keyBy('employee_id');

        return response()->json(Employee::with('designation')->where('is_active', true)->where('join_date', '<=', $date)->orderBy('name')->get()
            ->map(fn ($e) => [
                'employeeId' => $e->id, 'name' => $e->name, 'designation' => $e->designation?->name ?? '',
                'status' => $saved[$e->id]->status ?? null, 'checkIn' => (string) ($saved[$e->id]->check_in ?? ''), 'checkOut' => (string) ($saved[$e->id]->check_out ?? ''),
            ])->values());
    }

    public function attendanceMonth(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'year' => ['required', 'integer'], 'month' => ['required', 'integer', 'between:1,12']]);
        $start = Carbon::create($d['year'], $d['month'], 1);
        $saved = Attendance::where('employee_id', $d['employee_id'])->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->pluck('status', 'date')->mapWithKeys(fn ($s, $date) => [substr((string) $date, 0, 10) => $s]);
        $out = [];
        for ($day = $start->copy(); $day->month === $start->month; $day->addDay()) {
            $out[$day->toDateString()] = $saved[$day->toDateString()] ?? null;
        }

        return response()->json($out);
    }

    public function saveAttendance(Request $request): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rows' => ['required', 'array', 'max:1000'], 'rows.*.employeeId' => ['required', 'integer'],
            'rows.*.status' => ['nullable', 'in:present,absent,leave,late'],
            'rows.*.checkIn' => ['nullable', 'date_format:H:i'], 'rows.*.checkOut' => ['nullable', 'date_format:H:i'],
        ]);

        return response()->json(['saved' => $this->payroll->saveAttendance($request->user(), $d['date'], $d['rows'])], 201);
    }

    // ---------------------------------------------------------------- settings

    public function settings(): JsonResponse
    {
        return response()->json($this->payroll->settings());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'weeklyOffDays' => ['present', 'array', 'max:6'], 'weeklyOffDays.*' => ['integer', 'between:0,6'],
            'latesPerAbsentDay' => ['required', 'integer', 'min:0', 'max:31'],
            'advanceRecovery' => ['required', 'in:installment,full'],
        ]);
        $saved = $this->payroll->saveSettings($d);
        AuditLog::record($request->user()->id, 'settings.hr_payroll', 'settings', null, $saved, $request);

        return response()->json($saved);
    }

    // ---------------------------------------------------------------- payroll runs

    public function runs(): JsonResponse
    {
        return response()->json(PayrollRun::with('account')->orderByDesc('period')->get()->map(fn ($r) => self::run($r))->values());
    }

    public function showRun(PayrollRun $run): JsonResponse
    {
        return response()->json([
            'run' => self::run($run->load('account')),
            'payslips' => $run->payslips()->with('employee.designation')->get()->sortBy(fn ($s) => $s->employee?->name)->map(fn ($s) => self::payslip($s))->values(),
        ]);
    }

    public function createRun(Request $request): JsonResponse
    {
        $d = $request->validate(['period' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')]]);

        return response()->json(self::run($this->payroll->createRun($request->user(), $d['period'])), 201);
    }

    public function approveRun(Request $request, PayrollRun $run): JsonResponse
    {
        return response()->json(self::run($this->payroll->approve($request->user(), $run)));
    }

    public function payRun(Request $request, PayrollRun $run): JsonResponse
    {
        $d = $request->validate(['account' => ['required']]);

        return response()->json(self::run($this->payroll->pay($request->user(), $run, $this->account($d['account']))->load('account')));
    }

    public function deleteRun(Request $request, PayrollRun $run): JsonResponse
    {
        $this->payroll->deleteRun($request->user(), $run);

        return response()->json(['message' => 'Draft run deleted.']);
    }

    public function updatePayslip(Request $request, PayrollRun $run, Payslip $payslip): JsonResponse
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);
        $d = $request->validate(['additions' => ['required', 'numeric', 'min:0', 'max:10000000'], 'note' => ['nullable', 'string', 'max:255']]);

        return response()->json(self::payslip($this->payroll->updatePayslip($run, $payslip->load('employee.designation'), (float) $d['additions'], $d['note'] ?? null)));
    }

    /** Active accounts staff can be paid from (names, as the POS shows them). */
    public function payoutAccounts(): JsonResponse
    {
        return response()->json(Account::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name')->values());
    }

    // ---------------------------------------------------------------- advances

    public function advances(): JsonResponse
    {
        return response()->json(SalaryAdvance::with('employee')->where('status', '!=', 'void')->orderByDesc('given_at')->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'employeeId' => $a->employee_id, 'employee' => $a->employee?->name ?? '', 'amount' => (float) $a->amount,
                'installment' => $a->installment !== null ? (float) $a->installment : null, 'givenAt' => substr((string) $a->given_at, 0, 10),
                'recovered' => round((float) $a->amount - Payroll::remaining($a), 2), 'status' => $a->status,
            ])->values());
    }

    public function giveAdvance(Request $request): JsonResponse
    {
        $d = $request->validate(['employeeId' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'installment' => ['nullable', 'numeric', 'gt:0'], 'account' => ['required']]);
        $employee = Employee::findOrFail($d['employeeId']);
        $a = $this->payroll->giveAdvance($request->user(), $employee, (float) $d['amount'], isset($d['installment']) ? (float) $d['installment'] : null, $this->account($d['account']));

        return response()->json(['id' => $a->id, 'employee' => $employee->name, 'amount' => (float) $a->amount, 'installment' => $a->installment !== null ? (float) $a->installment : null,
            'givenAt' => substr((string) $a->given_at, 0, 10), 'recovered' => 0, 'status' => 'open'], 201);
    }

    public function recoverAdvance(Request $request, SalaryAdvance $advance): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);
        $this->payroll->recordRecovery($request->user(), $advance, (float) $d['amount']);

        return response()->json(['recovered' => round((float) $advance->amount - Payroll::remaining($advance->fresh()), 2)], 201);
    }

    public function voidAdvance(Request $request, SalaryAdvance $advance): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->payroll->voidAdvance($request->user(), $advance, trim($d['reason']));

        return response()->json(['message' => 'Advance voided.']);
    }

    // ---------------------------------------------------------------- shapes

    private function account(mixed $value): Account
    {
        $a = Account::where('is_active', true)->where(fn ($q) => $q->where('id', is_numeric($value) ? (int) $value : 0)->orWhere('name', $value))->first();

        return $a ?? throw ValidationException::withMessages(['account' => 'Choose an active account.']);
    }

    public static function employeeShape(Employee $e): array
    {
        return [
            'id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'phone' => $e->phone, 'email' => (string) $e->email,
            'department' => $e->department?->name ?? '', 'designation' => $e->designation?->name ?? '',
            'joinDate' => substr((string) $e->join_date, 0, 10), 'basicSalary' => (float) $e->basic_salary,
            'userAccount' => $e->user?->username, 'warehouse' => $e->warehouse?->name ?? '', 'warehouseId' => $e->warehouse_id, 'isActive' => $e->is_active,
        ];
    }

    private static function holiday(Holiday $h): array
    {
        $date = substr((string) $h->date, 0, 10);

        return ['id' => $h->id, 'date' => $date, 'day' => Carbon::parse($date)->format('l'), 'name' => $h->name, 'warehouse' => $h->warehouse?->name ?? 'All warehouses'];
    }

    private static function run(PayrollRun $r): array
    {
        $t = DB::table('payslips')->where('payroll_run_id', $r->id)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(basic + additions), 0) AS gross, COALESCE(SUM(deductions + advance_recovery), 0) AS ded, COALESCE(SUM(net), 0) AS net')->first();

        return [
            'id' => $r->id, 'period' => $r->period, 'employees' => (int) $t->n, 'gross' => round((float) $t->gross, 2),
            'deductions' => round((float) $t->ded, 2), 'net' => round((float) $t->net, 2), 'status' => $r->status,
            'paidAt' => $r->paid_at ? substr((string) $r->paid_at, 0, 10) : null, 'paidFrom' => $r->account?->name,
        ];
    }

    private static function payslip(Payslip $s): array
    {
        return [
            'id' => $s->id, 'employeeId' => $s->employee_id, 'name' => $s->employee?->name ?? '', 'designation' => $s->employee?->designation?->name ?? '',
            'basic' => (float) $s->basic, 'additions' => (float) $s->additions, 'additionNote' => (string) $s->addition_note,
            'deductions' => (float) $s->deductions, 'advanceRecovery' => (float) $s->advance_recovery, 'net' => (float) $s->net,
            'presentDays' => (int) $s->present_days, 'workingDays' => (int) $s->working_days, 'absentDays' => (int) $s->absent_days, 'lateDays' => (int) $s->late_days,
        ];
    }
}
