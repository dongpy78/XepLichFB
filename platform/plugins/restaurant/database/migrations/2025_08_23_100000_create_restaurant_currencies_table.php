<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('restaurant_currencies')) {
            Schema::create('restaurant_currencies', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->string('symbol', 10);
                $table->tinyInteger('is_prefix_symbol')->unsigned()->default(0);
                $table->tinyInteger('decimals')->unsigned()->default(0)->nullable();
                $table->integer('order')->default(0)->unsigned()->nullable();
                $table->tinyInteger('is_default')->default(0);
                $table->double('exchange_rate')->default(1);
                $table->timestamps();
            });
        }

        // Add currency fields to relevant tables
        if (! Schema::hasColumn('restaurant_menu_items', 'currency_id')) {
            Schema::table('restaurant_menu_items', function (Blueprint $table): void {
                $table->foreignId('currency_id')->nullable()->after('price');
            });
        }

        if (! Schema::hasColumn('restaurant_reservations', 'currency_id')) {
            Schema::table('restaurant_reservations', function (Blueprint $table): void {
                $table->foreignId('currency_id')->nullable()->after('number_of_guests');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_menu_items', 'currency_id')) {
            Schema::table('restaurant_menu_items', function (Blueprint $table): void {
                $table->dropColumn('currency_id');
            });
        }

        if (Schema::hasColumn('restaurant_reservations', 'currency_id')) {
            Schema::table('restaurant_reservations', function (Blueprint $table): void {
                $table->dropColumn('currency_id');
            });
        }

        Schema::dropIfExists('restaurant_currencies');
    }
};
