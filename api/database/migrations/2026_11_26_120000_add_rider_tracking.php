<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house rider app (owner decision 26 Nov 2026, Google Maps):
 *
 * - riders sign in to the rider app with phone + password (Sanctum token, ability `rider`)
 *   and report their position while on duty; the last position is kept on the rider and
 *   every point in `rider_locations` (the route of each delivery).
 * - branches have a map position (the "store" marker), orders have the customer's pin.
 * - orders record when the rider picked the parcel up and the latest arrival estimate.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $t) {
            $t->decimal('latitude', 10, 7)->nullable()->after('address');
            $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('riders', function (Blueprint $t) {
            $t->string('password')->nullable()->after('nid');
            $t->timestamp('last_login_at')->nullable();
            $t->boolean('on_duty')->default(false);
            $t->decimal('last_lat', 10, 7)->nullable();
            $t->decimal('last_lng', 10, 7)->nullable();
            $t->unsignedSmallInteger('last_accuracy')->nullable(); // metres
            $t->unsignedSmallInteger('last_heading')->nullable();  // degrees
            $t->decimal('last_speed', 6, 2)->nullable();           // m/s
            $t->timestamp('last_seen_at')->nullable();
        });

        Schema::table('online_orders', function (Blueprint $t) {
            $t->decimal('latitude', 10, 7)->nullable()->after('address');   // customer's pin
            $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $t->timestamp('picked_up_at')->nullable()->after('placed_at');
            $t->timestamp('eta_at')->nullable();
            $t->unsignedInteger('eta_seconds')->nullable();
            $t->unsignedInteger('eta_distance_m')->nullable();
            $t->string('eta_source', 10)->nullable(); // google | estimate
            $t->timestamp('eta_updated_at')->nullable();
        });

        Schema::create('rider_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rider_id')->constrained()->cascadeOnDelete();
            $t->foreignId('online_order_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->unsignedSmallInteger('accuracy')->nullable();
            $t->unsignedSmallInteger('heading')->nullable();
            $t->decimal('speed', 6, 2)->nullable();
            $t->timestamp('recorded_at');
            $t->index(['rider_id', 'recorded_at']);
            $t->index(['online_order_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_locations');
        Schema::table('online_orders', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude', 'picked_up_at', 'eta_at', 'eta_seconds', 'eta_distance_m', 'eta_source', 'eta_updated_at']));
        Schema::table('riders', fn (Blueprint $t) => $t->dropColumn(['password', 'last_login_at', 'on_duty', 'last_lat', 'last_lng', 'last_accuracy', 'last_heading', 'last_speed', 'last_seen_at']));
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
    }
};
