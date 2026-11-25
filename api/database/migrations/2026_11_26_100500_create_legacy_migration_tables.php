<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping for the legacy → new data migration (`php artisan legacy:migrate`):
 * which legacy row became which new row, and the legacy rows that were not
 * imported, with the reason, for business review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legacy_id_map')) {
            Schema::create('legacy_id_map', function (Blueprint $t) {
                $t->id();
                $t->string('entity', 40);
                $t->unsignedBigInteger('legacy_id');
                $t->unsignedBigInteger('new_id');
                $t->string('source', 10)->default('etl');
                $t->timestamp('created_at')->nullable();
                $t->unique(['entity', 'legacy_id']);
                $t->index(['entity', 'new_id']);
            });
        }
        if (! Schema::hasTable('etl_rejects')) {
            Schema::create('etl_rejects', function (Blueprint $t) {
                $t->id();
                $t->string('entity', 40);
                $t->unsignedBigInteger('legacy_id')->nullable();
                $t->string('reason', 255);
                $t->json('payload')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['entity', 'reason']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('etl_rejects');
        Schema::dropIfExists('legacy_id_map');
    }
};
