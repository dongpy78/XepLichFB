<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('restaurant_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique()->index();
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->string('image')->nullable();
            $table->foreignId('category_id');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_vegetarian')->default(false);
            $table->boolean('is_vegan')->default(false);
            $table->boolean('is_gluten_free')->default(false);
            $table->string('calories')->nullable();
            $table->text('ingredients')->nullable();
            $table->integer('order')->default(0);
            $table->string('status', 60)->default('published');
            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_menu_items');
    }
};
