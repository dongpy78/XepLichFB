<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Hỗ trợ quầy bar Lobby: khung giờ (L) theo từng ca, ca làm trọn ở Lobby (OH, M9), định biên phủ Lobby theo khung giờ.
 * - Khu vực sinh sống (xe đưa đón Nha Trang) và ca chỉ dành cho một khu vực (OH).
 * - Tăng ca có cấu trúc: trạng thái duyệt, người duyệt, thời điểm duyệt.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_shift_templates', function (Blueprint $table): void {
            // Ca làm trọn tại một trạm hỗ trợ (vd OH, M9 → LOBBY).
            $table->string('support_station', 20)->nullable()->after('color');
            // Khung giờ hỗ trợ Lobby khi ca có ký hiệu (L): M6(L) 09:00–14:00, A2(L) 14:00–17:00.
            $table->time('lobby_start_time')->nullable()->after('support_station');
            $table->time('lobby_end_time')->nullable()->after('lobby_start_time');
            // Ca chỉ dành cho nhân viên ở một khu vực (OH → nha_trang vì xe đưa đón).
            $table->string('allowed_residence', 20)->nullable()->after('lobby_end_time');
        });

        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->string('residence', 20)->nullable()->after('gender');
        });

        Schema::table('roster_shifts', function (Blueprint $table): void {
            // pending | approved | rejected (chỉ có ý nghĩa khi overtime_hours > 0)
            $table->string('overtime_status', 10)->nullable()->after('overtime_hours');
            $table->foreignId('overtime_approved_by')->nullable()->after('overtime_status');
            $table->timestamp('overtime_approved_at')->nullable()->after('overtime_approved_by');
        });

        // Định biên phủ trạm hỗ trợ theo khung giờ (vd Lobby 09:00–14:00 cần 3 người).
        Schema::create('roster_coverage_requirements', function (Blueprint $table): void {
            $table->id();
            $table->string('station', 20)->default('LOBBY');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('date')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('min_staff')->default(0);
            $table->unsignedTinyInteger('target_staff')->default(0);
            $table->boolean('is_confirmed')->default(false);
            $table->string('source', 20)->default('manual');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_coverage_requirements');

        Schema::table('roster_shifts', function (Blueprint $table): void {
            $table->dropColumn(['overtime_status', 'overtime_approved_by', 'overtime_approved_at']);
        });

        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->dropColumn('residence');
        });

        Schema::table('roster_shift_templates', function (Blueprint $table): void {
            $table->dropColumn(['support_station', 'lobby_start_time', 'lobby_end_time', 'allowed_residence']);
        });
    }
};
