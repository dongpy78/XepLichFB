<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ScheduleGroup;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterStatistics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Night chỉ tự xếp cho Night cố định (hồ sơ), Night luân phiên xếp tay; Room Service theo cờ trong hồ sơ; điều chuyển giữa nhà hàng,
 * nhóm hiển thị lịch, thực tập sinh – DỮ LIỆU GIẢ LẬP (quản lý 29/10/2026).
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
        $this->assertNull($level($gustoWaiter, $night, $n), 'Night luân phiên không còn tự động – kể cả có dòng năng lực Night');
        $this->assertTrue($problem->employees[$gustoWaiter->getKey()]->countsAsMale(), 'Nam waiter');
    }

    /** Ca mở M6 / A2 của nhà hàng: mục tiêu ≥ 1 nam và ≥ 1 nữ waiter mỗi ca (outlet có từ 2 người của giới đó); Night không bị đổi. */
    public function test_open_slots_target_one_male_and_one_female(): void
    {
        $this->restaurants();
        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $gusto = $this->outlets['GUSTO']->getKey();
        $night = $this->outlets['NIGHT']->getKey();

        foreach ($problem->requirements as $r) {
            if ($r->outletId === $gusto && in_array($r->shiftId, [$this->templates['M6']->getKey(), $this->templates['A2']->getKey()], true)) {
                $this->assertSame([0, 0, 1, 1], [$r->min, $r->target, $r->targetMale, $r->targetFemale], "{$r->date}: ca mở Gusto");
            }

            if ($r->outletId === $night) {
                $this->assertSame(0, $r->targetFemale, 'Night không có mục tiêu nữ');
            }
        }

        config(['plugins.restaurant-roster.general.scheduling.open_slot_gender_presence' => false]);
        $plain = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $this->assertSame([], array_filter($plain->requirements, fn ($r) => $r->outletId === $gusto && $r->targetMale > 0), 'Tắt được bằng cấu hình');
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

    /** Outlet Room Service + định biên Room Service M6 tối thiểu 1. */
    private function roomService(): int
    {
        StaffingRequirement::query()->create(['outlet_id' => $this->outlets['ROOM']->getKey(), 'shift_template_id' => $this->templates['M6']->getKey(),
            'status' => 'active', 'min_staff' => 1, 'target_staff' => 1, 'max_staff' => 1, 'is_confirmed' => true]);

        return $this->outlets['ROOM']->getKey();
    }

    private function solve(): \Botble\RestaurantRoster\Scheduling\SolveResult
    {
        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6), null, ['time_limit_seconds' => 60, 'max_iterations' => 120000]);

        return (new \Botble\RestaurantRoster\Scheduling\HeuristicSolver())->solve($problem);
    }

    /**
     * Night tự động chỉ cho Night cố định (bật trong hồ sơ, hoặc ca cứng là ca đêm): ≥ 1 Night cố định mỗi đêm; người không bật
     * (kể cả có dòng Ngoại lệ năng lực Night) không bao giờ được tự xếp ca đêm, không có ai bị điều từ Villa / Gusto sang Night.
     */
    public function test_only_night_core_is_scheduled_automatically(): void
    {
        $people = $this->restaurants();
        $coreA = $this->employee('NC1', 'NIGHT', extra: ['night_role' => Employee::NIGHT_CORE]);
        $coreB = $this->employee('NC2', 'NIGHT', extra: ['fixed_shift_template_id' => $this->templates['N']->getKey()]);
        $nightWaiter = $this->employee('NW1', 'NIGHT');
        $this->nightSkill($nightWaiter);
        $n = $this->templates['N']->getKey();
        $night = $this->outlets['NIGHT']->getKey();

        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $this->assertTrue($problem->employees[$coreA->getKey()]->isNightCore(), 'Bật Night cố định trong hồ sơ');
        $this->assertTrue($problem->employees[$coreB->getKey()]->isNightCore(), 'Dữ liệu cũ: ca cứng ca đêm');
        $this->assertNotNull($problem->employees[$coreA->getKey()]->skillLevel($night, $n));
        $this->assertNull($problem->employees[$coreA->getKey()]->skillLevel($this->outlets['VILLA']->getKey(), $this->templates['M6']->getKey()), 'Night cố định chỉ làm ca đêm');
        $this->assertNull($problem->employees[$nightWaiter->getKey()]->skillLevel($night, $n), 'Không tự xếp Night cho người không bật');
        $this->assertNull($problem->employees[$people['G1']->getKey()]->skillLevel($night, $n));
        $this->assertSame([], $problem->nightPlan, 'Không còn kế hoạch luân phiên');
        $this->assertSame([], array_filter($problem->employees, fn ($e) => $e->nightRole === \Botble\RestaurantRoster\Scheduling\EmployeeSpec::NIGHT_ROTATION));

        $needs = array_values(array_unique(array_map(fn ($r) => [$r->min, $r->target], array_filter($problem->requirements, fn ($r) => $r->shiftId === $n)), SORT_REGULAR));
        $this->assertSame([[1, 2]], $needs, 'Night: tối thiểu 1 Night cố định, mục tiêu = số Night cố định (không đòi 3 người tự động)');

        $result = (new \Botble\RestaurantRoster\Scheduling\HeuristicSolver())->solve($problem);
        $onNight = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['shift_id'] === $n);
        $this->assertSame([], array_values(array_diff(array_unique(array_column($onNight, 'employee_id')), [$coreA->getKey(), $coreB->getKey()])), 'Chỉ Night cố định làm ca đêm');

        foreach (range(0, 6) as $d) {
            $date = Carbon::parse(self::WEEK)->addDays($d)->toDateString();
            $this->assertNotEmpty(array_filter($onNight, fn ($r) => $r['date'] === $date), "{$date}: có Night cố định");
        }

        $this->assertSame(0, (int) ($result->solverMetadata['objective']['hard'] ?? 0));
    }

    /** Night cố định có OFF đã duyệt cả hai người cùng một đêm: không lấy người khác thay, có cảnh báo để quản lý xử lý. */
    public function test_missing_night_core_is_warned_not_replaced(): void
    {
        $this->restaurants();
        $cores = [$this->employee('NC1', 'NIGHT', extra: ['night_role' => Employee::NIGHT_CORE]), $this->employee('NC2', 'NIGHT', extra: ['night_role' => Employee::NIGHT_CORE])];
        $date = Carbon::parse(self::WEEK)->addDays(2)->toDateString();

        foreach ($cores as $core) {
            app(\Botble\RestaurantRoster\Services\OffRequestService::class)->record([$core->getKey()], ['date_from' => $date, 'date_to' => $date, 'type' => 'off_request'], 1);
        }

        $result = $this->solve();
        $n = $this->templates['N']->getKey();

        $this->assertSame([], array_values(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['shift_id'] === $n)), 'Không ai làm Night thay');
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $date && $u['level'] === 'min'
            && ($u['shift_id'] === $n || $u['shift_code'] === 'Night cố định')));
        $this->assertNotEmpty($gap, 'Cảnh báo thiếu Night cố định');
        $this->assertFalse(collect($result->supportReport['day_checks'])->firstWhere('date', $date)['checks']['night']['ok']);
    }

    /** Chưa ai bật Night cố định: thuật toán không xếp ca đêm, không báo thiếu ngầm – có ghi chú cho quản lý. */
    public function test_no_night_core_means_no_automatic_night(): void
    {
        $this->restaurants();
        $builder = app(ProblemBuilder::class);
        $problem = $builder->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $n = $this->templates['N']->getKey();

        $this->assertSame([0], array_values(array_unique(array_map(fn ($r) => $r->min, array_filter($problem->requirements, fn ($r) => $r->shiftId === $n)))));
        $this->assertNotEmpty($builder->notes['night_core'] ?? []);
    }

    /**
     * Quản lý xếp tay Night luân phiên trên Bảng xếp ca: lưu được, thuật toán giữ nguyên ô xếp tay; xếp đè ô OFF đã duyệt bị chặn;
     * N rồi M6 sáng hôm sau (thiếu giờ nghỉ) bị chặn khi công bố.
     */
    public function test_manager_assigns_rotation_night_by_hand(): void
    {
        $people = $this->restaurants();
        $this->employee('NC1', 'NIGHT', extra: ['night_role' => Employee::NIGHT_CORE]);
        $user = $this->user();
        $n = $this->templates['N']->getKey();
        $date = Carbon::parse(self::WEEK)->addDays(1)->toDateString();

        $this->actingAs($user)->postJson(route('roster.assign'), ['employee_id' => $people['G1']->getKey(), 'date' => $date, 'action' => 'shift',
            'shift_template_id' => $n, 'week' => self::WEEK])->assertJson(['error' => false]);
        $cell = Shift::query()->where('employee_id', $people['G1']->getKey())->whereDate('date', $date)->firstOrFail();
        $this->assertSame([$n, $this->outlets['NIGHT']->getKey()], [(int) $cell->shift_template_id, (int) $cell->outlet_id], 'Ca N ở outlet Night');
        $this->assertTrue((bool) $cell->is_locked, 'Night luân phiên xếp tay tự khóa');

        $result = $this->solve();
        $kept = collect($result->assignments)->first(fn ($r) => $r['employee_id'] === $people['G1']->getKey() && $r['date'] === $date);
        $this->assertSame($n, $kept['shift_id'] ?? null, 'Thuật toán giữ ô Night xếp tay');

        // Ngày đã có OFF quản lý cho (khóa): không xếp đè được.
        $off = Carbon::parse(self::WEEK)->addDays(3)->toDateString();
        app(\Botble\RestaurantRoster\Services\OffRequestService::class)->record([$people['G2']->getKey()], ['date_from' => $off, 'date_to' => $off, 'type' => 'off_request'], 1);
        $this->actingAs($user)->postJson(route('roster.assign'), ['employee_id' => $people['G2']->getKey(), 'date' => $off, 'action' => 'shift',
            'shift_template_id' => $n, 'week' => self::WEEK])->assertJson(['error' => true]);
        $this->assertTrue(Shift::query()->where('employee_id', $people['G2']->getKey())->whereDate('date', $off)->first()->isRecordedOff(), 'OFF đã duyệt giữ nguyên');
    }

    /**
     * Room Service: chỉ người bật "Có thể làm Room Service" trong hồ sơ được tự xếp; người không bật (kể cả có dòng năng lực Room Service)
     * thì không; người bật duy nhất OFF đã duyệt → thiếu Room Service được cảnh báo, không lấy người chưa bật.
     */
    public function test_room_service_only_for_flagged_staff(): void
    {
        $people = $this->restaurants();
        $room = $this->roomService();
        $m6 = $this->templates['M6']->getKey();
        $flagged = $this->employee('RS1', 'GUSTO', 'female', ['can_room_service' => true]);
        $unflagged = $this->employee('RS2', 'GUSTO', 'female');
        EmployeeSkill::query()->create(['employee_id' => $unflagged->getKey(), 'outlet_id' => $room, 'level' => 'primary']);

        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $this->assertSame('primary', $problem->employees[$flagged->getKey()]->skillLevel($room, $m6));
        $this->assertNull($problem->employees[$unflagged->getKey()]->skillLevel($room, $m6), 'Không bật thì không làm Room Service, kể cả có dòng năng lực');
        $this->assertNull($problem->employees[$people['G1']->getKey()]->skillLevel($room, $m6));

        $off = Carbon::parse(self::WEEK)->addDays(4)->toDateString();
        app(\Botble\RestaurantRoster\Services\OffRequestService::class)->record([$flagged->getKey()], ['date_from' => $off, 'date_to' => $off, 'type' => 'off_request'], 1);
        $result = $this->solve();
        $atRoom = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['outlet_id'] === $room);

        $this->assertSame([$flagged->getKey()], array_values(array_unique(array_column($atRoom, 'employee_id'))), 'Chỉ người bật được xếp Room Service');
        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $off && $u['outlet_id'] === $room && $u['level'] === 'min'),
            'Ngày người bật OFF: cảnh báo thiếu Room Service');
    }

    /**
     * Migration 29/10: Night cố định = ca cứng ca đêm → night_role core; vai trò luân phiên bị bỏ; Room Service lấy từ module cũ
     * (chuyên trách, dự phòng, người thay thế còn hiệu lực) → can_room_service; chuyên trách theo ca chưa có ca cứng → ca cứng = ca đó.
     */
    public function test_migration_moves_night_core_and_room_service_to_profile(): void
    {
        $core = $this->employee('NC1', 'NIGHT', extra: ['fixed_shift_template_id' => $this->templates['N']->getKey()]);
        $rotation = $this->employee('RT1', 'VILLA', extra: ['night_role' => 'rotation']);
        $lead = $this->employee('RL1', 'NIGHT');
        $backup = $this->employee('RB1', 'GUSTO');
        $expired = $this->employee('RX1', 'GUSTO');
        $insert = fn (Employee $e, string $role, ?int $shift = null, ?string $to = null) => \Illuminate\Support\Facades\DB::table('roster_room_service_staff')->insert([
            'employee_id' => $e->getKey(), 'role' => $role, 'shift_template_id' => $shift, 'effective_from' => '2026-01-01', 'effective_to' => $to, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $insert($lead, 'lead', $this->templates['M6']->getKey());
        $insert($backup, 'backup');
        $insert($expired, 'substitute', null, '2020-01-01');

        $migration = require base_path('platform/plugins/restaurant-roster/database/migrations/2026_10_29_000001_move_night_core_and_room_service_to_employee_profile.php');
        $migration->up();

        $this->assertSame('core', $core->fresh()->night_role);
        $this->assertTrue($core->fresh()->isNightCore());
        $this->assertNull($rotation->fresh()->night_role, 'Bỏ vai trò luân phiên');
        $this->assertSame([true, true, false], [$lead->fresh()->can_room_service, $backup->fresh()->can_room_service, $expired->fresh()->can_room_service]);
        $this->assertSame($this->templates['M6']->getKey(), (int) $lead->fresh()->fixed_shift_template_id, 'Chuyên trách ca M6 → ca cứng M6');
        $this->assertNull($backup->fresh()->fixed_shift_template_id);
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('roster_room_service_staff')->count(), 'Không xóa dữ liệu cũ');
    }

    /** Trang / route Nhân sự Night và Nhân sự Room Service đã bỏ; hồ sơ nhân viên có cấu hình Night cố định và Room Service. */
    public function test_old_night_and_room_service_pages_removed(): void
    {
        $people = $this->restaurants();
        $user = $this->user();

        foreach (['roster.night.index', 'roster.night.save', 'roster.night.propose', 'roster.night.demand', 'roster.room-service.index', 'roster.room-service.store'] as $name) {
            $this->assertFalse(\Illuminate\Support\Facades\Route::has($name), "Route {$name} đã bỏ");
        }

        $this->actingAs($user)->get(route('roster.schedule-groups.index'))->assertOk();
        $this->actingAs($user)->get(route('roster.index', ['week' => self::WEEK]))->assertOk();
        // Botble mã hóa chữ có dấu trong nhãn form: so khớp sau khi giải mã.
        $html = html_entity_decode($this->actingAs($user)->get(route('roster.employees.edit', $people['G1']->getKey()))->assertOk()->getContent());
        $this->assertStringContainsString('Night cố định', $html);
        $this->assertStringContainsString('Có thể làm Room Service', $html);
        $this->assertStringNotContainsString('Tham gia luân phiên Night', $html);
        $this->assertStringContainsString('Nhóm hiển thị trên Bảng xếp ca', $html);
    }

    /** Bật Night cố định / Room Service trong hồ sơ: lưu được, có nhật ký; bảng xếp ca hiện nhãn. */
    public function test_profile_flags_saved_audited_and_shown(): void
    {
        $employee = $this->employee('N2', 'NIGHT');

        $this->actingAs($this->user())->post(route('roster.employees.edit.update', $employee->getKey()), [
            'code' => 'N2', 'name' => 'Synthetic N2', 'outlet_id' => $this->outlets['NIGHT']->getKey(), 'employment_type' => 'FT',
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'night_role' => Employee::NIGHT_CORE, 'can_room_service' => 1, 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $fresh = $employee->fresh();
        $this->assertTrue($fresh->isNightCore());
        $this->assertTrue($fresh->can_room_service);
        $log = ChangeLog::query()->where('employee_id', $employee->getKey())->where('action', 'profile')->first();
        $this->assertNotNull($log);
        $this->assertSame('core', $log->after['night_role']);

        $html = $this->actingAs($this->user())->getJson(route('roster.grid', ['week' => self::WEEK]))->json('data.html');
        $this->assertStringContainsString('N cố định', $html);
        $this->assertStringContainsString('>RS<', $html);
    }

    /** Villa + Gusto mỗi bên: 1 quản lý (cấp cao) và 2 Waiter; một ca đã khóa của Gusto. @return array<string, Employee> */
    private function twoRestaurants(): array
    {
        $captain = Position::query()->create(['name' => 'Junior Restaurant Captain', 'is_manager' => true, 'order' => 1, 'status' => 'published']);
        $this->waiter->update(['order' => 5]);
        $people = [];

        foreach (['VILLA', 'GUSTO'] as $outlet) {
            $people[$outlet[0] . 'W1'] = $this->employee($outlet[0] . 'W1', $outlet);
            $people[$outlet[0] . 'W2'] = $this->employee($outlet[0] . 'W2', $outlet, 'female');
            $people[$outlet[0] . 'C'] = $this->employee($outlet[0] . 'C', $outlet, extra: ['position_id' => $captain->getKey()]);
        }

        Shift::query()->create(['employee_id' => $people['GW1']->getKey(), 'date' => self::WEEK, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $this->templates['M6']->getKey(),
            'outlet_id' => $this->outlets['GUSTO']->getKey(), 'status' => 'draft', 'is_locked' => true, 'lock_reason' => Shift::LOCK_CONFIRMED,
            'start_at' => self::WEEK . ' 06:00:00', 'end_at' => self::WEEK . ' 14:00:00', 'hours' => 8]);

        return $people;
    }

    private function closeAllWeek(string $outlet, int $days = 7): void
    {
        foreach (range(0, $days - 1) as $d) {
            $date = Carbon::parse(self::WEEK)->addDays($d)->toDateString();
            $query = \Botble\RestaurantRoster\Models\OutletDay::query()->where('outlet_id', $this->outlets[$outlet]->getKey())->whereDate('date', $date);

            if (! $query->exists()) {
                \Botble\RestaurantRoster\Models\OutletDay::query()->create(['outlet_id' => $this->outlets[$outlet]->getKey(), 'date' => $date, 'is_closed' => true]);
            }
        }
    }

    private function gridHtml(array $query = []): string
    {
        return (string) $this->actingAs($this->user())->getJson(route('roster.grid', ['week' => self::WEEK, ...$query]))->assertJson(['error' => false])->json('data.html');
    }

    /** Thứ tự xuất hiện các nhân viên trong bảng (mỗi người đúng 1 hàng). */
    private function rowOrder(string $html, array $people): array
    {
        $positions = [];

        foreach ($people as $code => $employee) {
            // Mỗi hàng có 7 ô ngày mang tên nhân viên: đúng 7 lần = đúng một hàng (không nhân đôi).
            $this->assertSame(7, substr_count($html, 'data-employee-name="' . $employee->name . '"'), "{$code}: xuất hiện đúng 1 hàng");
            $positions[$code] = strpos($html, 'data-employee-name="' . $employee->name . '"');
        }

        asort($positions);

        return array_keys($positions);
    }

    private function setMerge(?string $outlet): void
    {
        $this->actingAs($this->user())->postJson(route('roster.grid-merge'), ['week' => self::WEEK, 'outlet_id' => $outlet ? $this->outlets[$outlet]->getKey() : null])
            ->assertJson(['error' => false]);
    }

    /**
     * Quản lý bấm "Gộp vào VILLA": nhân viên Villa + Gusto hiển thị chung một khu Villa, xếp theo cấp bậc (quản lý trước Waiter),
     * mỗi người một hàng, người Gusto có nhãn GUSTO, có thông báo cho mọi người; outlet chính, nhóm hiển thị, ô khóa không đổi.
     */
    public function test_grid_shows_villa_and_gusto_together_when_manager_merges(): void
    {
        $people = $this->twoRestaurants();
        $this->assertStringContainsString('Gộp vào VILLA', $this->gridHtml(), 'Nút gộp cho quản lý');
        $this->setMerge('VILLA');
        $html = $this->gridHtml();

        $this->assertStringContainsString('VILLA – HIỂN THỊ CHUNG NHÂN VIÊN VILLA + GUSTO', $html);
        $this->assertStringContainsString('hiển thị chung trong khu', $html, 'Thông báo cho mọi người');
        $this->assertStringNotContainsString('<span class="roster-section__name">GUSTO</span>', $html, 'Không còn khu Gusto riêng');
        $order = $this->rowOrder($html, $people);
        $this->assertEqualsCanonicalizing(['VC', 'GC'], array_slice($order, 0, 2), 'Cấp quản lý đứng đầu khu gộp: ' . implode(',', $order));
        $this->assertStringContainsString('bg-azure-lt', $html, 'Nhãn outlet cho người Gusto');

        $this->assertSame($this->outlets['GUSTO']->getKey(), (int) $people['GW1']->fresh()->outlet_id, 'Outlet chính không đổi');
        $this->assertSame($this->groups['GUSTO']->getKey(), (int) $people['GW1']->fresh()->schedule_group_id, 'Nhóm hiển thị không đổi');
        $this->assertTrue((bool) Shift::query()->where('employee_id', $people['GW1']->getKey())->value('is_locked'), 'Ô khóa giữ nguyên');
        $this->assertSame(1, Shift::query()->count(), 'Không tạo thêm ô lịch');

        // Lọc outlet Villa trong chế độ gộp: có cả người Gusto.
        $filtered = $this->gridHtml(['outlet_id' => $this->outlets['VILLA']->getKey()]);
        $this->assertStringContainsString('data-employee-name="' . $people['GW2']->name . '"', $filtered);

        // Tuần khác không bị ảnh hưởng.
        $next = (string) $this->actingAs($this->user())->getJson(route('roster.grid', ['week' => '2030-03-11']))->json('data.html');
        $this->assertStringContainsString('<span class="roster-section__name">GUSTO</span>', $next);
    }

    /** Gộp vào GUSTO; "Hiển thị riêng" trở về hai khu như cũ. */
    public function test_grid_merge_into_gusto_and_back_to_separate(): void
    {
        $people = $this->twoRestaurants();
        $this->setMerge('GUSTO');
        $html = $this->gridHtml();

        $this->assertStringContainsString('GUSTO – HIỂN THỊ CHUNG NHÂN VIÊN GUSTO + VILLA', $html);
        $this->assertStringNotContainsString('<span class="roster-section__name">VILLA</span>', $html);
        $this->assertEqualsCanonicalizing(['VC', 'GC'], array_slice($this->rowOrder($html, $people), 0, 2));

        $this->setMerge(null);
        $html = $this->gridHtml();
        $this->assertStringContainsString('<span class="roster-section__name">VILLA</span>', $html);
        $this->assertStringContainsString('<span class="roster-section__name">GUSTO</span>', $html);
        $this->assertStringNotContainsString('HIỂN THỊ CHUNG NHÂN VIÊN', $html);
        $this->rowOrder($html, $people);
    }

    /**
     * Chỉ đổi giao diện: gộp không ảnh hưởng thuật toán (bài toán xếp lịch giống hệt); ngược lại, ngày đóng cửa / outlet ngừng
     * hoạt động không tự gộp bảng – quản lý tự bật.
     */
    public function test_grid_merge_is_display_only(): void
    {
        $this->twoRestaurants();
        $build = fn () => app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        $before = $build();
        $this->setMerge('VILLA');
        $after = $build();

        $this->assertEquals($before->requirements, $after->requirements, 'Định biên / chỗ ca không đổi');
        $this->assertEquals($before->employees, $after->employees, 'Nhân viên, outlet, năng lực không đổi');

        $this->setMerge(null);
        $this->closeAllWeek('GUSTO');
        $this->assertStringNotContainsString('HIỂN THỊ CHUNG NHÂN VIÊN', $this->gridHtml(), 'Đóng cửa không tự gộp bảng');
    }

    /** Đổi Waiter Gusto ⇄ Villa (chức năng có sẵn): outlet chính và nhóm hiển thị cùng đổi, bảng hiện đúng khu; có nhật ký. */
    public function test_swap_waiters_moves_outlet_and_schedule_group(): void
    {
        $people = $this->twoRestaurants();

        $this->actingAs($this->user())->postJson(route('roster.swap-waiters'), ['week' => self::WEEK])->assertJson(['error' => false]);

        $this->assertSame([$this->outlets['GUSTO']->getKey(), $this->groups['GUSTO']->getKey()],
            [(int) $people['VW1']->fresh()->outlet_id, (int) $people['VW1']->fresh()->schedule_group_id], 'Waiter Villa → Gusto');
        $this->assertSame([$this->outlets['VILLA']->getKey(), $this->groups['VILLA']->getKey()],
            [(int) $people['GW1']->fresh()->outlet_id, (int) $people['GW1']->fresh()->schedule_group_id], 'Waiter Gusto → Villa');
        $this->assertSame($this->outlets['VILLA']->getKey(), (int) $people['VC']->fresh()->outlet_id, 'Quản lý không đổi');
        $this->assertTrue((bool) Shift::query()->where('employee_id', $people['GW1']->getKey())->value('is_locked'), 'Lịch đã xếp giữ nguyên');
        $this->assertNotNull(ChangeLog::query()->where('employee_id', $people['VW1']->getKey())->where('action', 'outlet_swap')->first());

        $html = $this->gridHtml();
        $villaAt = strpos($html, '<span class="roster-section__name">VILLA</span>');
        $gustoAt = strpos($html, '<span class="roster-section__name">GUSTO</span>');
        $row = strpos($html, 'data-employee-name="' . $people['GW1']->name . '"');
        $this->assertTrue($row > $villaAt && ($gustoAt < $villaAt || $row < $gustoAt), 'GW1 nay hiện trong khu Villa');
    }
}
