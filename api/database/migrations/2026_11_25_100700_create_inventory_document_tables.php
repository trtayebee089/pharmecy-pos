<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory documents (§ stock in the database design): adjustments, stock
 * counts and transfers between warehouses. Every stock change they make goes
 * through StockLedger. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('reason', 20); // damage | expiry | correction | lost | found
            $t->string('note');
            $t->string('status', 10)->default('posted'); // posted | void
            $t->unsignedInteger('qty_in')->default(0);
            $t->unsignedInteger('qty_out')->default(0);
            $t->decimal('value', 14, 2)->default(0); // Σ |qty| × unit cost
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['warehouse_id', 'created_at']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->integer('qty_base'); // signed: + in, − out
            $t->decimal('unit_cost', 14, 4);
            $t->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->string('scope_type', 20); // all | dosage | manufacturer | category
            $t->string('scope_value', 120)->nullable();
            $t->string('status', 12)->default('counting'); // counting | posted | cancelled
            $t->integer('variance_qty')->default(0);
            $t->decimal('variance_value', 14, 2)->default(0);
            $t->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamps();
            $t->index(['warehouse_id', 'status']);
        });

        Schema::create('stock_count_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('start_qty_base'); // system quantity when the count started
            $t->unsignedInteger('counted_qty_base')->nullable();
            $t->unsignedInteger('system_qty_base')->nullable(); // at posting
            $t->integer('difference')->nullable(); // counted − system at posting
            $t->decimal('unit_cost', 14, 4)->nullable();
            $t->timestamps();
            $t->unique(['stock_count_id', 'batch_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('number', 40)->unique();
            $t->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->string('status', 10)->default('sent'); // sent | received | void
            $t->string('note')->nullable();
            $t->unsignedInteger('qty_base');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('received_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['from_warehouse_id', 'status']);
            $t->index(['to_warehouse_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('qty_base');
            $t->decimal('unit_cost', 14, 4);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
