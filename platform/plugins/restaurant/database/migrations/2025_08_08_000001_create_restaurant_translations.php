<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('restaurant_menu_items_translations')) {
            Schema::create('restaurant_menu_items_translations', function (Blueprint $table): void {
                $table->string('lang_code', 20);
                $table->foreignId('restaurant_menu_items_id');
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->text('ingredients')->nullable();

                $table->primary(['lang_code', 'restaurant_menu_items_id'], 'restaurant_menu_items_translations_primary');
            });
        }

        if (! Schema::hasTable('restaurant_menu_categories_translations')) {
            Schema::create('restaurant_menu_categories_translations', function (Blueprint $table): void {
                $table->string('lang_code', 20);
                $table->foreignId('restaurant_menu_categories_id');
                $table->string('name')->nullable();
                $table->text('description')->nullable();

                $table->primary(['lang_code', 'restaurant_menu_categories_id'], 'restaurant_menu_categories_translations_primary');
            });
        }

        if (! Schema::hasTable('restaurant_chefs_translations')) {
            Schema::create('restaurant_chefs_translations', function (Blueprint $table): void {
                $table->string('lang_code', 20);
                $table->foreignId('restaurant_chefs_id');
                $table->string('name')->nullable();
                $table->string('title')->nullable();
                $table->text('bio')->nullable();

                $table->primary(['lang_code', 'restaurant_chefs_id'], 'restaurant_chefs_translations_primary');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_menu_items_translations');
        Schema::dropIfExists('restaurant_menu_categories_translations');
        Schema::dropIfExists('restaurant_chefs_translations');
    }
};
