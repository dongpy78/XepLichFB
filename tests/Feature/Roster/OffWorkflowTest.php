<?php

namespace Tests\Feature\Roster;

use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Services\OffRequestService;
use Botble\RestaurantRoster\Services\RosterScheduler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Plugin Botble được nạp động lúc chạy web; test nạp namespace trực tiếp.
spl_autoload_register(function (string $class): void {
    $prefix = 'Botble\\RestaurantRoster\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

/**
 * Quy trình OFF của quản lý trên database thật (SQLite trong bộ nhớ) – DỮ LIỆU GIẢ LẬP, ngày năm 2030.
 * Đối chiếu danh sách kiểm thử bắt buộc: OFF ghi & khóa, thuật toán không đổi OFF / ô khóa, gỡ OFF, trùng đơn,
 * OFF mới trùng ca đã khóa, lịch sử thao tác.
 */
class OffWorkflowTest extends TestCase
{
    private const MONDAY = '2030-01-07';

    private Outlet $gusto;

    private ShiftTemplate $m6;

    private ShiftTemplate $a2;

    /** @var Employee[] */
    private array $team = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);
        config(['plugins.restaurant-roster.general.scheduling.staffing_rules' => [], 'plugins.restaurant-roster.general.scheduling.time_limit_seconds' => 3]);

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }

        $this->gusto = Outlet::query()->create(['name' => 'Gusto', 'code' => 'GUSTO', 'status' => 'published']);
        $this->m6 = ShiftTemplate::query()->create(['code' => 'M6', 'name' => 'Sáng', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'status' => 'published']);
        $this->a2 = ShiftTemplate::query()->create(['code' => 'A2', 'name' => 'Chiều', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'status' => 'published']);

        foreach (range(1, 6) as $k) {
            $employee = Employee::query()->create([
                'code' => "SYN{$k}", 'name' => "Synthetic {$k}", 'gender' => $k % 2 ? 'male' : 'female', 'outlet_id' => $this->gusto->getKey(),
                'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published',
            ]);
            EmployeeSkill::query()->create(['employee_id' => $employee->getKey(), 'outlet_id' => $this->gusto->getKey(), 'level' => 'primary']);
            $this->team[] = $employee;
        }

        foreach ([$this->m6, $this->a2] as $template) {
            StaffingRequirement::query()->create(['outlet_id' => $this->gusto->getKey(), 'shift_template_id' => $template->getKey(),
                'min_staff' => 1, 'target_staff' => 2, 'is_confirmed' => true]);
        }
    }

    private function day(int $offset): string
    {
        return Carbon::parse(self::MONDAY)->addDays($offset)->toDateString();
    }

    private function offs(): OffRequestService
    {
        return app(OffRequestService::class);
    }

    private function cell(Employee $employee, string $date): ?Shift
    {
        return Shift::query()->where('employee_id', $employee->getKey())->whereDate('date', $date)->first();
    }

    private function record(Employee $employee, int $from, int $to, array $extra = [], bool $override = false): array
    {
        return $this->offs()->record([$employee->getKey()], ['date_from' => $this->day($from), 'date_to' => $this->day($to), 'type' => 'off_request', ...$extra], 1, $override);
    }

    private function runScheduler(string $type = 'generate')
    {
        $scheduler = app(RosterScheduler::class);
        $run = $scheduler->run(Carbon::parse($this->day(0)), Carbon::parse($this->day(6)), null, $type, 1, ['time_limit_seconds' => 3]);
        $this->assertContains($run->status, ['feasible', 'partial', 'infeasible'], (string) $run->explanation);
        $scheduler->apply($run, 1);

        return $run;
    }

    /** 1 + 14. Nhập OFF: ô lịch thành OFF, bị khóa, liên kết đơn, đơn "đã cho OFF", có lịch sử. */
    public function test_recorded_off_is_written_and_locked_with_history(): void
    {
        $result = $this->record($this->team[0], 1, 2, ['reason' => 'Việc gia đình', 'received_at' => $this->day(-3)]);

        $this->assertSame(2, $result['written']);
        $request = $result['requests'][0]->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($this->day(-3), $request->received_at->toDateString());

        foreach ([1, 2] as $offset) {
            $cell = $this->cell($this->team[0], $this->day($offset));
            $this->assertSame(Shift::KIND_OFF, $cell->kind);
            $this->assertTrue($cell->is_locked);
            $this->assertSame(Shift::LOCK_OFF_REQUEST, $cell->lock_reason);
            $this->assertSame($request->getKey(), $cell->availability_request_id);
            $this->assertSame(Shift::STATE_OFF_LOCKED, $cell->cellState());
        }

        $this->assertSame(2, ChangeLog::query()->where('action', 'off_record')->count());
    }

    /** Nghỉ phép năm ghi thành ô AL (nghỉ phép), cũng khóa. */
    public function test_annual_leave_is_written_as_leave_cell(): void
    {
        $this->record($this->team[1], 3, 3, ['type' => 'annual_leave']);
        $cell = $this->cell($this->team[1], $this->day(3));

        $this->assertSame(Shift::KIND_LEAVE, $cell->kind);
        $this->assertSame('AL', $cell->leave_type);
        $this->assertTrue($cell->is_locked);
    }

    /** 2 + 5. Thuật toán không đổi OFF đã cho; chỉ xếp vào ô trống chưa khóa. */
    public function test_scheduler_keeps_recorded_off_and_fills_only_free_cells(): void
    {
        $this->record($this->team[0], 1, 2);
        $this->runScheduler();

        foreach ([1, 2] as $offset) {
            $cell = $this->cell($this->team[0], $this->day($offset));
            $this->assertSame(Shift::KIND_OFF, $cell->kind, 'OFF đã cho không bị đổi thành ca làm');
            $this->assertTrue($cell->is_locked);
        }

        $engineCells = Shift::query()->where('source', 'engine')->count();
        $this->assertGreaterThan(0, $engineCells, 'Thuật toán đã xếp các ô còn trống');
        $this->assertSame(0, Shift::query()->where('source', 'engine')->where('is_locked', true)->count(), 'Ô do thuật toán xếp là dự thảo, chưa khóa');
    }

    /** 3. Đơn chờ xử lý cũ không tự trở thành OFF trên lịch. */
    public function test_pending_request_does_not_become_off(): void
    {
        AvailabilityRequest::query()->create(['employee_id' => $this->team[2]->getKey(), 'date_from' => $this->day(4), 'date_to' => $this->day(4), 'type' => 'off_request', 'status' => 'pending']);

        $this->assertNull($this->cell($this->team[2], $this->day(4)));
    }

    /** 4. Không cho OFF (sau khi đã ghi): OFF bị gỡ khỏi lịch. */
    public function test_rejected_request_leaves_no_off_on_grid(): void
    {
        $request = $this->record($this->team[0], 1, 1)['requests'][0];
        $this->offs()->withdraw($request, 1, 'Không cho OFF');
        $request->update(['status' => 'rejected']);

        $this->assertNull($this->cell($this->team[0], $this->day(1)));
    }

    /** Gỡ OFF một ngày (thiếu người): ô trống lại, thuật toán xếp được ca ngày đó; đơn hết ngày thì "Đã hủy". */
    public function test_removed_off_no_longer_blocks_scheduling(): void
    {
        $request = $this->record($this->team[0], 1, 1)['requests'][0];
        $this->offs()->removeCell($this->cell($this->team[0], $this->day(1)), 1, 'Thiếu người');

        $this->assertNull($this->cell($this->team[0], $this->day(1)));
        $this->assertSame('cancelled', $request->refresh()->status);
        $this->assertSame(1, ChangeLog::query()->where('action', 'off_remove')->count());

        $problem = app(\Botble\RestaurantRoster\Services\ProblemBuilder::class)->build(Carbon::parse($this->day(0)), Carbon::parse($this->day(6)));
        $this->assertArrayNotHasKey($this->day(1), $problem->hardUnavailable[$this->team[0]->getKey()] ?? [], 'Ngày đã gỡ OFF không còn bị chặn');
    }

    /** 6. Tối ưu lại: ca đã xác nhận (khóa) giữ nguyên. */
    public function test_reoptimize_keeps_confirmed_shift(): void
    {
        $this->runScheduler();
        $cell = Shift::query()->where('employee_id', $this->team[3]->getKey())->where('kind', Shift::KIND_SHIFT)->orderBy('date')->firstOrFail();
        $cell->update(['is_locked' => true, 'lock_reason' => Shift::LOCK_CONFIRMED]);
        $before = [$cell->shift_template_id, $cell->outlet_id];

        $this->record($this->team[4], 0, 1);
        $this->runScheduler('reoptimize');

        $after = $cell->refresh();
        $this->assertSame($before, [$after->shift_template_id, $after->outlet_id]);
        $this->assertTrue($after->is_locked);
    }

    /** 7. OFF mới trùng ca đã khóa: không đè, ô "cần xem xét"; quản lý chọn cho OFF thì ô thành OFF khóa. */
    public function test_new_off_on_locked_shift_requires_manager_decision(): void
    {
        $date = $this->day(2);
        $shift = Shift::query()->create(['employee_id' => $this->team[0]->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT,
            'shift_template_id' => $this->m6->getKey(), 'outlet_id' => $this->gusto->getKey(), 'status' => 'draft',
            'is_locked' => true, 'lock_reason' => Shift::LOCK_CONFIRMED, 'start_at' => "{$date} 06:00:00", 'end_at' => "{$date} 14:00:00", 'hours' => 8]);

        $result = $this->record($this->team[0], 2, 3);

        $this->assertSame(1, $result['written'], 'Chỉ ghi ngày không xung đột');
        $this->assertCount(1, $result['kept']);
        $shift->refresh();
        $this->assertSame(Shift::KIND_SHIFT, $shift->kind, 'Ca đã khóa giữ nguyên');
        $this->assertTrue($shift->needs_review);
        $this->assertSame(Shift::STATE_REVIEW, $shift->cellState());

        $this->offs()->resolveReview($shift, true, 1);
        $cell = $this->cell($this->team[0], $date);
        $this->assertSame(Shift::KIND_OFF, $cell->kind);
        $this->assertSame(Shift::LOCK_OFF_REQUEST, $cell->lock_reason);
        $this->assertFalse($cell->needs_review);
    }

    /** Giữ ca khi có OFF trùng: bỏ đánh dấu, ca vẫn khóa. */
    public function test_keep_shift_decision_clears_review(): void
    {
        $date = $this->day(5);
        $shift = Shift::query()->create(['employee_id' => $this->team[1]->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT,
            'shift_template_id' => $this->a2->getKey(), 'outlet_id' => $this->gusto->getKey(), 'status' => 'draft',
            'is_locked' => true, 'lock_reason' => Shift::LOCK_BQ, 'start_at' => "{$date} 14:00:00", 'end_at' => "{$date} 22:00:00", 'hours' => 8]);
        $this->record($this->team[1], 5, 5);

        $this->offs()->resolveReview($shift->refresh(), false, 1);

        $shift->refresh();
        $this->assertFalse($shift->needs_review);
        $this->assertSame(Shift::KIND_SHIFT, $shift->kind);
        $this->assertTrue($shift->is_locked);
    }

    /** Chuyển dữ liệu cũ: đơn đã duyệt → ô OFF khóa; ngày đang có ca → giữ ca, "cần xem xét"; ô khóa cũ có lý do khóa. */
    public function test_migration_backfills_approved_requests(): void
    {
        $path = 'platform/plugins/restaurant-roster/database/migrations/2026_10_21_000001_add_off_workflow_and_cell_states.php';
        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('roster_shifts', 'lock_reason'));

        $start = now()->addDays(3)->toDateString();
        $next = now()->addDays(4)->toDateString();
        $employee = $this->team[0]->getKey();
        \DB::table('roster_availability_requests')->insert(['employee_id' => $employee, 'date_from' => $start, 'date_to' => $next,
            'type' => 'off_request', 'status' => 'approved', 'reviewed_by' => 1, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('roster_shifts')->insert(['employee_id' => $employee, 'date' => $next, 'kind' => 'shift', 'shift_template_id' => $this->m6->getKey(),
            'outlet_id' => $this->gusto->getKey(), 'status' => 'draft', 'is_locked' => false, 'hours' => 8, 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('roster_shifts')->insert(['employee_id' => $this->team[1]->getKey(), 'date' => $start, 'kind' => 'shift', 'shift_template_id' => $this->a2->getKey(),
            'outlet_id' => $this->gusto->getKey(), 'status' => 'draft', 'is_locked' => true, 'tags' => json_encode(['BQ']), 'hours' => 8, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('migrate', ['--path' => $path, '--force' => true]);

        $off = $this->cell($this->team[0], $start);
        $this->assertTrue($off->isRecordedOff(), 'Ngày trống của đơn đã duyệt thành OFF khóa');
        $kept = $this->cell($this->team[0], $next);
        $this->assertSame(Shift::KIND_SHIFT, $kept->kind, 'Ca làm sẵn có không bị đè');
        $this->assertTrue($kept->needs_review);
        $this->assertSame(Shift::LOCK_BQ, $this->cell($this->team[1], $start)->lock_reason);
    }

    /** Hai đơn của cùng nhân viên trùng ngày được phát hiện. */
    public function test_duplicate_requests_are_detected(): void
    {
        $this->record($this->team[0], 1, 3);
        $conflicts = $this->offs()->conflicts([$this->team[0]->getKey()], $this->day(3), $this->day(4));

        $this->assertCount(1, $conflicts['duplicates']);
        $this->assertSame([], $this->offs()->conflicts([$this->team[1]->getKey()], $this->day(3), $this->day(4))['duplicates']);
    }

    /** Sửa đơn sang ngày khác: OFF cũ được gỡ, OFF mới được ghi. */
    public function test_editing_request_moves_off_cells(): void
    {
        $request = $this->record($this->team[0], 1, 1)['requests'][0];
        $this->offs()->withdraw($request, 1, 'Sửa đơn');
        $request->update(['date_from' => $this->day(4), 'date_to' => $this->day(4)]);
        $this->offs()->apply($request->refresh(), 1);

        $this->assertNull($this->cell($this->team[0], $this->day(1)));
        $this->assertTrue($this->cell($this->team[0], $this->day(4))->isRecordedOff());
    }
}
