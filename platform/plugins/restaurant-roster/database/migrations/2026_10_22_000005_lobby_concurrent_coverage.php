<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Chốt quy tắc định biên Lobby Bar (quản lý 22/10/2026):
 * - Lobby mở 08:30–17:00; khung chính 09:00–16:45 cần TỐI THIỂU 2 NỮ WAITER CÓ MẶT CÙNG LÚC
 *   (08:30–09:00 và 16:45–17:00 không bắt buộc đủ 2 người).
 * - Ưu tiên OH(L) 08:45–16:45 (nhóm Nha Trang) + M9(L) 09:00–17:00 (nhóm Cam Ranh);
 *   thay thế: M6(L) ở Lobby 09:00–14:00, A2(L) ở Lobby 14:00–17:00 – chỉ đạt khi đủ 2 người ở mọi đoạn giờ.
 *
 * Thay các dòng định biên Lobby theo thứ / chung cũ (đếm theo "phủ ≥ 50% khung") bằng một dòng chung 09:00–16:45.
 * Dòng ngày cụ thể (sự kiện) giữ nguyên và được đếm theo số người cùng lúc. Dòng cũ bị thay được ghi vào log ứng dụng.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_coverage_requirements', function (Blueprint $table): void {
            // Chỉ đếm nữ waiter (không tính cấp quản lý / vị trí khác).
            $table->boolean('female_only')->default(false)->after('shift_codes');
        });

        DB::table('roster_shift_templates')->where('code', 'OH')->update(['lobby_start_time' => '08:45:00', 'lobby_end_time' => '16:45:00', 'allowed_residence' => 'nha_trang']);
        DB::table('roster_shift_templates')->where('code', 'M9')->update(['allowed_residence' => 'cam_ranh', 'allowed_gender' => 'female']);
        DB::table('roster_shift_templates')->where('code', 'M6')->update(['lobby_start_time' => '09:00:00', 'lobby_end_time' => '14:00:00']);
        DB::table('roster_shift_templates')->where('code', 'A2')->update(['lobby_start_time' => '14:00:00', 'lobby_end_time' => '17:00:00']);

        $station = config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY');
        $replaced = DB::table('roster_coverage_requirements')->where('station', $station)->whereNull('date')->get();

        if ($replaced->isNotEmpty()) {
            Log::info('Roster: thay định biên Lobby cũ bằng quy tắc 2 nữ waiter cùng lúc 09:00–16:45', ['rows' => $replaced->map(fn ($row) => (array) $row)->all()]);
            DB::table('roster_coverage_requirements')->whereIn('id', $replaced->pluck('id'))->delete();
        }

        DB::table('roster_coverage_requirements')->where('station', $station)->update(['female_only' => true]);

        DB::table('roster_coverage_requirements')->insert([
            'station' => $station,
            'weekday' => null,
            'date' => null,
            'start_time' => '09:00:00',
            'end_time' => '16:45:00',
            'shift_codes' => null,
            'female_only' => true,
            'min_staff' => 2,
            'target_staff' => 2,
            'is_confirmed' => true,
            'source' => 'manager',
            'note' => 'Lobby mở 08:30–17:00; 2 nữ waiter cùng lúc 09:00–16:45. Ưu tiên OH(L) Nha Trang + M9(L) Cam Ranh; thay thế M6(L) 09–14h / A2(L) 14–17h.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roster_shift_templates')->where('code', 'M6')->update(['lobby_start_time' => '09:00:00', 'lobby_end_time' => '12:00:00']);

        Schema::table('roster_coverage_requirements', function (Blueprint $table): void {
            $table->dropColumn('female_only');
        });
    }
};
