<?php

namespace Tests\Feature\Roster;

use Botble\RestaurantRoster\Models\CoverageRequirement;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterStatistics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Quy tắc Lobby Bar trong database: dòng định biên 2 nữ waiter cùng lúc 09:00–16:45 (migration), bộ giải đọc đúng,
 * và dòng cảnh báo LOBBY trên bảng xếp ca đếm số người cùng lúc như bộ giải – DỮ LIỆU GIẢ LẬP.
 */
class LobbyCoverageRuleTest extends TestCase
{
    private const DATE = '2030-03-04';

    protected function setUp(): void
    {
        parent::setUp();

        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }
    }

    public function test_migration_stores_the_concurrent_female_waiter_rule(): void
    {
        $rows = CoverageRequirement::query()->where('station', 'LOBBY')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(['09:00', '16:45', 2, 2, true, null, true], [substr($rows[0]->start_time, 0, 5), substr($rows[0]->end_time, 0, 5),
            $rows[0]->min_staff, $rows[0]->target_staff, $rows[0]->female_only, $rows[0]->shift_codes, $rows[0]->is_confirmed]);
    }

    public function test_problem_builder_reads_the_rule_as_concurrent_female_only_window(): void
    {
        $this->world();
        $problem = app(ProblemBuilder::class)->build(Carbon::parse(self::DATE), Carbon::parse(self::DATE)->addDays(6));

        $this->assertCount(7, $problem->coverage);
        $window = $problem->coverage[0];
        $this->assertSame([540, 1005, 2, 2, true, null], [$window->start, $window->end, $window->min, $window->target, $window->femaleOnly, $window->shiftIds]);
    }

    public function test_grid_lobby_row_counts_people_present_at_the_same_time(): void
    {
        [$templates, $staff] = $this->world();
        $statistics = fn () => (new RosterStatistics())->lobbyCoverage(Carbon::parse(self::DATE));

        $this->cell($staff['oh'], $templates['OH'], 'VILLA', ['L']);
        $this->cell($staff['m6'], $templates['M6'], 'GUSTO', ['L']);
        $day = $statistics()[self::DATE];
        $this->assertSame(1, $day['min_count']);
        $this->assertSame(['Lobby 14:00–16:45: 1/2 nữ waiter cùng lúc'], $day['short'], 'OH(L) + M6(L): thiếu 14:00–16:45');

        $this->cell($staff['a2'], $templates['A2'], 'GUSTO', ['L']);
        $day = $statistics()[self::DATE];
        $this->assertSame([2, []], [$day['min_count'], $day['short']], 'Thêm A2(L): đủ 2 người ở mọi đoạn');

        // Ngày khác: M6(L) + A2(L) không phải 2 người cùng lúc.
        $next = Carbon::parse(self::DATE)->addDay()->toDateString();
        $this->cell($staff['m6'], $templates['M6'], 'GUSTO', ['L'], $next);
        $this->cell($staff['a2'], $templates['A2'], 'GUSTO', ['L'], $next);
        $this->assertSame(1, $statistics()[$next]['min_count']);
        $this->assertCount(2, $statistics()[$next]['short']);
    }

    /**
     * Lỗi thực tế (lần chạy #105): dòng Lobby để trống "Chỉ tính ca" → không có chỗ OH(L) ở nhà hàng → chỉ xếp M9(L).
     * Nay: ô OH(L) được tạo cho outlet có người làm được OH, và bộ giải xếp OH(L) cho nữ waiter Nha Trang.
     */
    public function test_oh_l_is_scheduled_when_lobby_rule_has_no_shift_codes(): void
    {
        [$templates, $staff] = $this->world();
        $lobby = Outlet::query()->where('code', 'LOBBY')->value('id');
        $villa = Outlet::query()->where('code', 'VILLA')->value('id');

        foreach ([[$staff['oh'], $villa, null], [$staff['oh'], $lobby, null], [$staff['m6'], $lobby, $templates['M9']->getKey()]] as [$employee, $outlet, $template]) {
            \Botble\RestaurantRoster\Models\EmployeeSkill::query()->create(['employee_id' => $employee->getKey(), 'outlet_id' => $outlet, 'shift_template_id' => $template, 'level' => 'primary']);
        }

        $from = Carbon::parse(self::DATE);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['time_limit_seconds' => 4, 'max_iterations' => 60000]);
        $this->assertArrayHasKey(self::DATE . "|{$villa}|{$templates['OH']->getKey()}", $problem->requirements, 'Có chỗ OH(L) tại Villa');

        $result = (new \Botble\RestaurantRoster\Scheduling\HeuristicSolver())->solve($problem);
        $ohL = array_filter($result->assignments, fn ($r) => $r['employee_id'] === $staff['oh']->getKey() && $r['shift_id'] === $templates['OH']->getKey() && $r['support']);
        // Năng lực từ hồ sơ: cả 2 nữ waiter Cam Ranh (CR1, CR2) đều làm được M9(L).
        $m9 = array_filter($result->assignments, fn ($r) => in_array($r['employee_id'], [$staff['m6']->getKey(), $staff['a2']->getKey()], true) && $r['shift_id'] === $templates['M9']->getKey());

        $this->assertGreaterThanOrEqual(4, count($ohL), 'Nữ waiter Nha Trang được xếp OH(L)');
        $this->assertGreaterThanOrEqual(4, count($m9), 'Nữ waiter Cam Ranh được xếp M9(L)');
    }

    /** Lưu định biên Lobby có đoạn giờ không ca nào phủ (vd 08:30–17:00: 08:30–08:45 không ai ở Lobby) → báo lỗi rõ ràng. */
    public function test_lobby_window_that_no_shift_can_cover_is_rejected(): void
    {
        $this->world();
        $validate = function (string $start, string $end): array {
            $request = \Botble\RestaurantRoster\Http\Requests\CoverageRequirementRequest::create('/', 'POST', ['start_time' => $start, 'end_time' => $end,
                'min_staff' => 2, 'target_staff' => 2, 'is_confirmed' => 1, 'female_only' => 1]);
            $request->setContainer(app())->setRedirector(app('redirect'));

            try {
                $request->validateResolved();

                return [];
            } catch (\Illuminate\Validation\ValidationException $e) {
                return $e->errors()['start_time'] ?? [];
            }
        };

        $errors = $validate('08:30', '17:00');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('08:30–08:45', $errors[0]);
        $this->assertSame([], $validate('09:00', '16:45'), 'Khung chính 09:00–16:45 hợp lệ');
    }

    /**
     * Ca Lobby ưu tiên suy ra từ mẫu ca + hồ sơ (không ghi cứng): nữ waiter Nha Trang có năng lực OH → OH(L), Cam Ranh có năng lực M9 → M9(L);
     * "M9" luôn được ghi là "M9(L)"; lượt hỗ trợ lịch sử quy về số tuần thực sự đi làm.
     */
    public function test_preferred_lobby_shift_normalised_codes_and_fair_history(): void
    {
        [$templates, $staff] = $this->world();
        $lobby = Outlet::query()->where('code', 'LOBBY')->value('id');
        $villa = Outlet::query()->where('code', 'VILLA')->value('id');
        $gusto = Outlet::query()->where('code', 'GUSTO')->value('id');
        $skill = fn (Employee $e, $outlet, $template = null) => \Botble\RestaurantRoster\Models\EmployeeSkill::query()->create(['employee_id' => $e->getKey(), 'outlet_id' => $outlet, 'shift_template_id' => $template, 'level' => 'primary']);
        $skill($staff['oh'], $villa);
        $skill($staff['oh'], $lobby);
        $skill($staff['m6'], $gusto);
        $skill($staff['m6'], $lobby, $templates['M9']->getKey());
        $skill($staff['a2'], $gusto);

        // CR1 chỉ đi làm 2 trong 8 tuần trước (nghỉ dài), có 2 lượt M9(L) → tính như 8 lượt / 8 tuần.
        foreach (['2030-02-11', '2030-02-18'] as $date) {
            Shift::query()->create(['employee_id' => $staff['m6']->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $templates['M9']->getKey(),
                'outlet_id' => $lobby, 'tags' => ['L'], 'status' => 'published', 'start_at' => "{$date} 09:00:00", 'end_at' => "{$date} 17:00:00", 'hours' => 8]);
        }

        $from = Carbon::parse(self::DATE);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['history_weeks' => 8]);

        // CR2 không khai dòng năng lực Lobby nhưng là nữ waiter Cam Ranh → năng lực từ hồ sơ: ưu tiên M9(L).
        $this->assertSame([$staff['oh']->getKey() => $templates['OH']->getKey(), $staff['m6']->getKey() => $templates['M9']->getKey(),
            $staff['a2']->getKey() => $templates['M9']->getKey()], $problem->lobbyPreferred);
        // Mặc định (quản lý 28/10/2026): Lobby chỉ xét tuần đang xếp – không dùng lượt Lobby các tuần trước.
        $this->assertArrayNotHasKey('M9(L)', $problem->supportHistory[$staff['m6']->getKey()] ?? [], 'Không dùng lịch sử Lobby');
        $this->assertSame([], $problem->lobbyHistory, 'Không tải lịch sử Lobby');
        $this->assertFalse($problem->rules->lobbyHistoryFairness);

        // Bật lại cấu hình cũ: lượt quy về tuần đi làm (2 lượt / 2 tuần ≈ 8 lượt / 8 tuần).
        config(['plugins.restaurant-roster.general.scheduling.lobby_history_fairness' => true]);
        $legacy = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['history_weeks' => 8]);
        $this->assertSame(8, $legacy->supportHistory[$staff['m6']->getKey()]['M9(L)']);
        config(['plugins.restaurant-roster.general.scheduling.lobby_history_fairness' => false]);

        $values = app(\Botble\RestaurantRoster\Services\RosterAssigner::class)->values($staff['m6'], $from, ['kind' => Shift::KIND_SHIFT, 'template' => $templates['M9'], 'tags' => []]);
        $this->assertContains('L', (array) (is_string($values['tags']) ? json_decode($values['tags'], true) : $values['tags']), '"M9" được chuẩn hóa thành "M9(L)"');
    }

    /**
     * Lobby không phụ thuộc lịch sử 4–8 tuần: CR1 làm rất nhiều M9(L) các tuần trước → dữ liệu Lobby đưa vào thuật toán
     * (lượt Lobby lịch sử, ca Lobby ưu tiên, ràng buộc) giống hệt khi không có lịch sử. (Cân bằng sáng / chiều qua nhiều tuần vẫn giữ.)
     */
    public function test_lobby_inputs_ignore_past_weeks(): void
    {
        [$templates, $staff] = $this->world();
        $lobby = Outlet::query()->where('code', 'LOBBY')->value('id');
        $from = Carbon::parse(self::DATE);
        $build = fn () => app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['history_weeks' => 8]);
        $before = $build();

        foreach (range(1, 8) as $week) {
            foreach (range(0, 4) as $d) {
                $date = $from->copy()->subWeeks($week)->addDays($d)->toDateString();
                Shift::query()->create(['employee_id' => $staff['m6']->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $templates['M9']->getKey(),
                    'outlet_id' => $lobby, 'tags' => ['L'], 'status' => 'published', 'start_at' => "{$date} 09:00:00", 'end_at' => "{$date} 17:00:00", 'hours' => 8]);
            }
        }

        $after = $build();

        $this->assertSame([], $after->lobbyHistory, 'Không tải lịch sử Lobby');
        $this->assertSame($before->supportHistory, $after->supportHistory, 'Lượt Lobby các tuần trước không vào bài toán');
        $this->assertSame($before->lobbyPreferred, $after->lobbyPreferred);
        $this->assertEquals($before->coverage, $after->coverage, 'Định biên Lobby giữ nguyên');
    }

    /** @return array{0: array<string, ShiftTemplate>, 1: array<string, Employee>} */
    private function world(): array
    {
        foreach (['GUSTO' => 'Gusto', 'VILLA' => 'Villa', 'LOBBY' => 'Lobby'] as $code => $name) {
            Outlet::query()->create(['name' => $name, 'code' => $code, 'status' => 'published']);
        }

        $template = fn (string $code, string $start, string $end, array $extra = []) => ShiftTemplate::query()->create(
            ['code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end, 'status' => 'published', ...$extra]);
        $templates = [
            'OH' => $template('OH', '08:45:00', '16:45:00', ['lobby_start_time' => '08:45:00', 'lobby_end_time' => '16:45:00', 'allowed_residence' => 'nha_trang']),
            'M9' => $template('M9', '09:00:00', '17:00:00', ['support_station' => 'LOBBY', 'allowed_residence' => 'cam_ranh', 'allowed_gender' => 'female']),
            'M6' => $template('M6', '06:00:00', '14:00:00', ['lobby_start_time' => '09:00:00', 'lobby_end_time' => '14:00:00']),
            'A2' => $template('A2', '14:00:00', '22:00:00', ['lobby_start_time' => '14:00:00', 'lobby_end_time' => '17:00:00']),
        ];

        $waiter = Position::query()->create(['name' => 'Waiter', 'status' => 'published']);
        $make = fn (string $code, string $outlet, string $residence) => Employee::query()->create(['code' => $code, 'name' => "Synthetic {$code}", 'gender' => 'female',
            'outlet_id' => Outlet::query()->where('code', $outlet)->value('id'), 'position_id' => $waiter->getKey(), 'residence' => $residence,
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);

        return [$templates, ['oh' => $make('NT1', 'VILLA', 'nha_trang'), 'm6' => $make('CR1', 'GUSTO', 'cam_ranh'), 'a2' => $make('CR2', 'GUSTO', 'cam_ranh')]];
    }

    private function cell(Employee $employee, ShiftTemplate $template, string $outlet, array $tags, string $date = self::DATE): void
    {
        Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $template->getKey(),
            'outlet_id' => Outlet::query()->where('code', $outlet)->value('id'), 'tags' => $tags, 'status' => 'published',
            'start_at' => "{$date} {$template->start_time}", 'end_at' => "{$date} {$template->end_time}", 'hours' => 8]);
    }
}
