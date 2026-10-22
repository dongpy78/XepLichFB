<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Nhân sự Room Service và quy tắc Night mới (quản lý 23/10/2026):
 * - roster_room_service_staff: người chuyên trách Room Service theo ca (lead + ca), người dự phòng (backup), người đã xác nhận
 *   có kỹ năng thay thế (substitute) – có hiệu lực từ / đến, trạng thái, người tạo / sửa. Đổi người = đóng dòng cũ, mở dòng mới (giữ lịch sử).
 * - roster_employees.night_rotation_enabled: bật / tắt tư cách luân phiên Night của từng người.
 * - "Night đa năng" của lần trước chính là 2 người Room Service cứng → bỏ vai trò đa năng; người chuyên trách Room Service ban đầu
 *   lấy theo dữ liệu thật nếu có đúng một nhân viên khớp tên (Quốc Trung → M6, Xuân Lưu → A2). Không tạo nhân viên mới.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('roster_room_service_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index();
            // lead = chuyên trách một ca (shift_template_id), backup = dự phòng, substitute = đã xác nhận đủ kỹ năng thay thế
            $table->string('role', 12);
            $table->foreignId('shift_template_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->boolean('night_rotation_enabled')->default(true)->after('night_role');
        });

        DB::table('roster_employees')->where('night_role', 'flex')->update(['night_role' => null]);

        $room = DB::table('roster_outlets')->whereIn('code', ['ROOM', 'RS', 'ROOMSERVICE', 'ROOM_SERVICE'])->value('id')
            ?? DB::table('roster_outlets')->where('name', 'like', '%Room%')->value('id');
        $now = now();

        foreach (['M6' => 'Quốc Trung', 'A2' => 'Xuân Lưu'] as $code => $name) {
            $template = DB::table('roster_shift_templates')->where('code', $code)->value('id');
            $matches = DB::table('roster_employees')->where('name', 'like', "%{$name}%")->pluck('id');

            if (! $room || ! $template || $matches->count() !== 1) {
                Log::info("Roster: chưa tạo người chuyên trách Room Service {$code} ({$name}) – tìm thấy {$matches->count()} nhân viên khớp tên.");

                continue;
            }

            DB::table('roster_room_service_staff')->insert(['employee_id' => $matches->first(), 'role' => 'lead', 'shift_template_id' => $template,
                'is_active' => true, 'note' => 'Cấu hình ban đầu (23/10/2026)', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->dropColumn('night_rotation_enabled');
        });

        Schema::dropIfExists('roster_room_service_staff');
    }
};
