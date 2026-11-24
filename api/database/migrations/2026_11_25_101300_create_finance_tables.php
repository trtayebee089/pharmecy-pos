<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance documents: money transfers between accounts, expense and income
 * categories and entries. Every money change is an account_transactions row;
 * documents are voided, never deleted. Expenses and incomes share one table
 * (kind) instead of the two in the design doc: same columns and rules.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->date('transfer_date');
            $t->string('note')->nullable();
            $t->string('status', 10)->default('posted'); // posted | void
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('finance_categories', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10); // expense | income
            $t->string('name', 100);
            $t->foreignId('parent_id')->nullable()->constrained('finance_categories')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['kind', 'name']);
        });

        Schema::create('finance_entries', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10); // expense | income
            $t->string('number', 40)->unique();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->date('entry_date');
            $t->string('note')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('status', 10)->default('posted'); // posted | void
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['kind', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('money_transfers');
    }
};
