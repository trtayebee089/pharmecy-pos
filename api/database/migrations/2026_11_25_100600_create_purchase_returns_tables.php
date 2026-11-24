<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase returns (stock back to the supplier, per batch) and the part of a
 * return's credit that reduced open purchase invoices. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('status', 10)->default('posted'); // posted | void
            $t->string('settlement', 12); // reduce_due | refund
            $t->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete(); // refund received
            $t->string('credit_note_no', 60)->nullable();
            $t->string('reason');
            $t->decimal('total', 14, 2);
            $t->unsignedInteger('qty_base');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'created_at']);
            $t->index(['warehouse_id', 'created_at']);
        });

        Schema::create('purchase_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_item_id')->nullable()->constrained()->restrictOnDelete(); // receipt the batch came from
            $t->unsignedInteger('qty_base');
            $t->decimal('unit_cost', 14, 4);
            $t->decimal('line_total', 14, 2);
            $t->timestamps();
        });

        Schema::create('purchase_return_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->timestamps();
        });

        Schema::table('purchases', function (Blueprint $t) {
            // due_total = grand_total − paid_total − returned_total
            $t->decimal('returned_total', 14, 2)->default(0)->after('paid_total');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropColumn('returned_total');
        });
        Schema::dropIfExists('purchase_return_allocations');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
