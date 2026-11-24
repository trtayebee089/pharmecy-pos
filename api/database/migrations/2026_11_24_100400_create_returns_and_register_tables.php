<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale returns (per sold batch) and petty cash movements at the till (§3.7).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('session_id')->nullable()->constrained('cash_register_sessions')->restrictOnDelete();
            $t->string('refund_method', 20); // cash | bkash | credit
            $t->decimal('total', 14, 2);
            $t->unsignedInteger('restocked_qty_base')->default(0);
            $t->unsignedInteger('written_off_qty_base')->default(0);
            $t->string('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['warehouse_id', 'created_at']);
        });

        Schema::create('sale_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('sale_item_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('qty_base');
            $t->decimal('unit_refund', 14, 4); // per piece, after line and order discount
            $t->decimal('line_total', 14, 2);
            $t->boolean('restock');
            $t->timestamps();
        });

        Schema::create('cash_register_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('cash_register_sessions')->restrictOnDelete();
            $t->string('type', 10); // cash_in | cash_out
            $t->decimal('amount', 14, 2);
            $t->string('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_movements');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
