<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Admin › Roles shows a description per role. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $t) {
            $t->string('description')->nullable()->after('guard_name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $t) {
            $t->dropColumn('description');
        });
    }
};
