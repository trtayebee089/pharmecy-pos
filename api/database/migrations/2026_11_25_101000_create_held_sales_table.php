<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Held (parked) sales: a cart saved on the server so any counter in the
 * warehouse can resume it. No stock is reserved and no money is posted; the
 * sale is priced and allocated when it is completed. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_sales', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('session_id')->nullable()->constrained('cash_register_sessions')->nullOnDelete();
            $t->decimal('order_discount', 14, 2)->default(0);
            $t->json('lines'); // [{productId, unitId, qty, discountPct, batchId?, custom?}]
            $t->string('note')->nullable();
            $t->string('status', 10)->default('held'); // held | resumed | discarded
            $t->foreignId('held_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_sales');
    }
};
