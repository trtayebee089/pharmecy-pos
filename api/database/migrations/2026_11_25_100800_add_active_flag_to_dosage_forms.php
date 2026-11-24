<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dosage forms can be deactivated like the other catalog masters (hidden from
 * product forms, kept on existing products). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dosage_forms', function (Blueprint $t) {
            $t->boolean('is_active')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('dosage_forms', function (Blueprint $t) {
            $t->dropColumn('is_active');
        });
    }
};
