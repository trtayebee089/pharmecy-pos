<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Customer groups get notes (who qualifies, how it is verified). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $t) {
            $t->text('notes')->nullable()->after('discount_pct');
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $t) {
            $t->dropColumn('notes');
        });
    }
};
