<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_requests', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique(); // MR-26-000001
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // signed-in customer
            $t->string('name', 120);
            $t->string('phone', 20);
            $t->string('address', 255)->nullable();
            $t->text('medicines');
            $t->json('files')->nullable(); // [{path, name, mime, size}] on the private disk
            $t->string('status', 12)->default('new'); // new | contacted | ordered | closed
            $t->string('staff_note', 500)->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_requests');
    }
};
