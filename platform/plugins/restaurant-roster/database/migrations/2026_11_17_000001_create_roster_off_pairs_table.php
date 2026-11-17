<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Cặp nghỉ chung: hai nhân viên được ưu tiên OFF cùng ngày (ràng buộc mềm). Chỉ lưu hai nhân viên, không lưu lý do.
        // Xóa = xóa mềm (deleted_at) để giữ lịch sử; trùng cặp / một người một cặp kiểm tra ở ứng dụng (bỏ qua bản đã xóa).
        Schema::create('roster_off_pairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_a_id')->index();
            $table->foreignId('employee_b_id')->index();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->foreignId('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_off_pairs');
    }
};
