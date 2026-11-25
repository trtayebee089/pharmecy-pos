<?php

namespace App\Etl\Legacy\Steps;

use App\Etl\Legacy\Context;
use Carbon\CarbonPeriod;
use Illuminate\Support\Str;

/**
 * Departments, employees, attendance, holidays and open salary advances.
 *
 * - Every department gets one designation "Staff" (legacy has none).
 * - Attendance status 1 = present, 0 = late (legacy SalePro meaning).
 * - Legacy payroll history is not imported (380 of its rows have no salary
 *   month); it stays in the legacy backup and is counted in the report.
 * - Open advance per employee = Σ approved advances − Σ advance deducted on
 *   legacy payrolls; when positive it becomes one open salary advance
 *   (no money moves) so the new payroll recovers it.
 */
class Hr
{
    public function run(Context $c): void
    {
        $designations = [];
        foreach ($c->src->table('departments')->orderBy('id')->get() as $d) {
            $id = $c->dst->table('departments')->insertGetId(['name' => Str::limit(trim($d->name), 80, ''), 'created_at' => now(), 'updated_at' => now()]);
            $designations[$d->id] = [$id, $c->dst->table('designations')->insertGetId(['department_id' => $id, 'name' => 'Staff', 'created_at' => now(), 'updated_at' => now()])];
            $c->count('departments', 'imported');
        }
        $fallback = null;
        foreach ($c->src->table('employees')->orderBy('id')->get() as $e) {
            if (! isset($designations[$e->department_id])) {
                $fallback ??= (function () use ($c) {
                    $dep = $c->dst->table('departments')->insertGetId(['name' => 'General (legacy)', 'created_at' => now(), 'updated_at' => now()]);

                    return [$dep, $c->dst->table('designations')->insertGetId(['department_id' => $dep, 'name' => 'Staff', 'created_at' => now(), 'updated_at' => now()])];
                })();
                $c->count('employees', 'no department → General (legacy)');
            }
            [$dep, $des] = $designations[$e->department_id] ?? $fallback;
            $user = $c->mapped('user', $e->user_id ? (int) $e->user_id : null);
            if ($user && $c->dst->table('employees')->where('user_id', $user)->exists()) {
                $user = null;
            }
            $id = $c->dst->table('employees')->insertGetId([
                'code' => 'E-'.str_pad((string) $e->id, 4, '0', STR_PAD_LEFT), 'name' => trim($e->name), 'phone' => Str::limit((string) $e->phone_number, 20, ''),
                'email' => filter_var((string) $e->email, FILTER_VALIDATE_EMAIL) ? $e->email : null, 'department_id' => $dep, 'designation_id' => $des,
                'join_date' => Context::date($e->created_at, $c->cutoverDate), 'basic_salary' => Context::money($e->salary),
                'user_id' => $user, 'warehouse_id' => $c->defaultWarehouse, 'is_active' => (bool) $e->is_active,
                'created_at' => Context::ts($e->created_at, now()), 'updated_at' => now(),
            ]);
            $c->map('employee', $e->id, $id);
            $c->count('employees', 'imported');
        }

        $seen = [];
        $rows = [];
        foreach ($c->src->table('attendances')->orderBy('id')->get() as $a) {
            $emp = $c->mapped('employee', (int) $a->employee_id);
            $key = $emp.'-'.$a->date;
            if (! $emp || ! $a->date || isset($seen[$key])) {
                $c->count('attendances', ! $emp ? 'skipped: unknown employee' : 'skipped: duplicate day');

                continue;
            }
            $seen[$key] = true;
            $rows[] = ['employee_id' => $emp, 'date' => $a->date, 'status' => (int) $a->status === 1 ? 'present' : 'late',
                'check_in' => self::time($a->checkin), 'check_out' => self::time($a->checkout), 'recorded_by' => MasterData::userOr($c, $a->user_id),
                'created_at' => Context::ts($a->created_at, now()), 'updated_at' => now()];
            $c->count('attendances', 'imported');
        }
        $c->insert('attendances', $rows);

        foreach ($c->src->table('holidays')->where('is_approved', 1)->get() as $h) {
            foreach (CarbonPeriod::create($h->from_date, $h->to_date ?: $h->from_date) as $day) {
                $c->dst->table('holidays')->insert(['date' => $day->toDateString(), 'name' => Str::limit(trim((string) $h->note) ?: 'Holiday', 100, ''),
                    'warehouse_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                $c->count('holidays', 'days imported');
            }
        }

        $c->fact('payrolls', 'legacy payroll rows not imported (history in the legacy backup)', $c->src->table('payrolls')->count());
        $deducted = $c->src->table('payrolls')->groupBy('employee_id')->selectRaw('employee_id, SUM(advance) a')->pluck('a', 'employee_id');
        $advances = $c->src->table('advances_payments')->where('status', 'approved')->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(amount) a, MAX(payment_date) d')->get();
        foreach ($advances as $a) {
            $open = round((float) $a->a - (float) ($deducted[$a->employee_id] ?? 0), 2);
            $emp = $c->mapped('employee', (int) $a->employee_id);
            if ($open <= 0 || ! $emp) {
                $c->count('salary_advances', $emp ? 'fully recovered in legacy' : 'skipped: unknown employee');

                continue;
            }
            $c->dst->table('salary_advances')->insert([
                'employee_id' => $emp, 'amount' => Context::money($open), 'installment' => null, 'given_at' => $a->d ?: $c->cutoverDate,
                'account_id' => $c->cashAccount, 'status' => 'open', 'created_by' => $c->systemUser, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $c->count('salary_advances', 'open advance imported');
            $c->count('salary_advances', 'Σ open amount', $open);
        }
        $c->flush();
    }

    private static function time(?string $value): ?string
    {
        if (! $value || ! preg_match('/(\d{1,2}):(\d{2})\s*(am|pm)?/i', $value, $m)) {
            return null;
        }
        $h = (int) $m[1] % 24;
        if (isset($m[3]) && strtolower($m[3]) === 'pm' && $h < 12) {
            $h += 12;
        } elseif (isset($m[3]) && strtolower($m[3]) === 'am' && $h === 12) {
            $h = 0;
        }

        return sprintf('%02d:%s', $h, $m[2]);
    }
}
