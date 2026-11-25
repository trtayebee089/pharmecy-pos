<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Units sold per product, for "popular" sorting on the website and app.
 * Summing all sale lines on every page view is too slow on real data
 * (hundreds of thousands of lines); Storefront refreshes this hourly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales_stats', function (Blueprint $t) {
            $t->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('units_sold')->default(0);
            $t->index('units_sold');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_stats');
    }
};
