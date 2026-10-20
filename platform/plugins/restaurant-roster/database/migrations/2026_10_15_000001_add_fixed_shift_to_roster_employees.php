<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_employees', function (Blueprint $table): void {
            // Ca cứng: thuật toán tự động chỉ xếp nhân viên vào ca này (hoặc OFF).
            // Quản lý xếp tay vẫn linh hoạt; ô xếp khác ca cứng được tự khóa.
            $table->foreignId('fixed_shift_template_id')->nullable()->after('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->dropColumn('fixed_shift_template_id');
        });
    }
};
