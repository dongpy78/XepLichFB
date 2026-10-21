<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quy trình OFF của quản lý (quyết định 21/10/2026): nhân viên nhắn OFF qua Zalo, quản lý nhập vào phần mềm = đã cho OFF.
 * OFF được ghi ngay vào bảng lịch và khóa; thiếu người thì quản lý "gỡ OFF" ô đó rồi xếp ca.
 *
 * - Ô lịch có lý do khóa (OFF từ đơn / ca đã xác nhận / tiệc BQ / khóa tay), người khóa, thời điểm khóa, liên kết đơn OFF,
 *   và cờ "cần xem xét" – thay cho một cờ khóa có/không.
 * - Đơn OFF có ngày tiếp nhận (ngày nhân viên nhắn Zalo).
 * - Kỳ lịch có mốc "đã nhập xong OFF" – điều kiện để chạy xếp lịch tự động.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('roster_shifts', function (Blueprint $table): void {
            // off_request | confirmed | bq | manual (null = không khóa)
            $table->string('lock_reason', 20)->nullable()->after('is_locked');
            $table->foreignId('locked_by')->nullable()->after('lock_reason');
            $table->timestamp('locked_at')->nullable()->after('locked_by');
            $table->foreignId('availability_request_id')->nullable()->index()->after('locked_at');
            $table->boolean('needs_review')->default(false)->after('availability_request_id');
            $table->string('review_note', 255)->nullable()->after('needs_review');
        });

        Schema::table('roster_availability_requests', function (Blueprint $table): void {
            $table->date('received_at')->nullable()->after('reason');
        });

        Schema::table('roster_periods', function (Blueprint $table): void {
            $table->foreignId('off_confirmed_by')->nullable()->after('status');
            $table->timestamp('off_confirmed_at')->nullable()->after('off_confirmed_by');
            $table->string('note', 255)->nullable()->after('off_confirmed_at');
        });

        // Ô đã khóa trước đây: tiệc BQ hoặc khóa tay.
        DB::table('roster_shifts')->where('is_locked', true)->whereNull('lock_reason')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $tags = json_decode((string) $row->tags, true) ?: [];
                DB::table('roster_shifts')->where('id', $row->id)->update([
                    'lock_reason' => in_array('BQ', $tags, true) ? 'bq' : 'manual',
                    'locked_at' => $row->updated_at,
                ]);
            }
        });

        // Đơn đã duyệt còn hiệu lực (từ 5 tuần trước trở đi): ghi OFF vào lịch và khóa, như quy trình mới.
        // Ô đang có ca làm: không đè, đánh dấu "cần xem xét" để quản lý quyết định.
        $leave = ['annual_leave' => 'AL', 'sick' => 'SL'];
        $since = now()->subWeeks(5)->toDateString();
        $now = now();

        DB::table('roster_availability_requests')->where('status', 'approved')->whereDate('date_to', '>=', $since)->orderBy('id')
            ->chunkById(200, function ($requests) use ($leave, $since, $now): void {
                foreach ($requests as $request) {
                    $day = max(substr((string) $request->date_from, 0, 10), $since);
                    $last = substr((string) $request->date_to, 0, 10);

                    for (; $day <= $last; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
                        $cell = DB::table('roster_shifts')->where('employee_id', $request->employee_id)->whereDate('date', $day)->first();

                        if ($cell && $cell->kind === 'shift') {
                            DB::table('roster_shifts')->where('id', $cell->id)->update([
                                'needs_review' => true,
                                'review_note' => "Trùng đơn OFF #{$request->id} đã duyệt – giữ ca hay cho OFF?",
                            ]);

                            continue;
                        }

                        $values = [
                            'kind' => isset($leave[$request->type]) ? 'leave' : 'off',
                            'leave_type' => $leave[$request->type] ?? null,
                            'is_locked' => true,
                            'lock_reason' => 'off_request',
                            'locked_by' => $request->reviewed_by,
                            'locked_at' => $request->reviewed_at ?? $now,
                            'availability_request_id' => $request->id,
                            'updated_at' => $now,
                        ];

                        if ($cell) {
                            DB::table('roster_shifts')->where('id', $cell->id)->update($values);
                        } else {
                            DB::table('roster_shifts')->insert($values + [
                                'employee_id' => $request->employee_id,
                                'date' => $day,
                                'status' => 'draft',
                                'source' => 'manual',
                                'hours' => 0,
                                'break_minutes' => 0,
                                'overtime_hours' => 0,
                                'note' => $request->reason ?: $request->group_label,
                                'created_by' => $request->reviewed_by,
                                'created_at' => $now,
                            ]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('roster_periods', function (Blueprint $table): void {
            $table->dropColumn(['off_confirmed_by', 'off_confirmed_at', 'note']);
        });

        Schema::table('roster_availability_requests', function (Blueprint $table): void {
            $table->dropColumn('received_at');
        });

        Schema::table('roster_shifts', function (Blueprint $table): void {
            $table->dropColumn(['lock_reason', 'locked_by', 'locked_at', 'availability_request_id', 'needs_review', 'review_note']);
        });
    }
};
