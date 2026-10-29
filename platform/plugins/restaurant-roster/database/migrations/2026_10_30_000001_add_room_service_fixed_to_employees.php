<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room Service trong hồ sơ nhân viên (quản lý 30/10/2026) – hai vai trò:
 * - room_service_fixed: nhân viên Room Service cố định – ưu tiên xếp Room Service (ca cứng nếu có), không sang outlet khác;
 * - can_room_service (cột có sẵn): có thể hỗ trợ Room Service – chỉ được tự xếp thay khi Room Service thiếu người.
 * Người chuyên trách (lead) còn hiệu lực ở module cũ → cố định. Không tự bật cho ai khác.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('roster_employees', 'room_service_fixed')) {
            Schema::table('roster_employees', function (Blueprint $table): void {
                $table->boolean('room_service_fixed')->default(false)->after('can_room_service');
            });
        }

        if (Schema::hasTable('roster_room_service_staff')) {
            $leads = DB::table('roster_room_service_staff')->where('role', 'lead')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', date('Y-m-d')))
                ->when(Schema::hasColumn('roster_room_service_staff', 'is_active'), fn ($q) => $q->where('is_active', true))
                ->pluck('employee_id')->all();

            if ($leads) {
                DB::table('roster_employees')->whereIn('id', $leads)->update(['room_service_fixed' => true, 'can_room_service' => false]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roster_employees', 'room_service_fixed')) {
            Schema::table('roster_employees', fn (Blueprint $table) => $table->dropColumn('room_service_fixed'));
        }
    }
};
