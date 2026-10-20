<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('restaurant_chefs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title')->nullable();
            $table->text('bio')->nullable();
            $table->string('image')->nullable();
            $table->string('badge')->nullable();
            $table->integer('experience_years')->nullable();
            $table->json('specialties')->nullable();
            $table->json('social_links')->nullable();
            $table->integer('order')->default(0);
            $table->string('status', 60)->default('published');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_chefs');
    }
};
