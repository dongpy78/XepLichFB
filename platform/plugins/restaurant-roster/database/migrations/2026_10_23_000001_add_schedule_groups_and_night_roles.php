<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nhóm hiển thị lịch, vai trò nhân sự Night, thực tập sinh và kế hoạch luân phiên Night theo tuần (23/10/2026).
 *
 * - roster_schedule_groups: các khu vực trên Bảng xếp ca (GUS, Villa, Night, sau này Trainer…) – quản lý tự thêm / sửa / sắp xếp / bật tắt.
 *   Nhóm hiển thị KHÔNG phải outlet làm việc: một người nhóm Villa vẫn được xếp Night luân phiên mà không đổi nhóm.
 *   outlet_id (tùy chọn) = outlet có dòng thống kê định biên hiện dưới nhóm.
 * - roster_employees: nhóm hiển thị + thứ tự trong nhóm; vai trò Night (core = cố định, flex = đa năng);
 *   thực tập sinh (không tính vào định biên), người hướng dẫn, thời gian thực tập.
 * - roster_night_rotations: người luân phiên Night từng tuần (đề xuất / đã duyệt / đã công bố), người duyệt, lý do.
 *
 * Chuyển dữ liệu: mỗi outlet đang có nhân viên → một nhóm hiển thị cùng tên, cùng thứ tự (bảng lịch giữ nguyên như cũ);
 * nhân viên outlet Night không có ca cứng ca đêm → Night đa năng (người có ca cứng N là Night cố định, không cần đánh dấu).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('roster_schedule_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->foreignId('outlet_id')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('description', 400)->nullable();
            $table->timestamps();
        });

        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->foreignId('schedule_group_id')->nullable()->after('outlet_id')->index();
            $table->unsignedSmallInteger('schedule_order')->nullable()->after('schedule_group_id');
            // flex = Night đa năng (Night / Room Service / hỗ trợ nhà hàng). Trống = nhân viên thường.
            // Night cố định không lưu ở đây: là người có ca cứng là ca đêm (quản lý chọn ở mục Ca cứng).
            $table->string('night_role', 10)->nullable()->after('fixed_shift_template_id');
            $table->boolean('is_trainee')->default(false)->after('night_role');
            $table->foreignId('mentor_id')->nullable()->after('is_trainee');
            $table->date('training_from')->nullable()->after('mentor_id');
            $table->date('training_to')->nullable()->after('training_from');
            $table->string('training_note', 255)->nullable()->after('training_to');
        });

        Schema::create('roster_night_rotations', function (Blueprint $table): void {
            $table->id();
            $table->date('week_start')->index();
            $table->foreignId('employee_id')->index();
            $table->foreignId('source_outlet_id')->nullable();
            // proposed | approved | published
            $table->string('status', 10)->default('proposed');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['week_start', 'employee_id']);
        });

        $now = now();
        $outlets = DB::table('roster_outlets')->whereIn('id', DB::table('roster_employees')->whereNotNull('outlet_id')->distinct()->pluck('outlet_id'))
            ->orderBy('order')->get(['id', 'code', 'name', 'order']);

        foreach ($outlets as $outlet) {
            $code = strtoupper((string) ($outlet->code ?: 'OUTLET' . $outlet->id));

            if (DB::table('roster_schedule_groups')->where('code', $code)->exists()) {
                $code .= '-' . $outlet->id;
            }

            $groupId = DB::table('roster_schedule_groups')->insertGetId(['code' => $code, 'name' => $outlet->name ?: $code, 'outlet_id' => $outlet->id,
                'order' => (int) $outlet->order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('roster_employees')->where('outlet_id', $outlet->id)->update(['schedule_group_id' => $groupId]);
        }

        // Nam waiter M6 / A2 (Gusto, Villa): tối thiểu 1 mỗi ca (đã có), mục tiêu 2 mỗi ca → đủ 4 người thì chia 2 / 2, không 3 / 1.
        // Ghi lịch sử định biên để quản lý thấy và chỉnh lại được.
        $restaurants = DB::table('roster_outlets')->whereIn('code', ['GUSTO', 'VILLA'])->pluck('id');
        $dayShifts = DB::table('roster_shift_templates')->whereIn('code', ['M6', 'A2'])->pluck('id');

        foreach (DB::table('roster_staffing_requirements')->whereIn('outlet_id', $restaurants)->whereIn('shift_template_id', $dayShifts)
            ->where('status', 'active')->where('min_male', '>=', 1)->where(fn ($q) => $q->whereNull('target_male')->orWhere('target_male', '<', 2))->get() as $row) {
            DB::table('roster_staffing_requirements')->where('id', $row->id)->update(['target_male' => 2, 'updated_at' => $now]);
            DB::table('roster_staffing_requirement_revisions')->insert(['requirement_id' => $row->id, 'action' => 'update',
                'before' => json_encode(['target_male' => $row->target_male]), 'after' => json_encode(['target_male' => 2]),
                'reason' => 'Quy tắc nam waiter: đủ 4 người thì 2 M6 / 2 A2 (23/10/2026)', 'created_at' => $now, 'updated_at' => $now]);
        }

        $nightOutlet = DB::table('roster_outlets')->where('code', 'NIGHT')->value('id');

        if ($nightOutlet) {
            $nightTemplates = DB::table('roster_shift_templates')->where('code', 'N')->pluck('id');
            DB::table('roster_employees')->where('outlet_id', $nightOutlet)
                ->where(fn ($q) => $q->whereNull('fixed_shift_template_id')->orWhereNotIn('fixed_shift_template_id', $nightTemplates))
                ->update(['night_role' => 'flex']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_night_rotations');

        Schema::table('roster_employees', function (Blueprint $table): void {
            $table->dropColumn(['schedule_group_id', 'schedule_order', 'night_role', 'is_trainee', 'mentor_id', 'training_from', 'training_to', 'training_note']);
        });

        Schema::dropIfExists('roster_schedule_groups');
    }
};
