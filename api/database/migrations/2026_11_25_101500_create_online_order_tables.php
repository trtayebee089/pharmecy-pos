<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online orders and delivery (W17): shipping zones, riders, orders with lines
 * and status history, and the rider cash ledger for cash on delivery. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $t) {
            $t->id();
            $t->string('division', 60);
            $t->string('district', 60);
            $t->string('thana', 80);
            $t->decimal('rate', 14, 2)->default(0);
            $t->decimal('free_shipping_min', 14, 2)->nullable();
            $t->string('partner', 20)->default('own_rider'); // own_rider | pathao | steadfast | redx
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['division', 'district', 'thana'], 'shipping_zones_area_unique');
        });

        Schema::create('riders', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('phone', 20);
            $t->string('nid', 30)->nullable();
            $t->string('status', 10)->default('active'); // active | on_leave | inactive
            $t->timestamps();
        });

        Schema::create('online_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->string('tracking_code', 20)->unique();
            $t->string('legacy_ref', 60)->nullable()->index();
            $t->string('channel', 10); // phone | website | app
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('phone', 20);
            $t->string('address');
            $t->foreignId('zone_id')->nullable()->constrained('shipping_zones')->nullOnDelete();
            $t->string('division', 60);
            $t->string('district', 60);
            $t->string('thana', 80);
            $t->string('status', 12)->default('pending'); // pending | confirmed | packed | in_transit | delivered | cancelled
            $t->decimal('subtotal', 14, 2);
            $t->decimal('shipping', 14, 2)->default(0);
            $t->decimal('grand_total', 14, 2);
            $t->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('rider_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('placed_at');
            $t->timestamp('delivered_at')->nullable();
            $t->string('note')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'placed_at']);
        });

        Schema::create('online_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('online_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $t->string('description');
            $t->decimal('qty', 14, 3);
            $t->unsignedInteger('qty_base');
            $t->decimal('unit_price', 14, 4); // agreed at placement (server price list)
            $t->decimal('line_total', 14, 2);
            $t->timestamps();
        });

        Schema::create('online_order_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('online_order_id')->constrained()->cascadeOnDelete();
            $t->string('status', 12);
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        // Cash a rider collected on delivery and handed over: held = collect − handover.
        Schema::create('rider_cash_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rider_id')->constrained()->restrictOnDelete();
            $t->string('type', 10); // collect | handover
            $t->decimal('amount', 14, 2);
            $t->foreignId('online_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['rider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['rider_cash_entries', 'online_order_history', 'online_order_lines', 'online_orders', 'riders', 'shipping_zones'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
