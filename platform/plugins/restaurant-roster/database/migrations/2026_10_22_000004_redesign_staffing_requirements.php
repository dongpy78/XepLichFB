<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Định biên nhân sự (thiết kế lại 22/10/2026):
 * - Trạng thái cấu hình: active (thuật toán dùng) / draft (nháp – chưa dùng) / inactive (ngừng hiệu lực, giữ lịch sử).
 * - Khoảng hiệu lực (từ ngày – đến ngày), người tạo / người cập nhật.
 * - Khung đếm (tùy chọn): người hỗ trợ Lobby (L) một phần ca vẫn được tính cho outlet nếu có mặt ở outlet trong khung này.
 * - Nam / nữ waiter: trống = không áp dụng (khác 0).
 * - Nhóm nhân sự theo vị trí (bảng con): một vị trí trong danh mục Vị trí, hoặc nhóm "cấp quản lý".
 * - Lịch sử chỉnh sửa (trước / sau, lý do, người sửa).
 *
 * Chuyển dữ liệu: dòng cũ giữ nguyên số và đang áp dụng; 3 quy tắc viết cứng trong config (Gusto / Villa × M6 / A2:
 * ≥ 1 nam, ≥ 1 Hostess, ≥ 1 quản lý) được chép vào từng dòng định biên tương ứng – kết quả xếp lịch không đổi.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_staffing_requirements', function (Blueprint $table): void {
            $table->string('status', 10)->default('active')->after('shift_template_id')->index();
            $table->date('effective_from')->nullable()->after('date');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->time('count_start_time')->nullable()->after('upper_floor');
            $table->time('count_end_time')->nullable()->after('count_start_time');
            $table->foreignId('created_by')->nullable()->after('note');
            $table->foreignId('updated_by')->nullable()->after('created_by');
        });

        Schema::table('roster_staffing_requirements', function (Blueprint $table): void {
            $table->unsignedSmallInteger('min_male')->nullable()->default(null)->change();
            $table->unsignedSmallInteger('target_male')->nullable()->default(null)->change();
            $table->unsignedSmallInteger('min_female')->nullable()->default(null)->change();
            $table->unsignedSmallInteger('target_female')->nullable()->default(null)->change();
        });

        Schema::create('roster_staffing_requirement_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requirement_id')->index();
            // position = một vị trí (position_id); managers = mọi vị trí bật "Cấp quản lý".
            $table->string('kind', 12)->default('position');
            $table->foreignId('position_id')->nullable();
            $table->unsignedTinyInteger('min_staff')->nullable();
            $table->unsignedTinyInteger('target_staff')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('roster_staffing_requirement_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requirement_id')->index();
            // create | update | deactivate | activate | delete | copy
            $table->string('action', 12);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        // 0 / 0 nam hoặc nữ = trước đây là "không đặt" → không áp dụng.
        DB::table('roster_staffing_requirements')->where('min_male', 0)->where('target_male', 0)->update(['min_male' => null, 'target_male' => null]);
        DB::table('roster_staffing_requirements')->where('min_female', 0)->where('target_female', 0)->update(['min_female' => null, 'target_female' => null]);

        // Quy tắc viết cứng trong config → dữ liệu định biên.
        $outlets = DB::table('roster_outlets')->whereIn('code', ['GUSTO', 'VILLA'])->pluck('id')->all();
        $shifts = DB::table('roster_shift_templates')->whereIn('code', ['M6', 'A2'])->pluck('id')->all();
        $hostess = DB::table('roster_positions')->whereRaw('LOWER(name) = ?', ['hostess'])->value('id');
        $now = now();

        if ($outlets && $shifts) {
            $rows = DB::table('roster_staffing_requirements')->whereIn('outlet_id', $outlets)->whereIn('shift_template_id', $shifts)->get();

            foreach ($rows as $row) {
                if ((int) ($row->min_male ?? 0) < 1) {
                    DB::table('roster_staffing_requirements')->where('id', $row->id)->update([
                        'min_male' => 1,
                        'target_male' => max(1, (int) ($row->target_male ?? 0)),
                    ]);
                }

                if ($hostess) {
                    DB::table('roster_staffing_requirement_positions')->insert(['requirement_id' => $row->id, 'kind' => 'position', 'position_id' => $hostess,
                        'min_staff' => 1, 'target_staff' => 1, 'note' => 'Chuyển từ quy tắc cấu hình cũ', 'created_at' => $now, 'updated_at' => $now]);
                }

                DB::table('roster_staffing_requirement_positions')->insert(['requirement_id' => $row->id, 'kind' => 'managers', 'position_id' => null,
                    'min_staff' => 1, 'target_staff' => 1, 'note' => 'Chuyển từ quy tắc cấu hình cũ', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_staffing_requirement_revisions');
        Schema::dropIfExists('roster_staffing_requirement_positions');

        DB::table('roster_staffing_requirements')->whereNull('min_male')->update(['min_male' => 0, 'target_male' => 0]);
        DB::table('roster_staffing_requirements')->whereNull('min_female')->update(['min_female' => 0, 'target_female' => 0]);

        Schema::table('roster_staffing_requirements', function (Blueprint $table): void {
            $table->dropColumn(['status', 'effective_from', 'effective_to', 'count_start_time', 'count_end_time', 'created_by', 'updated_by']);
        });
    }
};
