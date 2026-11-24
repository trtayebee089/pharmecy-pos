<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog (§3.3) and batch-level inventory (§3.4).
 * Stock = append-only stock_movements + stock_balances projection per (warehouse, batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('short_name', 60)->nullable();
            $t->string('country', 60)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('legacy_id')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('generics', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('therapeutic_class')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('dosage_forms', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $t->string('slug')->unique();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('tax_rates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->decimal('rate', 5, 2);
            $t->boolean('is_inclusive')->default(true);
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 50)->unique();
            $t->string('name');
            $t->string('strength', 100)->nullable();
            $t->foreignId('generic_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('dosage_form_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('manufacturer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('tax_rate_id')->nullable()->constrained()->restrictOnDelete();
            $t->text('description')->nullable();
            $t->boolean('requires_prescription')->default(false);
            $t->boolean('is_batch_tracked')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('reorder_level_base')->default(0);
            $t->unsignedInteger('reorder_qty_base')->default(0);
            $t->string('image_path')->nullable();
            $t->string('slug')->nullable()->unique();
            $t->unsignedBigInteger('legacy_id')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
            $t->index('name');
        });

        Schema::create('product_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('unit_code', 20); // piece | strip | box | bottle | tube | vial
            $t->string('label', 60);
            $t->unsignedInteger('factor'); // pieces per unit
            $t->boolean('is_base')->default(false);
            $t->boolean('is_sale_default')->default(false);
            $t->boolean('is_purchase_default')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['product_id', 'unit_code']);
        });

        Schema::create('product_barcodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_unit_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('barcode', 64)->unique();
            $t->timestamps();
        });

        Schema::create('price_lists', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique(); // retail | wholesale
            $t->string('name', 60);
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('price_list_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_unit_id')->constrained()->cascadeOnDelete();
            $t->decimal('price', 14, 4);
            $t->timestamp('effective_from')->useCurrent();
            $t->timestamp('effective_to')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['product_unit_id', 'price_list_id', 'effective_from']);
        });

        Schema::create('batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('batch_no', 60);
            $t->date('expiry_date')->nullable(); // NULL only for legacy opening batches
            $t->date('mfg_date')->nullable();
            $t->string('status', 20)->default('active'); // active | quarantined | recalled
            $t->boolean('is_legacy_opening')->default(false);
            $t->timestamps();
            $t->unique(['product_id', 'batch_no']);
            $t->index(['product_id', 'expiry_date']);
        });

        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->integer('qty_base')->default(0);
            $t->decimal('avg_unit_cost', 14, 4)->default(0);
            $t->timestamps();
            $t->unique(['warehouse_id', 'batch_id']);
            $t->index(['warehouse_id', 'product_id']);
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained()->restrictOnDelete();
            $t->integer('qty_base'); // signed
            $t->string('type', 30); // opening | purchase | purchase_return | sale | sale_return | adjustment | transfer_out | transfer_in | count | damage | expiry_writeoff
            $t->decimal('unit_cost', 14, 4)->default(0);
            $t->string('source_type', 40)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('source_line_id')->nullable();
            $t->string('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at', 6)->useCurrent();
            $t->index(['product_id', 'created_at']);
            $t->index(['warehouse_id', 'created_at']);
            $t->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'stock_balances', 'batches', 'product_prices', 'price_lists', 'product_barcodes', 'product_units', 'products', 'tax_rates', 'categories', 'dosage_forms', 'generics', 'manufacturers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
