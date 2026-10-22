<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Định biên nữ waiter làm trực tiếp tại outlet (không tính người đang hỗ trợ Lobby (L) hay đứng Kid-Deline trong ca):
 * tối thiểu / mục tiêu theo outlet × ca × thứ / ngày. Mặc định 0 = chưa áp dụng – quản lý nhập theo vận hành thực tế.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_staffing_requirements', function (Blueprint $table): void {
            $table->unsignedTinyInteger('min_female')->default(0)->after('target_male');
            $table->unsignedTinyInteger('target_female')->default(0)->after('min_female');
        });
    }

    public function down(): void
    {
        Schema::table('roster_staffing_requirements', function (Blueprint $table): void {
            $table->dropColumn(['min_female', 'target_female']);
        });
    }
};
