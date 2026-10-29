<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hiển thị chung Villa + Gusto trên Bảng xếp ca theo tuần (quản lý 29/10/2026): chỉ đổi cách hiển thị – dồn nhân viên hai nhà hàng
 * vào một khu cho dễ nhìn và để mọi người cùng thấy. Không ảnh hưởng thuật toán xếp lịch, outlet chính, lịch đã xếp.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('roster_grid_merges')) {
            return;
        }

        Schema::create('roster_grid_merges', function (Blueprint $table): void {
            $table->id();
            $table->date('week_start')->unique();
            // Khu hiển thị chung (outlet nhà hàng đang hoạt động, vd Villa)
            $table->foreignId('outlet_id');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_grid_merges');
    }
};
