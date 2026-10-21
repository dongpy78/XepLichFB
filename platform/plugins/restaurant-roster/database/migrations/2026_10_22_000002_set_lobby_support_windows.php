<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Khung hỗ trợ Lobby (L) theo xác nhận của quản lý 22/10/2026:
 * - M6(L): ca 06:00–14:00, 06–09h làm ở nhà hàng, 09:00–12:00 ở quầy bar Lobby (rồi về nhà hàng);
 * - A2(L): ca 14:00–22:00, 14:00–17:00 ở Lobby rồi về outlet được phân công.
 * Chỉnh lại được trên màn hình Mẫu ca.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('roster_shift_templates')->where('code', 'M6')->update(['lobby_start_time' => '09:00:00', 'lobby_end_time' => '12:00:00']);
        DB::table('roster_shift_templates')->where('code', 'A2')->update(['lobby_start_time' => '14:00:00', 'lobby_end_time' => '17:00:00']);
    }

    public function down(): void
    {
        DB::table('roster_shift_templates')->where('code', 'M6')->update(['lobby_start_time' => '09:00:00', 'lobby_end_time' => '14:00:00']);
    }
};
