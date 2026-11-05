<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\OutletDay;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ScheduleGroup;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterScheduler;
use Botble\RestaurantRoster\Services\RosterStatistics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Khu vực TRAINEE và hồ sơ nhân viên gọn (quản lý 05/11/2026) – DỮ LIỆU GIẢ LẬP: thực tập sinh hiện ở khu vực riêng, được xếp tự động M6 / A2 tại nhà hàng
 * đã chọn, không tính định biên; quản lý sửa tay được. Form chỉ còn trường cần thiết; nhà hàng làm việc chỉ có Gusto / Villa.
 */
class TraineeAreaTest extends TestCase
{
    private const WEEK = '2030-03-04';

    /** @var array<string, Outlet> */
    private array $outlets = [];

    /** @var array<string, ScheduleGroup> */
    private array $groups = [];

    /** @var array<string, ShiftTemplate> */
    private array $templates = [];

    private Position $waiter;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(RestaurantRosterServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
        $this->withoutMiddleware(\Botble\Installer\Http\Middleware\RedirectIfNotInstalledMiddleware::class);
        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);
        config(['plugins.restaurant-roster.general.scheduling.search_min_seconds' => null, 'plugins.restaurant-roster.general.scheduling.time_limit_seconds' => 3,
            'plugins.restaurant-roster.general.scheduling.require_off_confirmation' => false]);

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate', ['--path' => 'database/migrations', '--force' => true]);
            $this->artisan('migrate', ['--path' => 'platform/core/acl/database/migrations', '--force' => true]);
        }

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }

        foreach (['GUSTO' => 1, 'VILLA' => 2, 'NIGHT' => 3, 'LOBBY' => 4] as $code => $order) {
            $this->outlets[$code] = Outlet::query()->create(['name' => ucfirst(strtolower($code)), 'code' => $code, 'order' => $order, 'status' => 'published']);

            if ($code !== 'LOBBY') {
                $this->groups[$code] = ScheduleGroup::query()->create(['code' => $code, 'name' => $code, 'outlet_id' => $this->outlets[$code]->getKey(), 'order' => $order, 'is_active' => true]);
            }
        }

        // Khu vực TRAINEE do migration tạo; đặt thứ tự cuối như trên hệ thống thật (lúc migrate chưa có khu vực nào khác).
        $this->groups['TRAINEE'] = ScheduleGroup::query()->where('code', 'TRAINEE')->firstOrFail();
        $this->groups['TRAINEE']->update(['order' => 90]);

        foreach (['M6' => ['06:00:00', '14:00:00', '09:00:00', '14:00:00'], 'A2' => ['14:00:00', '22:00:00', '14:00:00', '17:00:00']] as $code => [$start, $end, $lobbyStart, $lobbyEnd]) {
            $this->templates[$code] = ShiftTemplate::query()->create(['code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end, 'hours' => 8, 'break_minutes' => 0,
                'lobby_start_time' => $lobbyStart, 'lobby_end_time' => $lobbyEnd, 'status' => 'published']);
        }

        $this->waiter = Position::query()->create(['name' => 'Waiter', 'status' => 'published']);
        $this->user = new User();
        $this->user->forceFill(['email' => uniqid('tr') . '@example.test', 'username' => uniqid('tr'), 'password' => bcrypt('secret'),
            'first_name' => 'Synthetic', 'last_name' => 'Manager', 'super_user' => true, 'permissions' => []])->save();
    }

    private function payload(array $extra = []): array
    {
        return ['code' => '', 'name' => 'Synthetic New', 'gender' => 'female', 'position_id' => $this->waiter->getKey(), 'schedule_group_id' => $this->groups['GUSTO']->getKey(),
            'residence' => 'cam_ranh', 'days_off_per_week' => 2, 'auto_schedule' => 1, 'room_service' => 'none', 'status' => 'published', ...$extra];
    }

    private function store(array $extra = [])
    {
        return $this->actingAs($this->user)->post(route('roster.employees.create.store'), $this->payload($extra));
    }

    private function staff(string $code, string $outlet, string $gender, array $extra = []): Employee
    {
        return Employee::query()->create(['code' => $code, 'name' => "Synthetic {$code}", 'gender' => $gender, 'outlet_id' => $this->outlets[$outlet]->getKey(),
            'schedule_group_id' => $this->groups[$outlet]->getKey(), 'position_id' => $this->waiter->getKey(), 'residence' => 'cam_ranh', 'days_off_per_week' => 2,
            'auto_schedule' => true, 'status' => 'published', ...$extra]);
    }

    private function trainee(string $code, string $outlet, string $gender = 'female'): Employee
    {
        return $this->staff($code, $outlet, $gender, ['is_trainee' => true, 'schedule_group_id' => $this->groups['TRAINEE']->getKey()]);
    }

    private function generate(): void
    {
        $from = Carbon::parse(self::WEEK);
        $scheduler = app(RosterScheduler::class);
        $scheduler->apply($scheduler->run($from, $from->copy()->addDays(6), null, 'generate', 1), 1);
    }

    public function test_form_has_only_needed_fields(): void
    {
        $html = html_entity_decode((string) $this->actingAs($this->user)->get(route('roster.employees.create'))->assertOk()->getContent());

        foreach (['Khu vực', 'Nhà hàng làm việc', 'Khu vực sinh sống', 'Số ngày OFF / tuần', 'Ca cứng', 'Night cố định', 'Room Service', 'TRAINEE'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        foreach (['name="phone"', 'name="email"', 'name="hire_date"', 'name="employment_type"', 'name="weekly_hours"', 'name="mentor_id"', 'name="training_from"', 'name="training_note"', 'name="is_trainee"'] as $removed) {
            $this->assertStringNotContainsString($removed, $html);
        }

        $this->assertStringContainsString('noOutlet = ["' . $this->groups['TRAINEE']->getKey() . '"]', $html, 'Script ẩn / hiện ô theo khu vực có trong trang');

        // Nhà hàng làm việc: chỉ Gusto / Villa.
        $this->assertMatchesRegularExpression('/name="outlet_id".*?<\/select>/s', $html);
        preg_match('/name="outlet_id".*?<\/select>/s', $html, $select);
        $this->assertStringContainsString('Gusto', $select[0]);
        $this->assertStringContainsString('Villa', $select[0]);
        $this->assertStringNotContainsString('Night', $select[0]);
        $this->assertStringNotContainsString('Lobby', $select[0]);
    }

    public function test_area_decides_outlet_and_trainee_flag(): void
    {
        $this->store(['name' => 'In Night', 'schedule_group_id' => $this->groups['NIGHT']->getKey()])->assertSessionHasNoErrors();
        $night = Employee::query()->where('name', 'In Night')->firstOrFail();
        $this->assertSame([$this->outlets['NIGHT']->getKey(), false], [(int) $night->outlet_id, $night->is_trainee], 'Khu vực gắn outlet: outlet theo khu vực');

        $this->store(['name' => 'No Outlet', 'schedule_group_id' => $this->groups['TRAINEE']->getKey()])->assertSessionHasErrors('outlet_id');
        $this->store(['name' => 'Bad Outlet', 'schedule_group_id' => $this->groups['TRAINEE']->getKey(), 'outlet_id' => $this->outlets['NIGHT']->getKey()])->assertSessionHasErrors('outlet_id');
        $this->assertSame(0, Employee::query()->whereIn('name', ['No Outlet', 'Bad Outlet'])->count(), 'Thực tập sinh phải có nhà hàng làm việc là Gusto / Villa');

        $this->store(['name' => 'Intern', 'schedule_group_id' => $this->groups['TRAINEE']->getKey(), 'outlet_id' => $this->outlets['VILLA']->getKey(),
            'night_role' => Employee::NIGHT_CORE, 'room_service' => 'fixed'])->assertSessionHasNoErrors();
        $intern = Employee::query()->where('name', 'Intern')->firstOrFail();
        $this->assertSame([$this->outlets['VILLA']->getKey(), true, $this->groups['TRAINEE']->getKey()], [(int) $intern->outlet_id, $intern->is_trainee, (int) $intern->schedule_group_id]);
        $this->assertSame([null, false, false], [$intern->night_role, (bool) $intern->can_room_service, (bool) $intern->room_service_fixed], 'Thực tập sinh không tự xếp Night / Room Service');

        // Chuyển thành nhân viên chính thức.
        $this->actingAs($this->user)->post(route('roster.employees.edit.update', $intern->getKey()), $this->payload(['code' => $intern->code, 'name' => 'Intern', 'schedule_group_id' => $this->groups['VILLA']->getKey()]))
            ->assertSessionHasNoErrors();
        $this->assertSame([false, $this->outlets['VILLA']->getKey()], [$intern->fresh()->is_trainee, (int) $intern->fresh()->outlet_id]);
    }

    public function test_required_fields_and_room_service_choice(): void
    {
        $this->store(['gender' => ''])->assertSessionHasErrors('gender');
        $this->store(['position_id' => ''])->assertSessionHasErrors('position_id');
        $this->store(['schedule_group_id' => ''])->assertSessionHasErrors('schedule_group_id');
        $this->store(['residence' => ''])->assertSessionHasErrors('residence');

        $this->store(['name' => 'RS', 'room_service' => 'fixed'])->assertSessionHasNoErrors();
        $this->assertSame([false, true], [(bool) Employee::query()->where('name', 'RS')->value('can_room_service'), (bool) Employee::query()->where('name', 'RS')->value('room_service_fixed')]);
        $this->store(['name' => 'RS2', 'room_service' => 'support'])->assertSessionHasNoErrors();
        $this->assertSame([true, false], [(bool) Employee::query()->where('name', 'RS2')->value('can_room_service'), (bool) Employee::query()->where('name', 'RS2')->value('room_service_fixed')]);
    }

    /** Sửa hồ sơ, không đổi khu vực: outlet và các trường đã bỏ khỏi form (điện thoại, giờ / tuần…) giữ nguyên. */
    public function test_editing_keeps_outlet_and_hidden_data(): void
    {
        $legacy = $this->staff('L1', 'VILLA', 'male', ['schedule_group_id' => $this->groups['NIGHT']->getKey(), 'phone' => '0900', 'weekly_hours' => 36, 'employment_type' => 'PT']);

        $this->actingAs($this->user)->post(route('roster.employees.edit.update', $legacy->getKey()), $this->payload(['code' => 'L1', 'name' => 'Renamed', 'gender' => 'male', 'schedule_group_id' => $this->groups['NIGHT']->getKey()]))
            ->assertSessionHasNoErrors();
        $fresh = $legacy->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($this->outlets['VILLA']->getKey(), (int) $fresh->outlet_id, 'Không đổi khu vực thì không đổi outlet');
        $this->assertSame(['0900', 36.0, 'PT'], [$fresh->phone, $fresh->weekly_hours, $fresh->employment_type], 'Dữ liệu cũ không bị xóa');
    }

    public function test_trainee_section_is_last_and_auto_scheduled_at_chosen_restaurant(): void
    {
        foreach (range(1, 4) as $k) {
            $this->staff("V{$k}", 'VILLA', $k % 2 ? 'female' : 'male');
            $this->staff("G{$k}", 'GUSTO', $k % 2 ? 'female' : 'male');
        }

        $male = $this->trainee('T1', 'VILLA', 'male');
        $female = $this->trainee('T2', 'GUSTO');
        $this->generate();

        $html = (string) $this->actingAs($this->user)->getJson(route('roster.grid', ['week' => self::WEEK]))->assertJson(['error' => false])->json('data.html');
        $this->assertStringContainsString('<span class="roster-section__name">TRAINEE</span>', $html);
        $this->assertGreaterThan(strpos($html, '<span class="roster-section__name">VILLA</span>'), strpos($html, '<span class="roster-section__name">TRAINEE</span>'), 'TRAINEE sau các khu vực khác');
        $this->assertGreaterThan(strpos($html, '<span class="roster-section__name">TRAINEE</span>'), strpos($html, 'data-employee-name="' . $male->name . '"'), 'Thực tập sinh nằm trong khu TRAINEE');

        foreach ([[$male, 'VILLA'], [$female, 'GUSTO']] as [$trainee, $outlet]) {
            $cells = Shift::query()->with('template')->where('employee_id', $trainee->getKey())->where('kind', Shift::KIND_SHIFT)->get();
            $this->assertCount(5, $cells, 'Đủ ngày công');
            $this->assertEmpty(array_diff($cells->map(fn (Shift $s) => $s->notation)->unique()->all(), ['M6', 'A2']), 'Chỉ M6 / A2, không Lobby');
            $this->assertSame([$this->outlets[$outlet]->getKey()], $cells->pluck('outlet_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        }

        // Không tính vào thống kê định biên.
        $staffIds = Employee::query()->where('is_trainee', false)->pluck('id');
        $stats = (new RosterStatistics())->onDuty(Carbon::parse(self::WEEK), Outlet::query()->get());
        $villaM6 = Shift::query()->whereIn('employee_id', $staffIds)->whereDate('date', self::WEEK)->where('outlet_id', $this->outlets['VILLA']->getKey())->where('shift_template_id', $this->templates['M6']->getKey())
            ->get()->reject(fn (Shift $shift) => in_array('L', $shift->tags ?: [], true))->count(); // Người đi Lobby (L) không tính vào nhà hàng.
        $this->assertSame($villaM6, $stats['cells'][self::WEEK][$this->outlets['VILLA']->getKey()]['M6']['total'] ?? 0);

        // Quản lý sửa tay: đổi ca, chạy lại xếp tự động... ô khóa giữ nguyên.
        $cell = Shift::query()->where('employee_id', $male->getKey())->where('kind', Shift::KIND_SHIFT)->first();
        $other = (int) $cell->shift_template_id === $this->templates['M6']->getKey() ? 'A2' : 'M6';
        $this->actingAs($this->user)->postJson(route('roster.assign'), ['employee_id' => $male->getKey(), 'date' => $cell->date->toDateString(), 'week' => self::WEEK, 'action' => 'shift',
            'shift_template_id' => $this->templates[$other]->getKey()])->assertJson(['error' => false]);
        $this->assertSame($other, $cell->fresh()->load('template')->notation);
    }

    public function test_trainee_not_counted_in_balance_and_follows_closed_restaurant(): void
    {
        $this->trainee('T1', 'VILLA', 'male');
        $this->trainee('T2', 'VILLA');
        $this->staff('V1', 'VILLA', 'female');
        $this->staff('G1', 'GUSTO', 'male');
        $from = Carbon::parse(self::WEEK);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6));
        $spec = $problem->employees[Employee::query()->where('code', 'T1')->value('id')];
        $this->assertTrue($spec->isTrainee);
        $this->assertFalse($spec->countsAsMale(), 'Không tính nam waiter');
        $this->assertFalse($problem->employees[Employee::query()->where('code', 'T2')->value('id')]->isFemaleStaff, 'Không tính nữ waiter');
        $this->assertArrayNotHasKey(Employee::query()->where('code', 'T2')->value('id'), $problem->lobbyPreferred, 'Không vào vòng chia Lobby');

        foreach (range(0, 6) as $d) {
            OutletDay::query()->create(['outlet_id' => $this->outlets['VILLA']->getKey(), 'date' => $from->copy()->addDays($d)->toDateString(), 'is_closed' => true]);
        }

        $this->generate();
        $cells = Shift::query()->with('template')->where('employee_id', Employee::query()->where('code', 'T1')->value('id'))->where('kind', Shift::KIND_SHIFT)->get();
        $this->assertNotEmpty($cells);
        $this->assertSame([$this->outlets['GUSTO']->getKey()], $cells->pluck('outlet_id')->map(fn ($id) => (int) $id)->unique()->values()->all(), 'Nhà hàng đóng: làm ở nhà hàng còn mở');
        $this->assertEmpty(array_diff($cells->map(fn (Shift $s) => $s->notation)->unique()->all(), ['M6', 'A2']), 'Không có (G)');
    }

    public function test_migration_moves_existing_trainees_to_trainee_area(): void
    {
        $old = $this->staff('O1', 'VILLA', 'female', ['is_trainee' => true]);
        (require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/database/migrations/2026_11_05_000001_add_trainee_schedule_group.php')->up();
        $this->assertSame($this->groups['TRAINEE']->getKey(), (int) $old->fresh()->schedule_group_id);
        $this->assertSame($this->outlets['VILLA']->getKey(), (int) $old->fresh()->outlet_id, 'Outlet không đổi');
        $this->assertSame(1, ScheduleGroup::query()->where('code', 'TRAINEE')->count(), 'Không tạo trùng');
    }
}
