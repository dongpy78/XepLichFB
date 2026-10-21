<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cấp quản lý (Junior Restaurant Captain trở lên): cờ trên danh mục vị trí, quản lý bật/tắt trên màn hình Vị trí.
 * Thuật toán dựa vào cờ này (không so tên chức vụ) để: chỉ tự xếp M6/A2, giữ ở outlet chính, cân bằng quản lý M6 / A2.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_positions', function (Blueprint $table): void {
            $table->boolean('is_manager')->default(false)->after('name');
        });

        // Bật sẵn cho các chức vụ trong quy tắc của bộ phận (chỉ chạy một lần; sau đó chỉnh trên màn hình Vị trí).
        $managers = [
            'junior restaurant captain', 'restaurant captain', 'junior restaurant supervisor', 'restaurant supervisor',
            'senior restaurant supervisor', 'assistant restaurant manager', 'assistant manager', 'restaurant manager',
        ];

        foreach (DB::table('roster_positions')->get(['id', 'name']) as $position) {
            if (in_array(mb_strtolower(trim((string) $position->name)), $managers, true)) {
                DB::table('roster_positions')->where('id', $position->id)->update(['is_manager' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('roster_positions', function (Blueprint $table): void {
            $table->dropColumn('is_manager');
        });
    }
};
