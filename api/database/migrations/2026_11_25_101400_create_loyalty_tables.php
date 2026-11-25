<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty (§3.9): one append-only reward ledger with a wallet projection,
 * membership plans and customer memberships, point campaigns, and the
 * points/membership figures stored on each sale. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('type', 10); // opening | earn | redeem | expire | adjust | reverse
            $t->integer('points'); // signed
            $t->decimal('value', 14, 2)->default(0); // taka value for redeem rows
            // Points of a credit row (earn, positive adjust/reverse) not yet redeemed or expired: FIFO by expiry.
            $t->integer('remaining')->default(0);
            $t->date('expires_at')->nullable();
            $t->string('source_type', 30)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['customer_id', 'created_at']);
            $t->index(['customer_id', 'remaining', 'expires_at']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('reward_wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $t->integer('balance')->default(0);
            $t->unsignedInteger('lifetime_earned')->default(0);
            $t->unsignedInteger('lifetime_redeemed')->default(0);
            $t->timestamps();
        });

        Schema::create('membership_plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique(); // Silver | Gold | Platinum
            $t->string('name', 60);
            $t->decimal('min_annual_spend', 14, 2)->default(0);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('points_multiplier', 4, 2)->default(1);
            $t->decimal('fee', 14, 2)->default(0);
            $t->unsignedSmallInteger('duration_months')->default(12);
            $t->json('benefits')->nullable();
            $t->boolean('is_active')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('customer_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('plan_id')->constrained('membership_plans')->restrictOnDelete();
            $t->date('starts_at');
            $t->date('ends_at');
            $t->string('status', 10)->default('active'); // active | expired | cancelled
            $t->string('source', 10); // auto | purchase | manual
            $t->string('reason')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['customer_id', 'status']);
        });

        Schema::create('reward_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->decimal('multiplier', 4, 2)->default(1);
            $t->date('starts_at');
            $t->date('ends_at');
            $t->boolean('is_active')->default(true);
            $t->string('description')->nullable();
            $t->timestamps();
        });

        Schema::table('sales', function (Blueprint $t) {
            $t->decimal('membership_discount_pct', 5, 2)->default(0)->after('group_discount_pct');
            $t->unsignedInteger('points_redeemed')->default(0)->after('due_total');
            $t->decimal('points_value', 14, 2)->default(0)->after('points_redeemed');
            $t->unsignedInteger('points_earned')->default(0)->after('points_value');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->dropColumn(['membership_discount_pct', 'points_redeemed', 'points_value', 'points_earned']);
        });
        foreach (['reward_campaigns', 'customer_memberships', 'membership_plans', 'reward_wallets', 'reward_ledger'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
