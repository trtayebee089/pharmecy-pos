<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer channel (website and app, /api/v1): customer sign-in, password
 * reset codes, account removal requests, and loyalty on online orders
 * (customer discount and points redeemed by signed-in customers). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('password')->nullable()->after('email'); // null = no web/app sign-in yet
            $t->timestamp('last_login_at')->nullable()->after('password');
            $t->string('avatar_path')->nullable()->after('address');
        });

        Schema::create('customer_password_resets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('code_hash');
            $t->timestamp('expires_at');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['customer_id', 'created_at']);
        });

        Schema::create('account_removal_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('reason', 500)->nullable();
            $t->string('status', 12)->default('pending'); // pending | done | rejected
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
        });

        Schema::table('online_orders', function (Blueprint $t) {
            $t->decimal('discount_pct', 7, 4)->default(0)->after('subtotal');
            $t->decimal('discount_total', 14, 2)->default(0)->after('discount_pct');
            $t->unsignedInteger('points_redeemed')->default(0)->after('grand_total');
            $t->decimal('points_value', 14, 2)->default(0)->after('points_redeemed');
            // Retry-safe checkout: sha256(phone|Idempotency-Key) of the request that placed the order.
            $t->string('client_key', 64)->nullable()->unique()->after('legacy_ref');
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $t) {
            $t->dropUnique(['client_key']);
            $t->dropColumn(['discount_pct', 'discount_total', 'points_redeemed', 'points_value', 'client_key']);
        });
        Schema::dropIfExists('account_removal_requests');
        Schema::dropIfExists('customer_password_resets');
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['password', 'last_login_at', 'avatar_path']);
        });
    }
};
