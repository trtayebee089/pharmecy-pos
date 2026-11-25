<?php

use App\Models\Account;
use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->string('courier', 20)->nullable()->after('rider_id'); // steadfast | pathao
            $t->string('courier_consignment_id', 60)->nullable()->after('courier');
            $t->string('courier_tracking_code', 60)->nullable()->after('courier_consignment_id');
            $t->string('courier_status', 60)->nullable()->after('courier_tracking_code');
            $t->timestamp('courier_status_at')->nullable()->after('courier_status');
            $t->decimal('courier_fee', 10, 2)->nullable()->after('courier_status_at');
            $t->index(['courier', 'courier_consignment_id']);
        });

        // Pathao needs its own city / zone / area ids; remembered per delivery zone after the first booking.
        Schema::table('shipping_zones', function (Blueprint $t) {
            $t->unsignedInteger('pathao_city_id')->nullable();
            $t->unsignedInteger('pathao_zone_id')->nullable();
            $t->unsignedInteger('pathao_area_id')->nullable();
        });

        // Cash a courier collects is owed by the courier until it pays out (then a money transfer to the bank).
        if (Schema::hasTable('accounts') && Account::query()->exists()) {
            $acc = Account::firstOrCreate(['code' => 'COURIER'], ['name' => 'Courier COD receivable', 'type' => 'bank']);
            PaymentMethod::firstOrCreate(['code' => 'courier_cod'], ['name' => 'Courier COD', 'account_id' => $acc->id, 'requires_reference' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropIndex(['courier', 'courier_consignment_id']);
            $t->dropColumn(['courier', 'courier_consignment_id', 'courier_tracking_code', 'courier_status', 'courier_status_at', 'courier_fee']);
        });
        Schema::table('shipping_zones', function (Blueprint $t) {
            $t->dropColumn(['pathao_city_id', 'pathao_zone_id', 'pathao_area_id']);
        });
    }
};
