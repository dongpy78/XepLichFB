<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tinh gọn Night / Room Service (quản lý 29/10/2026): cấu hình nằm trong hồ sơ nhân viên.
 * - "Night cố định": cột night_role = 'core' (tái sử dụng cột có sẵn). Người đang có ca cứng là ca đêm được đánh dấu Night cố định.
 *   Night luân phiên bỏ tự động – night_role 'rotation' (nếu có) được xóa; quản lý xếp tay ca đêm trên Bảng xếp ca.
 * - "Có thể làm Room Service": cột mới can_room_service. Lấy từ module Nhân sự Room Service cũ (chuyên trách, dự phòng, đã xác nhận
 *   kỹ năng – dòng còn hiệu lực). Chuyên trách theo ca chưa có ca cứng: ca đó thành ca cứng trong hồ sơ (giữ đúng ca như trước).
 * Không xóa bảng / dữ liệu cũ (roster_night_rotations, roster_room_service_staff, roster_night_demands) – chỉ không dùng nữa.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('roster_employees', 'can_room_service')) {
            Schema::table('roster_employees', function (Blueprint $table): void {
                $table->boolean('can_room_service')->default(false)->after('night_role');
            });
        }

        // Night cố định: người có ca cứng là ca đêm (bắt đầu 20:00 – trước 04:00).
        $nightTemplates = DB::table('roster_shift_templates')->get(['id', 'start_time'])
            ->filter(function ($t) {
                $hour = (int) substr((string) $t->start_time, 0, 2);

                return $t->start_time !== null && ($hour >= 20 || $hour < 4);
            })->pluck('id')->all();

        if ($nightTemplates) {
            DB::table('roster_employees')->whereIn('fixed_shift_template_id', $nightTemplates)->update(['night_role' => 'core']);
        }

        DB::table('roster_employees')->where('night_role', 'rotation')->update(['night_role' => null]);

        // Room Service từ module cũ.
        if (Schema::hasTable('roster_room_service_staff')) {
            $today = date('Y-m-d');
            $rows = DB::table('roster_room_service_staff')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
                ->when(Schema::hasColumn('roster_room_service_staff', 'is_active'), fn ($q) => $q->where('is_active', true))
                ->get();

            foreach ($rows as $row) {
                DB::table('roster_employees')->where('id', $row->employee_id)->update(['can_room_service' => true]);

                if ($row->role === 'lead' && $row->shift_template_id) {
                    DB::table('roster_employees')->where('id', $row->employee_id)->whereNull('fixed_shift_template_id')
                        ->update(['fixed_shift_template_id' => $row->shift_template_id]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roster_employees', 'can_room_service')) {
            Schema::table('roster_employees', fn (Blueprint $table) => $table->dropColumn('can_room_service'));
        }
    }
};
