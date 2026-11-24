<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A received purchase (GRN) can fulfil an earlier purchase order. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->foreignId('order_id')->nullable()->after('status')->constrained('purchases')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropConstrainedForeignId('order_id');
        });
    }
};
