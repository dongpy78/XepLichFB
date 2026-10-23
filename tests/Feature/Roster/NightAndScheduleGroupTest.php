<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\NightRotation;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ScheduleGroup;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Services\NightStaffService;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterStatistics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Night luân phiên theo tuần (đề xuất, outlet nguồn, duyệt, nhật ký), chặn điều chuyển giữa nhà hàng, nhóm hiển thị lịch,
 * thực tập sinh – DỮ LIỆU GIẢ LẬP.
 */
class NightAndScheduleGroupTest extends TestCase
{
    private const WEEK = '2030-03-04';

    /** @var array<string, Outlet> */
    private array $outlets = [];

    /** @var array<string, ShiftTemplate> */
    private array $templates = [];

    /** @var array<string, ScheduleGroup> */
    private array $groups = [];

    private Position $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(RestaurantRosterServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
        $this->withoutMiddleware(\Botble\Installer\Http\Middleware\RedirectIfNotInstalledMiddleware::class);
        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate', ['--path' => 'database/migrations', '--force' => true]);
            $this->artisan('migrate', ['--path' => 'platform/core/acl/database/migrations', '--force' => true]);
        }

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }

        foreach (['GUSTO' => 1, 'VILLA' => 2, 'NIGHT' => 3, 'ROOM' => 4] as $code => $order) {
            $this->outlets[$code] = Outlet::query()->create(['name' => ucfirst(strtolower($code)), 'code' => $code, 'order' => $order, 'status' => 'published']);
            $this->groups[$code] = ScheduleGroup::query()->create(['code' => $code, 'name' => ucfirst(strtolower($code)), 'outlet_id' => $this->outlets[$code]->getKey(), 'order' => $order]);
        }

        foreach (['M6' => ['06:00:00', '14:00:00'], 'A2' => ['14:00:00', '22:00:00'], 'N' => ['22:00:00', '06:00:00']] as $code => [$start, $end]) {
            $this->templates[$code] = ShiftTemplate::query()->create(['code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end, 'hours' => 8, 'status' => 'published']);
        }

        $this->waiter = Position::query()->create(['name' => 'Waiter', 'status' => 'published']);

        foreach (['GUSTO', 'VILLA'] as $outlet) {
            foreach (['M6', 'A2'] as $shift) {
                StaffingRequirement::query()->create(['outlet_id' => $this->outlets[$outlet]->getKey(), 'shift_template_id' => $this->templates[$shift]->getKey(),
                    'status' => 'active', 'min_staff' => 2, 'target_staff' => 3, 'max_staff' => 5, 'min_male' => 1, 'target_male' => 2, 'is_confirmed' => true]);
            }
        }

        StaffingRequirement::query()->create(['outlet_id' => $this->outlets['NIGHT']->getKey(), 'shift_template_id' => $this->templates['N']->getKey(),
            'status' => 'active', 'min_staff' => 3, 'target_staff' => 3, 'max_staff' => 3, 'is_confirmed' => true]);
    }

    private function employee(string $code, string $outlet, string $gender = 'male', array $extra = []): Employee
    {
        $employee = Employee::query()->create(['code' => $code, 'name' => "Synthetic {$code}", 'gender' => $gender, 'outlet_id' => $this->outlets[$outlet]->getKey(),
            'schedule_group_id' => $this->groups[$outlet]->getKey(), 'position_id' => $this->waiter->getKey(), 'weekly_hours' => 48, 'days_off_per_week' => 2,
            'auto_schedule' => true, 'status' => 'published', ...$extra]);

        EmployeeSkill::query()->create(['employee_id' => $employee->getKey(), 'outlet_id' => $this->outlets[$outlet]->getKey(), 'level' => 'primary']);

        return $employee;
    }

    private function nightSkill(Employee $employee): void
    {
        EmployeeSkill::query()->create(['employee_id' => $employee->getKey(), 'outlet_id' => $this->outlets['NIGHT']->getKey(),
            'shift_template_id' => $this->templates['N']->getKey(), 'level' => 'eligible']);
    }

    private function nightShift(Employee $employee, string $date): void
    {
        Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $this->templates['N']->getKey(),
            'outlet_id' => $this->outlets['NIGHT']->getKey(), 'status' => 'published', 'start_at' => "{$date} 22:00:00", 'end_at' => Carbon::parse($date)->addDay()->toDateString() . ' 06:00:00', 'hours' => 8]);
    }

    /**
     * Gusto, Villa: mỗi outlet 5 nam waiter (3 người đầu có năng lực Night) + 3 nữ – đủ ngày công khi rút người đi Night 2 đêm.
     *
     * @return array<string, Employee>
     */
    private function restaurants(int $villaMales = 5): array
    {
        $people = [];

        foreach (['GUSTO' => 5, 'VILLA' => $villaMales] as $outlet => $males) {
            for ($k = 1; $k <= $males; $k++) {
                $people[$outlet[0] . $k] = $this->employee($outlet[0] . $k, $outlet);
            }

            for ($k = 1; $k <= 3; $k++) {
                $this->employee($outlet[0] . 'F' . $k, $outlet, 'female');
            }

            foreach ([1, 2, 3] as $k) {
                if (isset($people[$outlet[0] . $k])) {
                    $this->nightSkill($people[$outlet[0] . $k]);
                }
            }
        }

        return $people;
    }

    private function user(): User
    {
        $user = new User();
        $user->forceFill(['email' => uniqid('syn') . '@example.test', 'username' => uniqid('syn'), 'password' => bcrypt('secret'),
            'first_name' => 'Synthetic', 'last_name' => 'Manager', 'super_user' => true, 'permissions' => []])->save();

        return $user;
    }

    private function selectedCodes(array $proposal): array
    {
        $codes = array_map(fn ($row) => $row['employee']->code, $proposal['selected']);
        sort($codes);

        return $codes;
    }

    /**
     * Điều chuyển (quản lý 23/10/2026: ưu tiên đủ người các outlet): waiter / hostess tự đủ điều kiện thay thế
     * ở Gusto ↔ Villa mà không cần khai năng lực; cấp quản lý, người có ca cứng, vị trí khác thì không.
     */
    public function test_waiters_and_hostess_may_transfer_between_restaurants(): void
    {
        $gustoWaiter = $this->employee('G1', 'GUSTO');
        $this->nightSkill($gustoWaiter);
        $hostess = $this->employee('GH', 'GUSTO', 'female', ['position_id' => Position::query()->create(['name' => 'Hostess', 'status' => 'published'])->getKey()]);
        $captain = $this->employee('GC', 'GUSTO', extra: ['position_id' => Position::query()->create(['name' => 'Captain', 'is_manager' => true, 'status' => 'published'])->getKey()]);
        $bartender = $this->employee('GB', 'GUSTO', extra: ['position_id' => Position::query()->create(['name' => 'Bartender', 'status' => 'published'])->getKey()]);

        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        [$villa, $gusto, $night, $m6, $n] = [$this->outlets['VILLA']->getKey(), $this->outlets['GUSTO']->getKey(), $this->outlets['NIGHT']->getKey(), $this->templates['M6']->getKey(), $this->templates['N']->getKey()];
        $level = fn (Employee $e, int $outlet, int $shift) => $problem->employees[$e->getKey()]->skillLevel($outlet, $shift);

        $this->assertSame('eligible', $level($gustoWaiter, $villa, $m6), 'Waiter Gusto được điều chuyển sang Villa');
        $this->assertSame('primary', $level($gustoWaiter, $gusto, $m6), 'Outlet chính vẫn là chuyên trách (ưu tiên)');
        $this->assertSame('eligible', $level($hostess, $villa, $m6), 'Hostess được điều chuyển');
        $this->assertNull($level($captain, $villa, $m6), 'Cấp quản lý không tự điều chuyển');
        $this->assertNull($level($bartender, $villa, $m6), 'Vị trí khác chỉ theo năng lực đã khai');
        $this->assertNotNull($level($gustoWaiter, $night, $n), 'Vẫn luân phiên Night được');
        $this->assertTrue($problem->employees[$gustoWaiter->getKey()]->countsAsMale(), 'Nam waiter');
    }

    /**
     * Quy tắc Night (23/10/2026) đi qua đường dữ liệu thật (database → ProblemBuilder → bộ giải): 2 Night cứng + 4 người luân phiên
     * đã duyệt; Night đúng 3 người / ngày, ≥ 1 Night cứng / ngày, mỗi người luân phiên 2–3 đêm liền nhau rồi OFF;
     * người luân phiên từ outlet Night cũng được chọn; Room Service chuyên trách không làm Night.
     */
    public function test_night_rules_end_to_end(): void
    {
        $people = $this->restaurants();

        foreach (['NC1', 'NC2'] as $code) {
            $this->nightSkill($this->employee($code, 'NIGHT', extra: ['fixed_shift_template_id' => $this->templates['N']->getKey()]));
        }

        $nightWaiter = $this->employee('NW1', 'NIGHT');
        $this->nightSkill($nightWaiter);
        $planned = [$people['G1'], $people['G2'], $people['V1'], $nightWaiter];
        app(NightStaffService::class)->save(Carbon::parse(self::WEEK), array_map(fn ($e) => $e->getKey(), $planned), NightRotation::STATUS_APPROVED, 1);

        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6), null, ['time_limit_seconds' => 90, 'max_iterations' => 200000]);
        $result = (new \Botble\RestaurantRoster\Scheduling\HeuristicSolver())->solve($problem);
        $n = $this->templates['N']->getKey();
        $core = Employee::query()->whereIn('code', ['NC1', 'NC2'])->pluck('id')->all();
        $at = fn (int $id, string $date) => collect($result->assignments)->first(fn ($r) => $r['employee_id'] === $id && $r['date'] === $date && $r['kind'] === 'shift');

        foreach (\Carbon\CarbonPeriod::create(self::WEEK, Carbon::parse(self::WEEK)->addDays(6)) as $day) {
            $night = array_filter($result->assignments, fn ($r) => $r['date'] === $day->toDateString() && $r['shift_id'] === $n);
            $this->assertCount(3, $night, $day->format('d/m') . ': Night đúng 3 người');
            $this->assertNotEmpty(array_filter($night, fn ($r) => in_array($r['employee_id'], $core, true)), $day->format('d/m') . ': có Night cứng');
        }

        foreach ($planned as $employee) {
            $dates = collect($result->assignments)->filter(fn ($r) => $r['employee_id'] === $employee->getKey() && $r['shift_id'] === $n)->pluck('date')->sort()->values();
            $this->assertGreaterThanOrEqual(2, $dates->count(), "{$employee->code}: ít nhất 2 đêm");
            $this->assertLessThanOrEqual(5, $dates->count(), "{$employee->code}: tối đa 5 đêm");
            $this->assertSame($dates->count() - 1, (int) Carbon::parse($dates->first())->diffInDays(Carbon::parse($dates->last())), "{$employee->code}: các đêm liền nhau");
            $after = Carbon::parse($dates->last())->addDay()->toDateString();
            $this->assertTrue($after > Carbon::parse(self::WEEK)->addDays(6)->toDateString() || $at($employee->getKey(), $after) === null, "{$employee->code}: hôm sau đêm cuối OFF");
        }

        $this->assertSame(0, (int) ($result->solverMetadata['objective']['hard'] ?? 0));
    }

    /** 9.1: danh sách luân phiên loại Night cứng, Room Service chuyên trách, người đã tắt luân phiên; nhận cả nam waiter outlet Night. */
    public function test_rotation_pool_excludes_core_room_service_and_disabled(): void
    {
        $people = $this->restaurants();
        $this->nightSkill($this->employee('NC1', 'NIGHT', extra: ['fixed_shift_template_id' => $this->templates['N']->getKey()]));
        $this->nightSkill($this->employee('NW1', 'NIGHT'));
        app(\Botble\RestaurantRoster\Services\RoomServiceStaffService::class)->save(['employee_id' => $people['G3']->getKey(), 'role' => 'lead',
            'shift_template_id' => $this->templates['M6']->getKey(), 'effective_from' => '2030-01-01'], 1);
        $service = app(NightStaffService::class);
        $service->setRotationEnabled($people['V3'], false, 1, 'Xin tạm nghỉ luân phiên');

        $groups = $service->groups(Carbon::parse(self::WEEK));
        $codes = fn ($list) => $list->pluck('code')->sort()->values()->all();

        $this->assertSame(['G1', 'G2', 'NW1', 'V1', 'V2'], $codes($groups['rotation']));
        $this->assertSame(['NC1'], $codes($groups['core']));
        $this->assertSame(['G3'], $codes($groups['room_service']));
        $this->assertSame(['V3'], $codes($groups['disabled']));
        $this->assertNotNull(ChangeLog::query()->where('employee_id', $people['V3']->getKey())->where('action', 'night_rotation')->first(), 'Bật / tắt có nhật ký');
    }

    /** 9.14 + 9.15: đổi người chuyên trách Room Service – giữ lịch sử, liệt kê ca cần xem lại, không tự sửa lịch đã công bố. */
    public function test_changing_room_service_lead_keeps_history_and_schedule(): void
    {
        $service = app(\Botble\RestaurantRoster\Services\RoomServiceStaffService::class);
        $old = $this->employee('R1', 'ROOM');
        $new = $this->employee('R2', 'ROOM');
        $m6 = $this->templates['M6']->getKey();
        $service->save(['employee_id' => $old->getKey(), 'role' => 'lead', 'shift_template_id' => $m6, 'effective_from' => '2030-03-01'], 1);
        $published = Shift::query()->create(['employee_id' => $old->getKey(), 'date' => '2030-03-10', 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $m6,
            'outlet_id' => $this->outlets['ROOM']->getKey(), 'status' => 'published', 'start_at' => '2030-03-10 06:00:00', 'end_at' => '2030-03-10 14:00:00', 'hours' => 8]);

        $result = $service->save(['employee_id' => $new->getKey(), 'role' => 'lead', 'shift_template_id' => $m6, 'effective_from' => '2030-03-08', 'note' => 'Đổi người'], 1);

        $this->assertSame('2030-03-07', $result['closed']->effective_to->toDateString(), 'Người cũ kết thúc hôm trước ngày đổi');
        $this->assertSame(2, \Botble\RestaurantRoster\Models\RoomServiceStaff::query()->count(), 'Không xóa dòng cũ');
        $this->assertNotEmpty(array_filter($result['affected'], fn ($a) => $a['date'] === '2030-03-10' && $a['published']), 'Liệt kê ca đã công bố cần xem lại');
        $this->assertSame($old->getKey(), (int) $published->fresh()->employee_id, 'Lịch đã công bố không bị tự sửa');
        $this->assertSame(2, ChangeLog::query()->where('action', 'room_service')->where('employee_id', $old->getKey())->count());

        $problem = app(ProblemBuilder::class)->build(Carbon::parse('2030-03-11'), Carbon::parse('2030-03-17'));
        $room = $this->outlets['ROOM']->getKey();
        $this->assertSame([$room . ':' . $m6], $problem->employees[$new->getKey()]->fixed, 'Người mới chỉ làm Room Service M6');
        $this->assertSame([], $problem->employees[$old->getKey()]->fixed, 'Người cũ hết chuyên trách: không còn bị giới hạn ca Room Service');
    }

    /** 9.17: đề xuất nhiều tuần liền (chưa duyệt) chia đều lượt Night cho mọi người đủ điều kiện. */
    public function test_rotation_is_fair_over_several_weeks(): void
    {
        $this->restaurants();
        $plan = app(NightStaffService::class)->planFor(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addWeeks(3)->subDay());
        $counts = [];

        foreach ($plan['weeks'] as $week => $info) {
            $this->assertCount(4, $info['ids'], "Tuần {$week}: đủ 4 người");
            $this->assertSame([], $info['warnings']);

            foreach ($info['ids'] as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        $this->assertCount(6, $counts, 'Cả 6 người đủ điều kiện đều được luân phiên');
        $this->assertLessThanOrEqual(1, max($counts) - min($counts), '3 tuần × 4 người chia đều cho 6 người');
    }

    /** Nhân viên đa năng = người thuộc outlet Night trừ Night cứng và Room Service chuyên trách (cả nam và nữ). */
    public function test_flex_staff_are_night_outlet_people_except_core_and_room_leads(): void
    {
        $core = $this->employee('NC1', 'NIGHT', extra: ['fixed_shift_template_id' => $this->templates['N']->getKey()]);
        $male = $this->employee('NW1', 'NIGHT');
        $female = $this->employee('NW2', 'NIGHT', 'female');
        $lead = $this->employee('NR1', 'NIGHT');
        $villa = $this->employee('V1', 'VILLA');
        app(\Botble\RestaurantRoster\Services\RoomServiceStaffService::class)->save(['employee_id' => $lead->getKey(), 'role' => 'lead',
            'shift_template_id' => $this->templates['A2']->getKey(), 'effective_from' => '2030-01-01'], 1);

        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $flex = fn (Employee $e) => $problem->employees[$e->getKey()]->isNightFlex();

        $this->assertSame([false, true, true, false, false], [$flex($core), $flex($male), $flex($female), $flex($lead), $flex($villa)]);
    }

    /** Trước khi công bố: chặn khi có người trùng giờ / thiếu giờ nghỉ (vd N rồi M6 sáng hôm sau), nêu rõ người và ngày. */
    public function test_publish_is_blocked_when_someone_lacks_rest(): void
    {
        $employee = $this->employee('V1', 'VILLA');
        $this->nightShift($employee, '2030-03-04');
        Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => '2030-03-05', 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $this->templates['M6']->getKey(),
            'outlet_id' => $this->outlets['VILLA']->getKey(), 'status' => 'draft', 'start_at' => '2030-03-05 06:00:00', 'end_at' => '2030-03-05 14:00:00', 'hours' => 8]);

        $response = $this->actingAs($this->user())->postJson(route('roster.publish'), ['week' => self::WEEK]);

        $response->assertJson(['error' => true]);
        $this->assertStringContainsString('Synthetic V1 05/03', $response->json('message'));
        $this->assertSame('draft', Shift::query()->whereDate('date', '2030-03-05')->value('status'), 'Chưa công bố');
    }

    /**
     * Migration 24/10: Lê Trần Anh Quốc và Châu Quang Tiến vào danh sách luân phiên Night (thêm năng lực Night nếu thiếu, không tạo trùng
     * nhân viên / năng lực); người có năng lực Night mà không được chọn thì hiện lý do.
     */
    public function test_requested_staff_join_night_rotation_without_duplicates(): void
    {
        $path = 'platform/plugins/restaurant-roster/database/migrations/2026_10_24_000001_add_night_skill_for_requested_staff.php';
        $quoc = $this->employee('LQ', 'GUSTO', extra: ['name' => 'Lê Trần Anh Quốc', 'position_id' => Position::query()->create(['name' => 'Night Waiter', 'status' => 'published'])->getKey()]);
        $tien = $this->employee('CT', 'VILLA', extra: ['name' => 'Châu Quang Tiến', 'night_rotation_enabled' => false]);
        $this->nightSkill($tien);
        $captain = $this->employee('NX', 'VILLA', extra: ['fixed_shift_template_id' => $this->templates['M6']->getKey()]);
        $this->nightSkill($captain);

        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true]);
        $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true]);
        $this->artisan('migrate', ['--path' => $path, '--force' => true]);

        $groups = app(NightStaffService::class)->groups(Carbon::parse(self::WEEK));
        $this->assertEqualsCanonicalizing(['LQ', 'CT'], $groups['rotation']->pluck('code')->all(), 'Hai người có trong danh sách luân phiên (vị trí khác Waiter vẫn được vì đã có năng lực Night)');
        $this->assertSame(1, Employee::query()->where('name', 'Lê Trần Anh Quốc')->count(), 'Không tạo nhân viên trùng');
        $this->assertSame(1, EmployeeSkill::query()->where('employee_id', $tien->getKey())->where('outlet_id', $this->outlets['NIGHT']->getKey())->count(), 'Không tạo năng lực trùng');
        $this->assertStringContainsString('ca cứng', $groups['ineligible']->first(fn ($r) => $r['employee']->code === 'NX')['reason']);
    }

    /** Reset lịch Night: chỉ ca đêm thuật toán xếp (nháp, chưa khóa) và luân phiên đề xuất của tuần đang chọn; còn lại giữ nguyên, có nhật ký. */
    public function test_reset_night_week_only_touches_engine_drafts_of_that_week(): void
    {
        $people = $this->restaurants();
        $n = $this->templates['N']->getKey();
        $cell = fn (Employee $e, string $date, array $extra = [], string $outlet = 'NIGHT', ?int $template = null) => Shift::query()->create([
            'employee_id' => $e->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $template ?? $n, 'outlet_id' => $this->outlets[$outlet]->getKey(),
            'status' => 'draft', 'source' => 'engine', 'start_at' => "{$date} 22:00:00", 'end_at' => "{$date} 23:59:00", 'hours' => 8, ...$extra]);
        $engine = $cell($people['G1'], '2030-03-04');
        $published = $cell($people['G2'], '2030-03-05', ['status' => 'published']);
        $locked = $cell($people['G3'], '2030-03-06', ['is_locked' => true]);
        $manual = $cell($people['V1'], '2030-03-07', ['source' => 'manual']);
        $otherWeek = $cell($people['G1'], '2030-03-11');
        $otherOutlet = $cell($people['V2'], '2030-03-04', [], 'VILLA', $this->templates['M6']->getKey());
        $off = Shift::query()->create(['employee_id' => $people['V3']->getKey(), 'date' => '2030-03-05', 'kind' => Shift::KIND_OFF, 'status' => 'published', 'is_locked' => true]);
        NightRotation::query()->insert([
            ['week_start' => self::WEEK, 'employee_id' => $people['G1']->getKey(), 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()],
            ['week_start' => self::WEEK, 'employee_id' => $people['G2']->getKey(), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
            ['week_start' => '2030-03-11', 'employee_id' => $people['G3']->getKey(), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $user = $this->user();

        $this->actingAs($user)->post(route('roster.night.reset'), ['week' => '2030-03-06'])->assertSessionHasErrors('confirm');
        $this->assertNotNull($engine->fresh(), 'Chưa xác nhận thì không reset');

        $this->actingAs($user)->post(route('roster.night.reset'), ['week' => '2030-03-06', 'confirm' => 1])->assertRedirect();
        $this->assertNull($engine->fresh(), 'Xóa ca đêm do thuật toán xếp');
        foreach ([$published, $locked, $manual, $otherWeek, $otherOutlet, $off] as $kept) {
            $this->assertNotNull($kept->fresh(), 'Giữ ca đã công bố / khóa / xếp tay / tuần khác / outlet khác / OFF');
        }
        $this->assertSame(['approved'], NightRotation::query()->whereDate('week_start', self::WEEK)->pluck('status')->all(), 'Danh sách đã duyệt chỉ xóa khi tích xác nhận');
        $this->assertNotNull(ChangeLog::query()->where('action', 'night_reset')->where('employee_id', $people['G1']->getKey())->first(), 'Có nhật ký');

        $this->actingAs($user)->post(route('roster.night.reset'), ['week' => self::WEEK, 'confirm' => 1, 'include_approved' => 1])->assertRedirect();
        $this->assertSame(0, NightRotation::query()->whereDate('week_start', self::WEEK)->count());
        $this->assertSame(1, NightRotation::query()->whereDate('week_start', '2030-03-11')->count(), 'Tuần khác giữ nguyên');
        $this->assertSame(1, Employee::query()->whereKey($people['G1']->getKey())->count(), 'Không xóa nhân viên');

        // Chạy lại sau reset: không trùng ô (mỗi người một ô / ngày).
        app(NightStaffService::class)->save(Carbon::parse(self::WEEK), [$people['G1']->getKey()], NightRotation::STATUS_APPROVED, 1);
        $this->assertSame(1, NightRotation::query()->whereDate('week_start', self::WEEK)->count());
    }

    /** Backend từ chối áp dụng phương án tự động có ca "OH" (OH ở nhà hàng), kể cả khi dữ liệu phương án bị sửa. */
    public function test_apply_rejects_auto_plan_with_plain_oh(): void
    {
        $employee = $this->employee('V1', 'VILLA');
        $oh = ShiftTemplate::query()->create(['code' => 'OH', 'name' => 'OH', 'start_time' => '08:45:00', 'end_time' => '16:45:00', 'hours' => 8, 'status' => 'published']);
        $scheduler = app(\Botble\RestaurantRoster\Services\RosterScheduler::class);
        $run = \Botble\RestaurantRoster\Models\Run::query()->create(['type' => 'generate', 'scope_from' => self::WEEK, 'scope_to' => '2030-03-10', 'status' => 'feasible',
            'solver' => 'test', 'settings' => ['schedule_stamp' => $scheduler->scheduleStamp(Carbon::parse(self::WEEK), Carbon::parse('2030-03-10'))],
            'proposal' => ['assignments' => [
                ['employee_id' => $employee->getKey(), 'date' => self::WEEK, 'kind' => 'shift', 'outlet_id' => $this->outlets['VILLA']->getKey(), 'shift_id' => $oh->getKey(), 'support' => false, 'locked' => false],
            ]]]);

        try {
            $scheduler->apply($run, 1);
            $this->fail('Phải từ chối ca OH');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OH', $e->getMessage());
        }

        $this->assertSame(0, Shift::query()->count(), 'Không ghi gì khi bị từ chối');

        // Cùng phương án nhưng là OH(L) (hỗ trợ Lobby) → hợp lệ.
        $run->update(['proposal' => ['assignments' => [['employee_id' => $employee->getKey(), 'date' => self::WEEK, 'kind' => 'shift', 'outlet_id' => $this->outlets['VILLA']->getKey(), 'shift_id' => $oh->getKey(), 'support' => true, 'locked' => false]]]]);
        $this->assertSame([], $scheduler->disallowedCells($run->fresh(), ShiftTemplate::query()->get()->keyBy('id')));
    }

    /** 9.9: đề xuất 4 người ít tuần Night nhất, chia giữa các outlet. */
    public function test_rotation_prefers_least_history_and_balances_outlets(): void
    {
        $people = $this->restaurants();
        $this->nightShift($people['G1'], '2030-02-26');   // tuần liền trước
        $this->nightShift($people['V1'], '2030-02-12');   // 3 tuần trước

        $proposal = app(NightStaffService::class)->propose(Carbon::parse(self::WEEK));

        $this->assertSame(['G2', 'G3', 'V2', 'V3'], $this->selectedCodes($proposal), 'G1 vừa làm tuần trước, V1 đã làm 3 tuần trước');
        $this->assertSame([], $proposal['warnings']);
    }

    /** 9.10: tuần sau không lặp lại người vừa làm khi còn lựa chọn hợp lệ khác. */
    public function test_rotation_does_not_repeat_people_in_consecutive_weeks(): void
    {
        $people = $this->restaurants();
        $service = app(NightStaffService::class);
        $first = ['G2', 'G3', 'V2', 'V3'];
        $service->save(Carbon::parse(self::WEEK), array_map(fn ($c) => $people[$c]->getKey(), $first), NightRotation::STATUS_APPROVED, 1);

        $next = $this->selectedCodes($service->propose(Carbon::parse(self::WEEK)->addWeek()));

        $this->assertContains('G1', $next, 'Người chưa làm tuần trước được ưu tiên');
        $this->assertContains('V1', $next);
        $this->assertLessThanOrEqual(2, count(array_intersect($next, $first)), 'Chỉ lặp lại người tuần trước khi thiếu người hợp lệ khác');
        $plan = $service->planFor(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(13));
        $this->assertSame('approved', $plan['weeks'][self::WEEK]['source']);
        $this->assertSame('proposed', $plan['weeks']['2030-03-11']['source']);
        $this->assertContains($people['G1']->getKey(), $plan['plan']['2030-03-12']);
    }

    /** 9.11: không chọn người mà outlet nguồn sẽ thiếu nam waiter tối thiểu – nêu rõ lý do. */
    public function test_rotation_skips_people_whose_outlet_would_fall_below_minimum(): void
    {
        $this->restaurants(villaMales: 3);
        $proposal = app(NightStaffService::class)->propose(Carbon::parse(self::WEEK));

        $this->assertSame(['G1', 'G2', 'G3'], $this->selectedCodes($proposal), 'Villa chỉ 3 nam waiter: không rút ai');
        $this->assertNotEmpty($proposal['warnings'], 'Không đủ 4 người hợp lệ → cảnh báo');
        $this->assertStringContainsString('3 / 4', $proposal['warnings'][0]);
        $reasons = collect($proposal['excluded'])->mapWithKeys(fn ($row) => [$row['employee']->code => $row['reason']]);
        $this->assertStringContainsString('VILLA sẽ thiếu nam waiter', $reasons['V1']);
    }

    /** 9.17: chọn người bị loại phải có lý do và được ghi nhật ký; tuần đã công bố không bị ghi đè. */
    public function test_exceptions_require_reason_are_audited_and_published_week_is_locked(): void
    {
        $people = $this->restaurants(villaMales: 3);
        $service = app(NightStaffService::class);

        try {
            $service->save(Carbon::parse(self::WEEK), [$people['V1']->getKey()], NightRotation::STATUS_APPROVED, 1);
            $this->fail('Ngoại lệ không có lý do phải bị từ chối');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lý do', $e->getMessage());
        }

        $service->save(Carbon::parse(self::WEEK), [$people['V1']->getKey(), $people['G1']->getKey()], NightRotation::STATUS_PUBLISHED, 1, 'Nhân viên xin đổi tuần');

        $log = ChangeLog::query()->where('employee_id', $people['V1']->getKey())->where('action', 'night_rotation')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('thiếu nam waiter', (string) $log->after['exception']);
        $this->assertStringContainsString('Ngoại lệ', (string) NightRotation::query()->where('employee_id', $people['V1']->getKey())->value('note'));

        $this->expectException(InvalidArgumentException::class);
        $service->save(Carbon::parse(self::WEEK), [$people['G2']->getKey()], NightRotation::STATUS_APPROVED, 1);
    }

    /** 9.12: người luân phiên Night vẫn nằm trong nhóm hiển thị của mình trên Bảng xếp ca, có đánh dấu luân phiên. */
    public function test_rotation_staff_stay_in_their_schedule_group_on_the_grid(): void
    {
        $people = $this->restaurants();
        app(NightStaffService::class)->save(Carbon::parse(self::WEEK), [$people['V2']->getKey()], NightRotation::STATUS_APPROVED, 1);
        $this->nightShift($people['V2'], '2030-03-05');

        $html = $this->actingAs($this->user())->getJson(route('roster.grid', ['week' => self::WEEK]))->assertJson(['error' => false])->json('data.html');

        $this->assertSame($this->groups['VILLA']->getKey(), (int) $people['V2']->fresh()->schedule_group_id);
        $villaAt = strpos($html, '>VILLA<');
        $nightAt = strpos($html, '>NIGHT<');
        $rowAt = strpos($html, 'Synthetic V2');
        $this->assertTrue($villaAt !== false && $rowAt > $villaAt && ($nightAt === false || $rowAt < $nightAt), 'Hàng V2 nằm trong khu Villa');
        $this->assertStringContainsString('N luân phiên', $html);
    }

    /** 9.13 + 9.14: quản lý thêm nhóm Trainer qua giao diện; thực tập sinh không được tính vào định biên. */
    public function test_trainer_group_added_without_code_and_trainees_not_counted(): void
    {
        $this->actingAs($user = $this->user())->post(route('roster.schedule-groups.create.store'), [
            'name' => 'Trainer', 'code' => 'trainer', 'order' => 99, 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $trainer = ScheduleGroup::query()->where('code', 'TRAINER')->firstOrFail();
        $trainee = $this->employee('T1', 'VILLA', extra: ['is_trainee' => true, 'schedule_group_id' => $trainer->getKey(), 'training_to' => '2030-04-30']);
        $staff = $this->employee('V9', 'VILLA');

        foreach ([$trainee, $staff] as $employee) {
            Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => self::WEEK, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $this->templates['M6']->getKey(),
                'outlet_id' => $this->outlets['VILLA']->getKey(), 'status' => 'published', 'start_at' => self::WEEK . ' 06:00:00', 'end_at' => self::WEEK . ' 14:00:00', 'hours' => 8]);
        }

        $html = $this->actingAs($user)->getJson(route('roster.grid', ['week' => self::WEEK]))->json('data.html');
        $this->assertStringContainsString('>TRAINER<', $html);
        $this->assertStringContainsString('Thực tập', $html);

        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $this->assertArrayNotHasKey($trainee->getKey(), $problem->employees, 'Thực tập sinh không vào bài toán xếp lịch');

        $stats = (new RosterStatistics())->onDuty(Carbon::parse(self::WEEK), Outlet::query()->get());
        $this->assertSame(1, $stats['cells'][self::WEEK][$this->outlets['VILLA']->getKey()]['M6']['total'], 'Chỉ tính nhân viên chính thức');
    }

    /** Các màn hình mới mở được: Nhân sự Night (đề xuất, định biên từng ngày), Nhóm hiển thị lịch, hồ sơ nhân viên. */
    public function test_management_pages_render(): void
    {
        $people = $this->restaurants();
        // Night cứng = ca cứng ca đêm (không cần chọn vai trò).
        $this->employee('NC1', 'NIGHT', extra: ['fixed_shift_template_id' => $this->templates['N']->getKey()]);
        $this->assertSame(['NC1'], app(NightStaffService::class)->groups()['core']->pluck('code')->all());
        $user = $this->user();

        $this->actingAs($user)->get(route('roster.night.index', ['week' => self::WEEK]))->assertOk()
            ->assertSee('Night cứng')->assertSee('Synthetic NC1')->assertSee('Đề xuất')->assertSee('Night từng ngày');
        $this->actingAs($user)->post(route('roster.night.save'), ['week' => self::WEEK, 'employee_ids' => [$people['G2']->getKey()], 'status' => 'preview'])
            ->assertOk()->assertSee('Xem trước ảnh hưởng')->assertSee('quy tắc là 4 người');
        $this->assertSame(0, NightRotation::query()->count(), 'Xem trước không lưu');
        $this->actingAs($user)->post(route('roster.night.save'), ['week' => self::WEEK, 'employee_ids' => [$people['G2']->getKey()], 'status' => 'approved'])->assertRedirect();
        $this->assertSame(1, NightRotation::query()->where('status', 'approved')->count());
        $this->actingAs($user)->get(route('roster.schedule-groups.index'))->assertOk();
        $this->actingAs($user)->get(route('roster.schedule-groups.create'))->assertOk();
        $this->actingAs($user)->get(route('roster.room-service.index'))->assertOk()->assertSee('Thêm / đổi người');
        // Botble mã hóa chữ có dấu trong nhãn form (ò → &ograve;): so khớp sau khi giải mã.
        $html = html_entity_decode($this->actingAs($user)->get(route('roster.employees.edit', $people['G1']->getKey()))->assertOk()->getContent());
        $this->assertStringContainsString('Tham gia luân phiên Night', $html);
        $this->assertStringContainsString('Thực tập sinh', $html);
        $this->assertStringContainsString('Nhóm hiển thị trên Bảng xếp ca', $html);
    }

    /** Migration: mỗi outlet đang có nhân viên thành một nhóm hiển thị; nhân viên Night không có ca cứng N → đa năng (ca cứng N = cố định). */
    public function test_migration_creates_groups_from_outlets_and_night_roles(): void
    {
        $path = 'platform/plugins/restaurant-roster/database/migrations/2026_10_23_000001_add_schedule_groups_and_night_roles.php';
        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true]);

        $insert = fn (string $code, string $outlet, ?int $fixed = null) => \Illuminate\Support\Facades\DB::table('roster_employees')->insertGetId([
            'code' => $code, 'name' => $code, 'outlet_id' => $this->outlets[$outlet]->getKey(), 'fixed_shift_template_id' => $fixed,
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => 1, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $core = $insert('NC', 'NIGHT', $this->templates['N']->getKey());
        $flex = $insert('NF', 'NIGHT');
        $villa = $insert('VW', 'VILLA');

        $this->artisan('migrate', ['--path' => $path, '--force' => true]);

        $this->assertSame(['NIGHT', 'VILLA'], ScheduleGroup::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame([null, 'flex', null], [Employee::query()->find($core)->night_role, Employee::query()->find($flex)->night_role, Employee::query()->find($villa)->night_role]);
        $this->assertSame((int) ScheduleGroup::query()->where('code', 'VILLA')->value('id'), (int) Employee::query()->find($villa)->schedule_group_id);
        $this->assertSame(2, (int) StaffingRequirement::query()->where('outlet_id', $this->outlets['VILLA']->getKey())->value('target_male'));
    }

    /** 9.17: đổi vai trò Night / thực tập sinh trong hồ sơ được ghi nhật ký. */
    public function test_profile_changes_are_audited(): void
    {
        $employee = $this->employee('N2', 'NIGHT');

        $this->actingAs($this->user())->post(route('roster.employees.edit.update', $employee->getKey()), [
            'code' => 'N2', 'name' => 'Synthetic N2', 'outlet_id' => $this->outlets['NIGHT']->getKey(), 'employment_type' => 'FT',
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'night_rotation_enabled' => 0, 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($employee->fresh()->night_rotation_enabled);
        $log = ChangeLog::query()->where('employee_id', $employee->getKey())->where('action', 'profile')->first();
        $this->assertNotNull($log);
        $this->assertSame(0, (int) $log->after['night_rotation_enabled']);
    }
}
