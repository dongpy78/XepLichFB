<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('restaurant_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->date('reservation_date');
            $table->time('reservation_time');
            $table->integer('number_of_guests');
            $table->text('special_requests')->nullable();
            $table->string('status', 60)->default('pending'); // pending, confirmed, cancelled, completed
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['reservation_date', 'reservation_time']);
            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_reservations');
    }
};
