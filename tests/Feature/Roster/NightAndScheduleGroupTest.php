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
     * Gusto, Villa: mỗi outlet 5 nam waiter (2 người đầu có năng lực Night) + 3 nữ – đủ ngày công khi rút 1–2 người đi Night.
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

            $this->nightSkill($people[$outlet[0] . '1']);
            $this->nightSkill($people[$outlet[0] . '2']);
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

    /** 9.3: thuật toán không tự lấy người outlet nhà hàng khác; Night (luân phiên) vẫn được; Night đa năng giữ năng lực điều chuyển. */
    public function test_builder_blocks_cross_outlet_transfer_except_night_and_flex(): void
    {
        $gustoMale = $this->employee('G1', 'GUSTO');
        EmployeeSkill::query()->create(['employee_id' => $gustoMale->getKey(), 'outlet_id' => $this->outlets['VILLA']->getKey(), 'level' => 'eligible']);
        $this->nightSkill($gustoMale);
        $flex = $this->employee('N1', 'NIGHT', extra: ['night_role' => 'flex']);
        EmployeeSkill::query()->create(['employee_id' => $flex->getKey(), 'outlet_id' => $this->outlets['VILLA']->getKey(), 'level' => 'eligible']);

        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(6));
        [$villa, $night, $m6, $n] = [$this->outlets['VILLA']->getKey(), $this->outlets['NIGHT']->getKey(), $this->templates['M6']->getKey(), $this->templates['N']->getKey()];

        $this->assertNull($problem->employees[$gustoMale->getKey()]->skillLevel($villa, $m6), 'Không tự điều chuyển nam Gusto sang Villa');
        $this->assertNotNull($problem->employees[$gustoMale->getKey()]->skillLevel($night, $n), 'Vẫn luân phiên Night được');
        $this->assertNotNull($problem->employees[$flex->getKey()]->skillLevel($villa, $m6), 'Night đa năng được điều chuyển bù nhà hàng');
        $this->assertTrue($problem->employees[$flex->getKey()]->isNightFlex());
        $this->assertTrue($problem->employees[$gustoMale->getKey()]->countsAsMale(), 'Nam waiter');
    }

    /** 9.9: đề xuất 2 người ít tuần Night nhất, chia giữa các outlet. */
    public function test_rotation_prefers_least_history_and_balances_outlets(): void
    {
        $people = $this->restaurants();
        $this->nightShift($people['G1'], '2030-02-26');   // tuần liền trước
        $this->nightShift($people['V1'], '2030-02-12');   // 3 tuần trước

        $proposal = app(NightStaffService::class)->propose(Carbon::parse(self::WEEK));

        $this->assertSame(['G2', 'V2'], $this->selectedCodes($proposal));
        $this->assertSame([], $proposal['warnings']);
    }

    /** 9.10: tuần sau không lặp lại người vừa làm khi còn lựa chọn hợp lệ khác. */
    public function test_rotation_does_not_repeat_people_in_consecutive_weeks(): void
    {
        $people = $this->restaurants();
        $service = app(NightStaffService::class);
        $service->save(Carbon::parse(self::WEEK), [$people['G2']->getKey(), $people['V2']->getKey()], NightRotation::STATUS_APPROVED, 1);

        $next = $service->propose(Carbon::parse(self::WEEK)->addWeek());

        $this->assertSame(['G1', 'V1'], $this->selectedCodes($next));
        $plan = $service->planFor(Carbon::parse(self::WEEK), Carbon::parse(self::WEEK)->addDays(13));
        $this->assertSame('approved', $plan['weeks'][self::WEEK]['source']);
        $this->assertSame('proposed', $plan['weeks']['2030-03-11']['source']);
        $this->assertEqualsCanonicalizing([$people['G1']->getKey(), $people['V1']->getKey()], $plan['plan']['2030-03-12']);
    }

    /** 9.11: không chọn người mà outlet nguồn sẽ thiếu nam waiter tối thiểu – nêu rõ lý do. */
    public function test_rotation_skips_people_whose_outlet_would_fall_below_minimum(): void
    {
        $this->restaurants(villaMales: 3);
        $proposal = app(NightStaffService::class)->propose(Carbon::parse(self::WEEK));

        $this->assertSame(['G1', 'G2'], $this->selectedCodes($proposal), 'Villa chỉ 3 nam waiter: không rút ai');
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
        // Night cố định = ca cứng ca đêm (không cần chọn vai trò).
        $this->employee('NC1', 'NIGHT', extra: ['fixed_shift_template_id' => $this->templates['N']->getKey()]);
        $this->assertSame(['NC1'], app(NightStaffService::class)->groups()['core']->pluck('code')->all());
        $user = $this->user();

        $this->actingAs($user)->get(route('roster.night.index', ['week' => self::WEEK]))->assertOk()
            ->assertSee('Night cố định')->assertSee('Synthetic NC1')->assertSee('Đề xuất')->assertSee('Định biên Night từng ngày');
        $this->actingAs($user)->post(route('roster.night.save'), ['week' => self::WEEK, 'employee_ids' => [$people['G2']->getKey()], 'status' => 'approved'])->assertRedirect();
        $this->assertSame(1, NightRotation::query()->where('status', 'approved')->count());
        $this->actingAs($user)->get(route('roster.schedule-groups.index'))->assertOk();
        $this->actingAs($user)->get(route('roster.schedule-groups.create'))->assertOk();
        // Botble mã hóa chữ có dấu trong nhãn form (ò → &ograve;): so khớp sau khi giải mã.
        $html = html_entity_decode($this->actingAs($user)->get(route('roster.employees.edit', $people['G1']->getKey()))->assertOk()->getContent());
        $this->assertStringContainsString('Vai trò Night', $html);
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
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'night_role' => 'flex', 'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->assertSame('flex', $employee->fresh()->night_role);
        $log = ChangeLog::query()->where('employee_id', $employee->getKey())->where('action', 'profile')->first();
        $this->assertNotNull($log);
        $this->assertSame('flex', $log->after['night_role']);
    }
}
