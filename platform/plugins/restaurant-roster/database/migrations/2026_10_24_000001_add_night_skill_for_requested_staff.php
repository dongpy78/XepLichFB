<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quản lý yêu cầu 24/10/2026: Lê Trần Anh Quốc và Châu Quang Tiến được xếp Night luân phiên.
 * Đây là dữ liệu (năng lực Night trong "Năng lực nhân viên" + bật tham gia luân phiên), không phải tên ghi cứng trong thuật toán –
 * sau này quản lý thêm / bỏ người ở Năng lực nhân viên và trang Nhân sự Night.
 * Chỉ áp dụng khi tìm thấy đúng một nhân viên khớp tên; không tạo nhân viên mới. Kết quả ghi vào log ứng dụng.
 */
return new class () extends Migration {
    private const NAMES = ['Lê Trần Anh Quốc', 'Châu Quang Tiến'];

    public function up(): void
    {
        $night = DB::table('roster_outlets')->where('code', 'NIGHT')->value('id');
        $template = DB::table('roster_shift_templates')->where('code', 'N')->value('id');

        if (! $night || ! $template) {
            Log::info('Roster: chưa thêm năng lực Night – không tìm thấy outlet NIGHT hoặc mẫu ca N.');

            return;
        }

        foreach (self::NAMES as $name) {
            $ids = DB::table('roster_employees')->where('name', 'like', "%{$name}%")->pluck('id');

            if ($ids->count() !== 1) {
                Log::info("Roster: không thêm năng lực Night cho {$name} – tìm thấy {$ids->count()} nhân viên khớp tên.");

                continue;
            }

            $id = $ids->first();
            $hasSkill = DB::table('roster_employee_skills')->where('employee_id', $id)->where('outlet_id', $night)
                ->where(fn ($q) => $q->whereNull('shift_template_id')->orWhere('shift_template_id', 0)->orWhere('shift_template_id', $template))->exists();

            if (! $hasSkill) {
                DB::table('roster_employee_skills')->insert(['employee_id' => $id, 'outlet_id' => $night, 'shift_template_id' => $template,
                    'level' => 'eligible', 'is_fixed' => false, 'note' => 'Luân phiên Night (quản lý 24/10/2026)', 'created_at' => now(), 'updated_at' => now()]);
            }

            DB::table('roster_employees')->where('id', $id)->update(['night_rotation_enabled' => true]);
            DB::table('roster_change_logs')->insert(['employee_id' => $id, 'date' => now()->toDateString(), 'action' => 'night_rotation',
                'before' => json_encode(['night_skill' => $hasSkill]), 'after' => json_encode(['night_skill' => true, 'night_rotation_enabled' => true]),
                'reason' => 'Thêm vào danh sách luân phiên Night theo yêu cầu quản lý 24/10/2026', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Không gỡ năng lực đã cấp (có thể quản lý đã chỉnh tiếp) – gỡ ở Năng lực nhân viên nếu cần.
    }
};
