<?php

namespace Tests\Feature\Roster;

use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\OutletDay;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterScheduler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Lobby khi chỉ một nhà hàng hoạt động (quản lý 02/11/2026) – DỮ LIỆU GIẢ LẬP: nữ waiter Villa + Gusto là một nhóm, M9(L) / OH(L) chia đều trên cả
 * nhóm (không ai 2 ca khi còn người chưa có ca); người có ca cứng M6 không xếp M9(L), chỉ M6(L). Khi cả hai cùng mở, trần 1 người / nhà hàng giữ nguyên.
 */
class SingleRestaurantLobbyTest extends TestCase
{
    private const WEEK = '2030-03-04';

    /** @var array<string, Outlet> */
    private array $outlets = [];

    /** @var array<string, ShiftTemplate> */
    private array $templates = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(RestaurantRosterServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);

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
    }

    private function waiter(string $code, string $outlet, string $gender, string $residence, array $extra = []): Employee
    {
        $position = Position::query()->firstOrCreate(['name' => 'Waiter'], ['status' => 'published']);

        return Employee::query()->create(['code' => $code, 'name' => "Synthetic {$code}", 'gender' => $gender, 'outlet_id' => $this->outlets[$outlet]->getKey(),
            'position_id' => $position->getKey(), 'residence' => $residence, 'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published', ...$extra]);
    }

    private function closeVillaAllWeek(): void
    {
        foreach (range(0, 6) as $d) {
            OutletDay::query()->create(['outlet_id' => $this->outlets['VILLA']->getKey(), 'date' => Carbon::parse(self::WEEK)->addDays($d)->toDateString(), 'is_closed' => true]);
        }
    }

    private function solve(): SolveResult
    {
        $from = Carbon::parse(self::WEEK);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['time_limit_seconds' => 5, 'search_min_seconds' => null]);

        return (new HeuristicSolver())->solve($problem);
    }

    /** @return array<int, int> [employee id] => số ca của loại này (ở Lobby hoặc có cờ L) */
    private function lobbyCounts(SolveResult $result, string $code): array
    {
        $counts = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['shift_id'] === $this->templates[$code]->getKey() && ($row['support'] || $row['outlet_id'] === $this->outlets['LOBBY']->getKey())) {
                $counts[$row['employee_id']] = ($counts[$row['employee_id']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** Đội hình: Villa nhiều người Cam Ranh hơn số ngày không phải vấn đề – trước đây Villa buộc góp 1 người mỗi ngày nên có người 2 ca. */
    private function team(): array
    {
        $people = [];

        foreach (['GUSTO' => 'G', 'VILLA' => 'V'] as $outlet => $prefix) {
            foreach (range(1, $outlet === 'VILLA' ? 3 : 8) as $k) {
                $people["{$prefix}C{$k}"] = $this->waiter("{$prefix}C{$k}", $outlet, 'female', 'cam_ranh');
            }

            foreach (range(1, 2) as $k) {
                $people["{$prefix}N{$k}"] = $this->waiter("{$prefix}N{$k}", $outlet, 'female', 'nha_trang');
                $people["{$prefix}M{$k}"] = $this->waiter("{$prefix}M{$k}", $outlet, 'male', 'cam_ranh');
            }
        }

        return $people;
    }

    public function test_villa_closed_m9_and_oh_shared_across_both_restaurants(): void
    {
        $people = $this->team();
        $this->closeVillaAllWeek();
        $result = $this->solve();

        $this->assertSame(0, $result->softConstraintScores['objective']['lobby_round'], 'Không ai 2 ca khi còn người chưa có ca');

        $m9 = $this->lobbyCounts($result, 'M9');
        $camRanh = array_filter($people, fn (Employee $e) => $e->residence === 'cam_ranh' && $e->gender === 'female');
        $values = array_map(fn (Employee $e) => $m9[$e->getKey()] ?? 0, $camRanh);
        $this->assertGreaterThan(0, array_sum($values));

        if (min($values) === 0) {
            $this->assertLessThanOrEqual(1, max($values), 'Còn người chưa có M9(L) thì không ai có 2: ' . json_encode($values));
        }

        // Người Villa (nhà hàng đang đóng) nhận được OH(L) tại Gusto – trước đây bị chặn vì "(L) chỉ từ outlet chính".
        $oh = $this->lobbyCounts($result, 'OH');
        $this->assertGreaterThan(0, ($oh[$people['VN1']->getKey()] ?? 0) + ($oh[$people['VN2']->getKey()] ?? 0), 'Nữ Villa ở Nha Trang có OH(L)');

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift') {
                $this->assertNotSame($this->outlets['VILLA']->getKey(), $row['outlet_id'], 'Không ai làm ở Villa đang đóng');
            }
        }
    }

    /** Cả hai nhà hàng mở: mỗi nhà hàng góp tối đa 1 người cho một khung Lobby, và (L) chỉ từ outlet chính (quy tắc cũ giữ nguyên). */
    public function test_both_open_keeps_one_per_restaurant_and_home_only_support(): void
    {
        $people = $this->team();
        $result = $this->solve();
        $byEmployee = collect($people)->keyBy(fn (Employee $e) => $e->getKey());
        $lobby = $this->outlets['LOBBY']->getKey();
        $morning = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] !== 'shift') {
                continue;
            }

            $home = (int) $byEmployee[$row['employee_id']]->outlet_id;

            if ($row['support']) {
                $this->assertSame($home, $row['outlet_id'], '(L) chỉ từ outlet chính khi cả hai mở');
            }

            if ($row['outlet_id'] === $lobby || ($row['support'] && $row['shift_id'] === $this->templates['OH']->getKey())) {
                $morning[$row['date']][$home] = ($morning[$row['date']][$home] ?? 0) + 1;
            }
        }

        $this->assertNotEmpty($morning);

        foreach ($morning as $date => $sources) {
            $this->assertLessThanOrEqual(1, max($sources), "{$date}: mỗi nhà hàng tối đa 1 người: " . json_encode($sources));
        }
    }

    /** Ca cứng M6: không bao giờ M9(L); đi Lobby thì M6(L). Áp dụng vào bảng: mã ca M6(L), làm tại nhà hàng còn mở, không (G). */
    public function test_fixed_m6_gets_m6_l_never_m9(): void
    {
        $m6 = $this->templates['M6']->getKey();
        $people = [];

        foreach (['GUSTO' => 'G', 'VILLA' => 'V'] as $outlet => $prefix) {
            foreach (range(1, 4) as $k) {
                $people[] = $this->waiter("{$prefix}F{$k}", $outlet, 'female', 'cam_ranh', ['fixed_shift_template_id' => $m6]);
            }
        }

        foreach (range(1, 4) as $k) {
            $this->waiter("GA{$k}", 'GUSTO', 'female', 'nha_trang');
        }

        $this->closeVillaAllWeek();
        $from = Carbon::parse(self::WEEK);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6));
        $spec = $problem->employees[$people[0]->getKey()];
        $this->assertNull($spec->skillLevel($this->outlets['LOBBY']->getKey(), $this->templates['M9']->getKey()), 'Ca cứng M6 không được M9');
        $this->assertTrue($spec->canSupport($this->outlets['LOBBY']->getKey(), $m6), 'Ca cứng M6 vẫn hỗ trợ Lobby được');
        $this->assertArrayNotHasKey($people[0]->getKey(), $problem->lobbyPreferred, 'Không vào vòng chia M9(L) / OH(L)');

        $scheduler = app(RosterScheduler::class);
        $scheduler->apply($scheduler->run($from, $from->copy()->addDays(6), null, 'generate', 1, ['time_limit_seconds' => 5, 'search_min_seconds' => null]), 1);
        $ids = array_map(fn (Employee $e) => $e->getKey(), $people);
        $cells = Shift::query()->with('template')->whereIn('employee_id', $ids)->where('kind', Shift::KIND_SHIFT)->get();
        $this->assertNotEmpty($cells);
        $this->assertEmpty(array_diff($cells->map(fn (Shift $s) => $s->notation)->unique()->all(), ['M6', 'M6(L)']), 'Chỉ M6 hoặc M6(L): ' . $cells->map(fn (Shift $s) => $s->notation)->unique()->implode(', '));
        $this->assertGreaterThan(0, $cells->filter(fn (Shift $s) => $s->notation === 'M6(L)')->count(), 'Có người đi M6(L)');
        $this->assertSame([$this->outlets['GUSTO']->getKey()], $cells->pluck('outlet_id')->map(fn ($id) => (int) $id)->unique()->values()->all(), 'Làm tại Gusto');
    }
}
