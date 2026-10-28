<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhu cầu Night theo ngày (quản lý 28/10/2026): ngày ít khách 2 nam waiter, ngày đông khách 3 – quản lý chọn trên trang Nhân sự Night.
 * Ngày chưa chọn dùng mặc định (định biên Night đang áp dụng).
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('roster_night_demands')) {
            return;
        }

        Schema::create('roster_night_demands', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedTinyInteger('staff');
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_night_demands');
    }
};
