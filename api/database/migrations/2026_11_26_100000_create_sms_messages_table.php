<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $t) {
            $t->id();
            $t->string('to', 20);
            $t->text('message');
            $t->string('purpose', 30); // test | due_reminder | order_update | sale | password_reset
            $t->string('status', 10); // sent | failed
            $t->string('provider', 40)->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->text('response')->nullable();
            $t->string('error')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['purpose', 'created_at']);
            $t->index('to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
