<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\CoverageRequirement;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\RuleRevision;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterAssigner;
use Botble\RestaurantRoster\Services\SchedulingRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Cấu hình quy tắc xếp lịch (quản lý 13/11/2026) – DỮ LIỆU GIẢ LẬP: Snack Villa / Elprimo / tắt chỉ cho Waiter nữ Cam Ranh;
 * công tắc hỗ trợ Lobby/Bar tổng và từng ca. Đổi cấu hình không sửa / xóa ô lịch nào.
 */
class SchedulingRulesTest extends TestCase
{
    private const WEEK = '2030-03-04';

    /** @var array<string, Outlet> */
    private array $outlets = [];

    /** @var array<string, ShiftTemplate> */
    private array $templates = [];

    /** @var array<string, Employee> */
    private array $people = [];

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

        if (! Schema::hasTable('settings')) {
            $this->artisan('migrate', ['--path' => 'platform/core/setting/database/migrations', '--force' => true]);
        }

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }

        foreach (['GUSTO' => 1, 'VILLA' => 2, 'LOBBY' => 3] as $code => $order) {
            $this->outlets[$code] = Outlet::query()->create(['name' => ucfirst(strtolower($code)), 'code' => $code, 'order' => $order, 'status' => 'published']);
        }

        $template = fn (string $code, string $start, string $end, array $extra = []) => $this->templates[$code] = ShiftTemplate::query()->create(
            ['code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end, 'hours' => 8, 'break_minutes' => 0, 'status' => 'published', ...$extra]);
        $template('M6', '06:00:00', '14:00:00', ['lobby_start_time' => '09:00:00', 'lobby_end_time' => '14:00:00']);
        $template('A2', '14:00:00', '22:00:00', ['lobby_start_time' => '14:00:00', 'lobby_end_time' => '17:00:00']);
        $template('OH', '08:45:00', '16:45:00', ['lobby_start_time' => '08:45:00', 'lobby_end_time' => '16:45:00', 'allowed_residence' => 'nha_trang']);
        $template('M9', '09:00:00', '17:00:00', ['support_station' => 'LOBBY', 'allowed_residence' => 'cam_ranh', 'allowed_gender' => 'female']);

        $waiter = Position::query()->create(['name' => 'Waiter', 'status' => 'published']);
        $hostess = Position::query()->create(['name' => 'Hostess', 'status' => 'published']);
        $make = fn (string $code, string $outlet, string $gender, string $residence, $position) => $this->people[$code] = Employee::query()->create([
            'code' => $code, 'name' => "Synthetic {$code}", 'gender' => $gender, 'outlet_id' => $this->outlets[$outlet]->getKey(), 'position_id' => $position->getKey(),
            'residence' => $residence, 'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);

        foreach (['GUSTO' => 'G', 'VILLA' => 'V'] as $outlet => $p) {
            foreach (range(1, 3) as $k) {
                $make("{$p}CF{$k}", $outlet, 'female', 'cam_ranh', $waiter);
            }

            $make("{$p}CM1", $outlet, 'male', 'cam_ranh', $waiter);
            $make("{$p}NF1", $outlet, 'female', 'nha_trang', $waiter);
            $make("{$p}NF2", $outlet, 'female', 'nha_trang', $waiter);
            $make("{$p}HF1", $outlet, 'female', 'cam_ranh', $hostess);
        }
    }

    private function eligibleIds(): array
    {
        return collect($this->people)->filter(fn ($e, $code) => str_contains($code, 'CF'))->map->getKey()->values()->all();
    }

    private function rules(): SchedulingRules
    {
        app()->forgetInstance(SchedulingRules::class);

        return app(SchedulingRules::class);
    }

    private function setRules(array $rules): void
    {
        $this->rules()->save(array_replace_recursive(SchedulingRules::defaults(), $rules), null, 'test');
        app()->forgetInstance(SchedulingRules::class);
    }

    private function lobbyCoverage(): void
    {
        CoverageRequirement::query()->create(['station' => 'LOBBY', 'start_time' => '09:00:00', 'end_time' => '16:45:00', 'female_only' => true,
            'min_staff' => 1, 'target_staff' => 1, 'is_confirmed' => true]);
    }

    private function solve(): SolveResult
    {
        $from = Carbon::parse(self::WEEK);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['time_limit_seconds' => 4, 'search_min_seconds' => null]);

        return (new HeuristicSolver())->solve($problem);
    }

    private function at(SolveResult $result, ?string $outletCode): array
    {
        $id = $outletCode ? Outlet::query()->where('code', $outletCode)->value('id') : null;

        return array_values(array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $id && (int) $r['outlet_id'] === (int) $id));
    }

    /** 1, 2, 3, 4: Villa → chỉ M9(V); Elprimo → chỉ M9(EL); chuyển nhà hàng không còn Snack cũ; Tắt → không Snack. Đúng đối tượng, đủ 1 người / ngày. */
    public function test_snack_follows_selected_restaurant_and_eligible_staff_only(): void
    {
        foreach (['villa' => ['SNACK_V', 'SNACK_EL'], 'elprimo' => ['SNACK_EL', 'SNACK_V']] as $mode => [$on, $off]) {
            $this->setRules(['snack' => ['mode' => $mode, 'count' => 1]]);
            $result = $this->solve();
            $snack = $this->at($result, $on);

            $this->assertCount(7, $snack, "{$mode}: mỗi ngày đúng 1 người Snack");
            $this->assertSame([$this->templates['M9']->getKey()], array_values(array_unique(array_column($snack, 'shift_id'))));
            $this->assertEmpty(array_diff(array_column($snack, 'employee_id'), $this->eligibleIds()), "{$mode}: chỉ Waiter nữ Cam Ranh");
            $this->assertSame([], $this->at($result, $off), "{$mode}: không có Snack của nhà hàng còn lại");
        }

        $this->setRules(['snack' => ['mode' => 'off']]);
        $result = $this->solve();
        $this->assertSame([], [...$this->at($result, 'SNACK_V'), ...$this->at($result, 'SNACK_EL')], 'Tắt: không có ca Snack');
        $this->assertNotEmpty(array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift'), 'Nhân viên vẫn được xếp ca bình thường');
    }

    /** Không đủ Waiter nữ Cam Ranh: báo thiếu, không lấy người nam / Nha Trang / Hostess. */
    public function test_snack_shortage_is_reported_not_filled_with_others(): void
    {
        $this->setRules(['snack' => ['mode' => 'villa', 'count' => 8]]);
        $result = $this->solve();
        $snack = $this->at($result, 'SNACK_V');

        $this->assertEmpty(array_diff(array_column($snack, 'employee_id'), $this->eligibleIds()));
        $snackId = Outlet::query()->where('code', 'SNACK_V')->value('id');
        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => (int) ($u['outlet_id'] ?? 0) === (int) $snackId), 'Có cảnh báo thiếu Snack');
    }

    /** 5, 7: tắt hỗ trợ Lobby/Bar → không M9(L) / OH(L) / M6(L) / A2(L) của nhân viên nhà hàng; M6 / A2 thường vẫn xếp. */
    public function test_lobby_support_off_creates_no_support_shifts(): void
    {
        $this->lobbyCoverage();
        $before = $this->solve();
        $this->assertNotEmpty([...$this->at($before, 'LOBBY'), ...array_filter($before->assignments, fn ($r) => ! empty($r['support']))], 'Bật (mặc định): có người hỗ trợ Lobby');

        $this->setRules(['lobby' => ['enabled' => false]]);
        $result = $this->solve();
        $this->assertSame([], $this->at($result, 'LOBBY'));
        $this->assertSame([], array_values(array_filter($result->assignments, fn ($r) => ! empty($r['support']))));
        $codes = array_unique(array_map(fn ($r) => $r['shift_id'], array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift')));
        $this->assertContains($this->templates['M6']->getKey(), $codes);
        $this->assertContains($this->templates['A2']->getKey(), $codes);
    }

    /** 6: bật tổng nhưng tắt M9(L) → không có M9 ở Lobby; các ca (L) khác vẫn được dùng. */
    public function test_single_lobby_code_can_be_disabled(): void
    {
        $this->lobbyCoverage();
        $this->setRules(['lobby' => ['enabled' => true, 'codes' => ['M9' => false, 'A2' => true, 'OH' => true, 'M6' => true]]]);
        $result = $this->solve();

        $this->assertSame([], array_values(array_filter($this->at($result, 'LOBBY'), fn ($r) => $r['shift_id'] === $this->templates['M9']->getKey())));
        $this->assertNotEmpty(array_filter($result->assignments, fn ($r) => ! empty($r['support'])), 'Vẫn có OH(L) / M6(L) / A2(L)');
    }

    /** Ký hiệu: áp dụng lịch tự động ghi M9(V); nhập tay M9(EL) ở quầy Snack Elprimo, không thêm (L). Áp dụng hai lần không trùng ô. */
    public function test_snack_notation_and_apply_without_duplicates(): void
    {
        $this->setRules(['snack' => ['mode' => 'villa', 'count' => 1]]);
        $admin = $this->admin();

        foreach ([1, 2] as $round) {
            $this->actingAs($admin)->postJson(route('roster.auto.run'), ['from' => self::WEEK, 'to' => Carbon::parse(self::WEEK)->addDays(6)->toDateString(), 'type' => 'generate'])
                ->assertJson(['error' => false]);
            $this->actingAs($admin)->postJson(route('roster.auto.apply', \Botble\RestaurantRoster\Models\Run::query()->latest('id')->value('id')))->assertJson(['error' => false]);
        }

        $snack = Shift::query()->with('template')->where('outlet_id', Outlet::query()->where('code', 'SNACK_V')->value('id'))->get();
        $this->assertCount(7, $snack);
        $this->assertSame(['M9(V)'], $snack->map->notation->unique()->values()->all());
        $this->assertSame(0, Shift::query()->selectRaw('employee_id, date, count(*) c')->groupBy('employee_id', 'date')->havingRaw('count(*) > 1')->get()->count());

        $this->rules()->ensureSnackOutlet('elprimo');
        $employee = $this->people['GCF1'];
        $shift = app(RosterAssigner::class)->assign($employee, Carbon::parse('2030-03-20'), app(RosterAssigner::class)->parse('M9(EL)'));
        $this->assertSame(['M9(EL)', 'SNACK_EL'], [$shift->notation, Outlet::query()->whereKey($shift->outlet_id)->value('code')]);
        $plain = app(RosterAssigner::class)->assign($employee, Carbon::parse('2030-03-21'), app(RosterAssigner::class)->parse('M9'));
        $this->assertSame(['M9(L)', 'LOBBY'], [$plain->fresh()->load('template')->notation, Outlet::query()->whereKey($plain->outlet_id)->value('code')], 'M9 thường vẫn là Lobby');
    }

    /** Quản lý nhập tay tự do: Snack đang mở Elprimo vẫn gõ được M9(V) (quầy chưa có thì tự tạo), hiện đúng như gõ, không bị cảnh báo. */
    public function test_manager_can_type_any_snack_code_freely(): void
    {
        $this->setRules(['snack' => ['mode' => 'elprimo']]);
        $admin = $this->admin();
        $employee = $this->people['GCF2'];
        $date = Carbon::today()->addDay();
        $week = $date->copy()->startOfWeek()->toDateString();
        $type = fn (string $text, string $day) => $this->actingAs($admin)->postJson(route('roster.assign'), ['employee_id' => $employee->getKey(), 'date' => $day,
            'action' => 'notation', 'notation' => $text, 'week' => $week])->assertJson(['error' => false]);
        $cell = fn (string $day) => Shift::query()->with('template')->where('employee_id', $employee->getKey())->whereDate('date', $day)->first();

        $type('M9(V)', $date->toDateString());
        $this->assertSame(['M9(V)', 'SNACK_V'], [$cell($date->toDateString())->notation, Outlet::query()->whereKey($cell($date->toDateString())->outlet_id)->value('code')]);

        $next = $date->copy()->addDay()->toDateString();
        $type('M9', $next);
        $this->assertSame('M9(L)', $cell($next)->notation, 'M9 gõ tay vẫn là M9(L) như trước');

        $html = (string) $this->actingAs($admin)->getJson(route('roster.grid', ['week' => $week]))->json('data.html');
        $this->assertStringNotContainsString('(Cấu hình quy tắc xếp lịch)', $html, 'Ô nhập tay không bị cảnh báo');
        $this->assertStringContainsString('M9(V)', $html);
    }

    /** 8, 9, 12: đổi cấu hình ảnh hưởng lịch → hỏi xác nhận, liệt kê ô; lưu xong ô khóa / chỉnh tay vẫn còn; có lịch sử; không đủ quyền thì không đổi được. */
    public function test_saving_rules_warns_keeps_cells_and_needs_permission(): void
    {
        $day = Carbon::today()->addDay()->toDateString();
        $lobbyShift = Shift::query()->create(['employee_id' => $this->people['GCF1']->getKey(), 'date' => $day, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $this->templates['M9']->getKey(),
            'outlet_id' => $this->outlets['LOBBY']->getKey(), 'tags' => ['L'], 'status' => 'draft', 'is_locked' => true, 'source' => 'engine']);
        $this->setRules(['lobby' => ['codes' => ['M9' => true, 'A2' => true, 'OH' => false, 'M6' => true]]]);
        // Công tắc tổng tắt: các công tắc con bị khóa nên trình duyệt không gửi.
        $form = ['snack_mode' => 'off', 'snack_count' => 1, 'lobby_enabled' => 0];

        $denied = $this->actingAs($this->user(['roster.index', 'roster.manage']))->post(route('roster.rules.update'), $form);
        $this->assertTrue($denied->status() >= 300, 'Không có quyền Danh mục thì bị chặn');
        $this->assertTrue($this->rules()->get()['lobby']['enabled']);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('roster.rules.index'))->assertOk()->assertSee('Cấu hình quy tắc xếp lịch');
        $preview = $this->actingAs($admin)->post(route('roster.rules.update'), $form)->assertOk();
        $preview->assertSee('ảnh hưởng 1 ô lịch')->assertSee('Synthetic GCF1')->assertSee('Đã khóa');
        $this->assertTrue($this->rules()->get()['lobby']['enabled'], 'Chưa xác nhận thì chưa lưu');

        $this->actingAs($admin)->post(route('roster.rules.update'), [...$form, 'confirmed' => 1])->assertRedirect();
        $this->assertFalse($this->rules()->get()['lobby']['enabled']);
        $this->assertSame(['M9' => true, 'A2' => true, 'OH' => false, 'M6' => true], $this->rules()->get()['lobby']['codes'], 'Lựa chọn từng ca được giữ để bật lại');
        $this->assertNotNull($lobbyShift->fresh(), 'Ô khóa không bị xóa');
        $this->assertSame('Hỗ trợ Lobby/Bar: tắt', RuleRevision::query()->latest('id')->value('summary'));

        $week = Carbon::parse($day)->startOfWeek()->toDateString();
        $html = (string) $this->actingAs($admin)->getJson(route('roster.grid', ['week' => $week]))->json('data.html');
        $this->assertStringContainsString('Hỗ trợ Lobby/Bar đang tắt (Cấu hình quy tắc xếp lịch)', $html, 'Bảng xếp ca cảnh báo ô bị ảnh hưởng');
    }

    private function admin(): User
    {
        return $this->user([], true);
    }

    private function user(array $permissions, bool $super = false): User
    {
        $user = new User();
        $user->forceFill(['email' => uniqid('syn') . '@example.test', 'username' => uniqid('syn'), 'password' => bcrypt('secret'),
            'first_name' => 'Synthetic', 'last_name' => 'Manager', 'super_user' => $super, 'permissions' => array_fill_keys($permissions, true)])->save();

        return $user;
    }
}
