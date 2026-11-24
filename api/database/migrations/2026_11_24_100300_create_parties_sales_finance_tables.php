<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parties (§3.5), purchasing core (§3.6), sales & POS (§3.7), payments & ledgers (§3.8).
 * Money: DECIMAL(14,2) amounts, DECIMAL(14,4) unit prices/costs, INT qty_base (pieces).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('phone', 20)->nullable();
            $t->string('phone_normalized', 20)->nullable()->unique();
            $t->string('email')->nullable();
            $t->string('gender', 10)->nullable();
            $t->foreignId('customer_group_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('credit_limit', 14, 2)->nullable();
            $t->boolean('is_walk_in')->default(false);
            $t->boolean('is_active')->default(true);
            $t->string('address')->nullable();
            $t->unsignedBigInteger('legacy_id')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
            $t->index('name');
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('company_name')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('legacy_id')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('type', 20); // cash | bank | mobile_wallet
            $t->string('account_no', 60)->nullable();
            $t->decimal('opening_balance', 14, 2)->default(0);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique(); // cash | card | bkash | nagad | rocket | bank | customer_credit | points
            $t->string('name', 60);
            $t->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->boolean('requires_reference')->default(false);
            $t->timestamps();
        });

        // ---- Purchasing core ----
        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('supplier_invoice_no', 60)->nullable();
            $t->date('invoice_date')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->string('status', 20)->default('received'); // ordered | received | void
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('discount_total', 14, 2)->default(0);
            $t->decimal('tax_total', 14, 2)->default(0);
            $t->decimal('other_charges', 14, 2)->default(0);
            $t->decimal('grand_total', 14, 2)->default(0);
            $t->decimal('paid_total', 14, 2)->default(0);
            $t->decimal('due_total', 14, 2)->default(0);
            $t->text('note')->nullable();
            $t->boolean('is_legacy')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'invoice_date']);
            $t->index(['warehouse_id', 'received_at']);
        });
        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 14, 3);
            $t->unsignedInteger('factor');
            $t->unsignedInteger('qty_base');
            $t->unsignedInteger('free_qty_base')->default(0);
            $t->string('batch_no', 60)->nullable();
            $t->date('expiry_date')->nullable();
            $t->date('mfg_date')->nullable();
            $t->decimal('unit_cost', 14, 4);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('line_total', 14, 2);
            $t->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamps();
        });

        // ---- Sales & POS ----
        Schema::create('cash_registers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('name', 60);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('cash_register_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $t->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('opened_at');
            $t->decimal('opening_float', 14, 2)->default(0);
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->decimal('expected_cash', 14, 2)->nullable();
            $t->decimal('counted_cash', 14, 2)->nullable();
            $t->decimal('difference', 14, 2)->nullable();
            $t->string('status', 10)->default('open');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index(['cash_register_id', 'status']);
        });

        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->string('channel', 20)->default('pos'); // pos | manual | website | app
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('session_id')->nullable()->constrained('cash_register_sessions')->restrictOnDelete();
            $t->string('status', 20)->default('completed'); // held | completed | void
            $t->timestamp('sale_date');
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('line_discount_total', 14, 2)->default(0);
            $t->decimal('order_discount_total', 14, 2)->default(0);
            $t->decimal('tax_total', 14, 2)->default(0);
            $t->decimal('shipping_total', 14, 2)->default(0);
            $t->decimal('rounding', 14, 2)->default(0);
            $t->decimal('grand_total', 14, 2)->default(0);
            $t->decimal('paid_total', 14, 2)->default(0);
            $t->decimal('change_total', 14, 2)->default(0);
            $t->decimal('due_total', 14, 2)->default(0);
            $t->string('payment_status', 10)->default('unpaid'); // unpaid | partial | paid
            $t->decimal('cost_total', 14, 2)->default(0);
            $t->text('note')->nullable();
            $t->boolean('is_legacy')->default(false);
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['warehouse_id', 'sale_date']);
            $t->index(['customer_id', 'sale_date']);
            $t->index(['status', 'sale_date']);
        });
        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->string('line_type', 10)->default('product'); // product | misc
            $t->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('product_unit_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('description');
            $t->string('misc_code', 60)->nullable();
            $t->decimal('qty', 14, 3);
            $t->unsignedInteger('factor')->default(1);
            $t->unsignedInteger('qty_base')->default(0);
            $t->decimal('unit_price', 14, 4);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('discount_total', 14, 2)->default(0);
            $t->decimal('tax_total', 14, 2)->default(0);
            $t->decimal('line_total', 14, 2);
            $t->decimal('cost_total', 14, 2)->default(0);
            $t->timestamps();
            $t->index('product_id');
        });
        Schema::create('sale_item_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('qty_base');
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->unsignedInteger('returned_qty_base')->default(0);
            $t->timestamps();
        });

        // ---- Payments & ledgers ----
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->string('direction', 5); // in | out
            $t->string('party_type', 20)->nullable(); // customer | supplier
            $t->unsignedBigInteger('party_id')->nullable();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('reference', 100)->nullable();
            $t->timestamp('paid_at');
            $t->foreignId('session_id')->nullable()->constrained('cash_register_sessions')->restrictOnDelete();
            $t->string('status', 10)->default('posted');
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['party_type', 'party_id', 'paid_at']);
            $t->index(['account_id', 'paid_at']);
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $t->string('allocatable_type', 30); // sale | purchase | sale_return | purchase_return
            $t->unsignedBigInteger('allocatable_id');
            $t->decimal('amount', 14, 2);
            $t->timestamps();
            $t->index(['allocatable_type', 'allocatable_id']);
        });
        Schema::create('account_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->date('txn_date');
            $t->string('direction', 5); // in | out
            $t->decimal('amount', 14, 2);
            $t->string('source_type', 40);
            $t->unsignedBigInteger('source_id');
            $t->string('note')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['account_id', 'txn_date']);
        });
        Schema::create('party_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->string('party_type', 20); // customer | supplier
            $t->unsignedBigInteger('party_id');
            $t->date('entry_date');
            $t->string('type', 20); // opening | invoice | payment | return | refund | adjustment | void
            $t->decimal('debit', 14, 2)->default(0);
            $t->decimal('credit', 14, 2)->default(0);
            $t->string('source_type', 40)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['party_type', 'party_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        foreach (['party_ledger_entries', 'account_transactions', 'payment_allocations', 'payments', 'sale_item_batches', 'sale_items', 'sales', 'cash_register_sessions', 'cash_registers', 'purchase_items', 'purchases', 'payment_methods', 'accounts', 'suppliers', 'customers', 'customer_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
