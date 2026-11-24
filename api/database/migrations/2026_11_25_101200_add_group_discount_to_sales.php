<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The customer group discount that applied to a sale (audit and reports). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->decimal('group_discount_pct', 5, 2)->default(0)->after('order_discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->dropColumn('group_discount_pct');
        });
    }
};
