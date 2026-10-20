<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_employees', function (Blueprint $table): void {
            // Ngày/tháng sinh dạng "13/12" (bảng lịch gốc không có năm sinh).
            $table->string('birthday', 5)->nullable()->after('email');
        });

        Schema::table('roster_shifts', function (Blueprint $table): void {
            // Ký hiệu phụ của ca: L, G, V, RS, RS1, BQ, K...
            $table->json('tags')->nullable()->after('leave_type');
            // Giờ tăng ca nối tiếp sau ca ("+4" trong M6(L)+4).
            $table->decimal('overtime_hours', 4, 2)->default(0)->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('roster_shifts', function (Blueprint $table): void {
            $table->dropColumn(['tags', 'overtime_hours']);
        });

        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->dropColumn('birthday');
        });
    }
};
