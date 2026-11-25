<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_banners', function (Blueprint $t) {
            $t->id();
            $t->string('placement', 10); // hero (wide, rotates) | tile (three below it)
            $t->string('title', 120);
            $t->string('subtitle', 255)->nullable();
            $t->string('button_text', 40)->nullable();
            $t->string('link_url', 500)->nullable();
            $t->string('image_path');
            $t->string('mobile_image_path')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['placement', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_banners');
    }
};
