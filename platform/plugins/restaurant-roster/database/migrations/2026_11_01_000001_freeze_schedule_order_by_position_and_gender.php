<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bảng xếp ca sắp theo vị trí → (Waiter) nữ trước nam → "Thứ tự trong nhóm" (quản lý 01/11/2026).
 * Ghi số thứ tự 10, 20, 30… cho mọi nhân viên theo thứ tự hiện tại trong từng nhóm (khu hiển thị × vị trí × giới với Waiter),
 * để người cũ giữ nguyên thứ tự và nhân viên mới tự nhận số cuối nhóm. Không đổi dữ liệu nào khác.
 */
return new class () extends Migration {
    public function up(): void
    {
        $names = array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) config('plugins.restaurant-roster.general.scheduling.gender_order_positions', ['Waiter']));
        $gendered = DB::table('roster_positions')->get(['id', 'name'])
            ->filter(fn ($p) => in_array(mb_strtolower(trim((string) $p->name)), $names, true))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $groups = DB::table('roster_employees')->orderByRaw('schedule_order is null')->orderBy('schedule_order')->orderBy('code')->orderBy('id')
            ->get(['id', 'schedule_group_id', 'outlet_id', 'position_id', 'gender'])
            ->groupBy(fn ($e) => implode('|', [
                $e->schedule_group_id ? 'g' . $e->schedule_group_id : 'o' . $e->outlet_id,
                (int) $e->position_id,
                in_array((int) $e->position_id, $gendered, true) ? (['female' => 'f', 'male' => 'm'][$e->gender] ?? '?') : '',
            ]));

        foreach ($groups as $rows) {
            foreach ($rows->values() as $k => $row) {
                DB::table('roster_employees')->where('id', $row->id)->update(['schedule_order' => min(9990, ($k + 1) * 10)]);
            }
        }
    }

    public function down(): void
    {
    }
};
