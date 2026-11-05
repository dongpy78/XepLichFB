<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Khu vực TRAINEE trên Bảng xếp ca (quản lý 05/11/2026): đặt cuối bảng, không gắn outlet (thực tập sinh chọn nhà hàng làm việc riêng),
 * không có dòng thống kê định biên. Thực tập sinh hiện có chuyển vào khu vực này; outlet làm việc của họ không đổi.
 */
return new class () extends Migration {
    public function up(): void
    {
        $now = now();
        $groupId = DB::table('roster_schedule_groups')->where('code', 'TRAINEE')->value('id');

        if (! $groupId) {
            $groupId = DB::table('roster_schedule_groups')->insertGetId(['code' => 'TRAINEE', 'name' => 'TRAINEE', 'outlet_id' => null,
                'order' => (int) DB::table('roster_schedule_groups')->max('order') + 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('roster_employees')->where('is_trainee', true)->update(['schedule_group_id' => $groupId]);
    }

    public function down(): void
    {
        $groupId = DB::table('roster_schedule_groups')->where('code', 'TRAINEE')->value('id');

        if ($groupId) {
            DB::table('roster_employees')->where('schedule_group_id', $groupId)->update(['schedule_group_id' => null]);
            DB::table('roster_schedule_groups')->where('id', $groupId)->delete();
        }
    }
};
