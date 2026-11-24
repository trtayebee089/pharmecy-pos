<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase order planning per company (legacy product_order_quantity_alert):
 * draft orders per manufacturer and date, and the "drafts for all companies"
 * runs. A draft carries no stock or money; converting it creates an ordered
 * purchase for a supplier. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_drafts', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('manufacturer_id')->constrained()->restrictOnDelete();
            $t->date('order_date');
            $t->string('source', 10)->default('manual'); // manual | ai
            $t->string('status', 10)->default('draft'); // draft | converted
            $t->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // null = automatic run
            $t->timestamps();
            $t->index(['warehouse_id', 'manufacturer_id', 'order_date'], 'po_drafts_wh_mfr_date_index'); // generated name exceeds 64 chars
        });

        Schema::create('purchase_order_draft_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('draft_id')->constrained('purchase_order_drafts')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('order_qty'); // pieces
            $t->unsignedInteger('sold_qty')->default(0); // snapshot when planned
            $t->unsignedInteger('in_stock')->default(0); // snapshot when planned
            $t->decimal('unit_cost', 14, 4)->default(0); // per piece
            $t->json('forecast')->nullable();
            $t->timestamps();
            $t->unique(['draft_id', 'product_id']);
        });

        Schema::create('purchase_order_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->json('queue'); // manufacturer ids still to process
            $t->unsignedInteger('total');
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('created')->default(0);
            $t->string('status', 10)->default('running'); // running | done
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_runs');
        Schema::dropIfExists('purchase_order_draft_lines');
        Schema::dropIfExists('purchase_order_drafts');
    }
};
