<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR & payroll (§3.10): departments, designations, employees, holidays,
 * attendance, payroll runs with payslips, salary advances and their recoveries.
 * finance_entries gets a source link so a payroll's salary expense cannot be
 * voided on its own. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
            $t->timestamps();
        });

        Schema::create('designations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->string('name', 80);
            $t->timestamps();
            $t->unique(['department_id', 'name']);
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name', 120);
            $t->string('phone', 20);
            $t->string('email', 120)->nullable();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('designation_id')->constrained()->restrictOnDelete();
            $t->date('join_date');
            $t->decimal('basic_salary', 14, 2);
            $t->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('name', 100);
            $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete(); // null = all branches
            $t->timestamps();
            $t->index('date');
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->string('status', 10); // present | absent | leave | late
            $t->string('check_in', 5)->nullable();
            $t->string('check_out', 5)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'date']);
            $t->index('date');
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->unique(); // YYYY-MM
            $t->string('status', 10)->default('draft'); // draft | approved | paid
            $t->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('expense_entry_id')->nullable()->constrained('finance_entries')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('paid_at')->nullable();
            $t->timestamps();
        });

        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->decimal('basic', 14, 2);
            $t->decimal('additions', 14, 2)->default(0);
            $t->string('addition_note')->nullable();
            $t->unsignedSmallInteger('absent_days')->default(0);
            $t->unsignedSmallInteger('late_days')->default(0);
            $t->unsignedSmallInteger('present_days')->default(0);
            $t->unsignedSmallInteger('working_days')->default(0);
            $t->decimal('deductions', 14, 2)->default(0);
            $t->decimal('advance_recovery', 14, 2)->default(0);
            $t->decimal('net', 14, 2);
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('salary_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->decimal('installment', 14, 2)->nullable(); // monthly recovery (installment mode)
            $t->date('given_at');
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->string('status', 10)->default('open'); // open | recovered | void
            $t->string('void_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('advance_recoveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('salary_advance_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('source', 10); // payroll | cash
            $t->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::table('finance_entries', function (Blueprint $t) {
            $t->string('source_type', 30)->nullable()->after('note');
            $t->unsignedBigInteger('source_id')->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('finance_entries', function (Blueprint $t) {
            $t->dropColumn(['source_type', 'source_id']);
        });
        foreach (['advance_recoveries', 'salary_advances', 'payslips', 'payroll_runs', 'attendances', 'holidays', 'employees', 'designations', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
