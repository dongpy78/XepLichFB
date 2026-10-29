<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phiên bản lịch tuần đã công bố (quản lý 30/10/2026): mỗi lần "Công bố lịch tuần" lưu một bản chụp đầy đủ của bảng xếp ca
 * (khu, nhân viên, thứ tự, mã ca / OFF từng ngày, chú thích ca) – file PNG / Excel / PDF luôn tạo từ bản chụp này,
 * không lấy dữ liệu đang chỉnh sửa. Công bố lại → phiên bản mới, giữ phiên bản cũ.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('roster_schedule_versions')) {
            return;
        }

        Schema::create('roster_schedule_versions', function (Blueprint $table): void {
            $table->id();
            $table->date('week_start')->index();
            // Phạm vi: 0 = toàn bộ bảng xếp ca; khác 0 = bảng lọc theo outlet đó.
            $table->unsignedBigInteger('scope_outlet_id')->default(0);
            $table->unsignedInteger('version');
            $table->string('hash', 64);
            $table->longText('snapshot');
            $table->unsignedInteger('employee_count')->default(0);
            $table->foreignId('published_by')->nullable();
            $table->string('published_by_name')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['week_start', 'scope_outlet_id', 'version'], 'roster_schedule_versions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_schedule_versions');
    }
};
