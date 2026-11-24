<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation, settings and platform tables (docs/rebuild/database-design.md §3.1–3.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('type', 20)->default('store'); // store | warehouse
            $t->string('address')->nullable();
            $t->string('phone', 30)->nullable();
            $t->text('invoice_header')->nullable();
            $t->text('invoice_footer')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('username', 50)->unique()->after('name');
            $t->string('phone', 30)->nullable()->after('email');
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('last_login_at')->nullable();
            $t->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $t->softDeletes();
        });

        Schema::create('user_warehouses', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->primary(['user_id', 'warehouse_id']);
        });

        Schema::create('role_limits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->unique();
            $t->decimal('max_line_discount_pct', 5, 2)->default(0);
            $t->decimal('max_order_discount_pct', 5, 2)->default(0);
            $t->boolean('can_override_batch')->default(false);
            $t->boolean('can_sell_below_cost')->default(false);
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 20)->default('global'); // global | warehouse
            $t->unsignedBigInteger('scope_id')->default(0);
            $t->string('key', 100);
            $t->json('value')->nullable();
            $t->timestamps();
            $t->unique(['scope', 'scope_id', 'key']);
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('doc_type', 30);
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedSmallInteger('year');
            $t->string('prefix', 30);
            $t->unsignedBigInteger('next_number')->default(1);
            $t->timestamps();
            $t->unique(['doc_type', 'warehouse_id', 'year']);
        });

        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('key', 100);
            $t->string('endpoint', 150);
            $t->string('request_hash', 64);
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->json('response_body')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 60);
            $t->string('subject_type', 60);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('role_limits');
        Schema::dropIfExists('user_warehouses');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('default_warehouse_id');
            $t->dropColumn(['username', 'phone', 'is_active', 'must_change_password', 'last_login_at', 'deleted_at']);
        });
        Schema::dropIfExists('warehouses');
    }
};
