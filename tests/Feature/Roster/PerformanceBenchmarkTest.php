<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterScheduler;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Đo hiệu năng (quản lý 01/11/2026) – DỮ LIỆU GIẢ LẬP gần giống thật: Gusto, Villa, Night, Room Service, Lobby, Kid-Deline, quản lý,
 * ca cứng OH, OFF đã duyệt, ô khóa. Chỉ chạy khi ROSTER_BENCH=1 (chậm, không nằm trong bộ test thường):
 *   ROSTER_BENCH=1 php vendor/bin/phpunit tests/Feature/Roster/PerformanceBenchmarkTest.php
 * ROSTER_BENCH_SCALE=2 nhân đôi số nhân viên (đánh giá khả năng mở rộng). Kết quả in ra STDERR.
 */
class PerformanceBenchmarkTest extends TestCase
{
    private const WEEK = '2030-03-04';

    /** @var array<string, Outlet> */
    private array $outlets = [];

    /** @var array<string, ShiftTemplate> */
    private array $templates = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('ROSTER_BENCH')) {
            $this->markTestSkipped('Đo hiệu năng: đặt ROSTER_BENCH=1 để chạy.');
        }

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

        $this->world((int) (getenv('ROSTER_BENCH_SCALE') ?: 1));
    }

    private function world(int $scale): void
    {
        foreach (['GUSTO' => 1, 'VILLA' => 2, 'NIGHT' => 3, 'ROOM' => 4, 'LOBBY' => 5, 'KID' => 6] as $code => $order) {
            $this->outlets[$code] = Outlet::query()->create(['name' => ucfirst(strtolower($code)), 'code' => $code, 'order' => $order, 'status' => 'published']);
        }

        $template = fn (string $code, string $start, string $end, array $extra = []) => $this->templates[$code] = ShiftTemplate::query()->create(
            ['code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end, 'hours' => 8, 'break_minutes' => 0, 'status' => 'published', ...$extra]);
        $template('M6', '06:00:00', '14:00:00', ['lobby_start_time' => '09:00:00', 'lobby_end_time' => '14:00:00']);
        $template('A2', '14:00:00', '22:00:00', ['lobby_start_time' => '14:00:00', 'lobby_end_time' => '17:00:00']);
        $template('OH', '08:45:00', '16:45:00', ['lobby_start_time' => '08:45:00', 'lobby_end_time' => '16:45:00', 'allowed_residence' => 'nha_trang']);
        $template('M9', '09:00:00', '17:00:00', ['support_station' => 'LOBBY', 'allowed_residence' => 'cam_ranh', 'allowed_gender' => 'female']);
        $template('N', '22:00:00', '06:00:00');

        $captain = Position::query()->create(['name' => 'Junior Restaurant Captain', 'is_manager' => true, 'order' => 1, 'status' => 'published']);
        $hostess = Position::query()->create(['name' => 'Hostess', 'order' => 3, 'status' => 'published']);
        $waiter = Position::query()->create(['name' => 'Waiter', 'order' => 5, 'status' => 'published']);

        $requirement = fn (string $outlet, string $shift, int $min, int $target) => StaffingRequirement::query()->create(['outlet_id' => $this->outlets[$outlet]->getKey(),
            'shift_template_id' => $this->templates[$shift]->getKey(), 'status' => 'active', 'min_staff' => $min, 'target_staff' => $target, 'max_staff' => $target, 'is_confirmed' => true]);
        $requirement('NIGHT', 'N', 2, 2);
        $requirement('ROOM', 'M6', 1, 1);
        $requirement('ROOM', 'A2', 1, 1);
        $requirement('KID', 'M6', 1, 1);
        $requirement('KID', 'A2', 1, 1);

        $n = 0;
        $make = function (string $outlet, string $gender, $position, array $extra = []) use (&$n): Employee {
            $n++;

            return Employee::query()->create(['code' => sprintf('FB-%04d', $n), 'name' => "Synthetic {$n}", 'gender' => $gender, 'outlet_id' => $this->outlets[$outlet]->getKey(),
                'position_id' => $position->getKey(), 'residence' => $n % 3 ? 'cam_ranh' : 'nha_trang', 'weekly_hours' => 48, 'days_off_per_week' => 2,
                'birthday' => sprintf('%02d/%02d', $n % 28 + 1, $n % 12 + 1), 'auto_schedule' => true, 'status' => 'published', ...$extra]);
        };

        $people = [];

        for ($s = 0; $s < $scale; $s++) {
            foreach (['GUSTO' => [3, 10, 9], 'VILLA' => [3, 9, 9]] as $outlet => [$captains, $females, $males]) {
                foreach (range(1, $captains) as $k) {
                    $people[] = $make($outlet, $k % 2 ? 'male' : 'female', $captain);
                }

                $people[] = $make($outlet, 'female', $hostess);

                foreach (range(1, $females) as $k) {
                    $people[] = $make($outlet, 'female', $waiter);
                }

                foreach (range(1, $males) as $k) {
                    $people[] = $make($outlet, 'male', $waiter);
                }
            }

            $people[] = $make('GUSTO', 'male', $captain, ['fixed_shift_template_id' => $this->templates['OH']->getKey()]);

            foreach (range(1, 3) as $k) {
                $people[] = $make('NIGHT', 'male', $waiter, ['night_role' => Employee::NIGHT_CORE]);
            }

            foreach (range(1, 2) as $k) {
                $people[] = $make('NIGHT', 'male', $waiter);
            }

            foreach (range(1, 3) as $k) {
                $people[] = $make('ROOM', $k % 2 ? 'male' : 'female', $waiter, ['room_service_fixed' => true]);
            }
        }

        // OFF đã duyệt (khóa) và ô khóa rải trong tuần.
        foreach (array_slice($people, 0, 10 * $scale) as $k => $employee) {
            $date = Carbon::parse(self::WEEK)->addDays($k % 6)->toDateString();
            Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date, 'kind' => Shift::KIND_OFF, 'status' => 'draft',
                'is_locked' => true, 'lock_reason' => Shift::LOCK_OFF_REQUEST, 'hours' => 0]);
        }

        foreach (array_slice($people, 12 * $scale, 6 * $scale) as $k => $employee) {
            $date = Carbon::parse(self::WEEK)->addDays($k % 6)->toDateString();
            Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $this->templates['A2']->getKey(),
                'outlet_id' => $employee->outlet_id, 'status' => 'draft', 'is_locked' => true, 'lock_reason' => Shift::LOCK_CONFIRMED,
                'start_at' => "{$date} 14:00:00", 'end_at' => "{$date} 22:00:00", 'hours' => 8]);
        }
    }

    /** @return array{0: float, 1: int, 2: mixed} [ms, số truy vấn, kết quả] */
    private function measure(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $result = $work();
        $ms = (hrtime(true) - $start) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$ms, $queries, $result];
    }

    private function report(string $label, float $ms, ?int $queries = null, string $extra = ''): void
    {
        fwrite(STDERR, sprintf("  %-38s %9.0f ms%s%s\n", $label, $ms, $queries === null ? '' : sprintf('  %4d truy vấn', $queries), $extra ? "  {$extra}" : ''));
    }

    private function user(): User
    {
        $user = new User();
        $user->forceFill(['email' => uniqid('bench') . '@example.test', 'username' => uniqid('bench'), 'password' => bcrypt('secret'),
            'first_name' => 'Bench', 'last_name' => 'Manager', 'super_user' => true, 'permissions' => []])->save();

        return $user;
    }

    /** Thời gian từng chỉ số toàn cục trong hàm chấm điểm (gọi mỗi vòng lặp của thuật toán). ROSTER_BENCH_PROFILE=1. */
    public function test_profile_cost_terms(): void
    {
        if (! getenv('ROSTER_BENCH_PROFILE')) {
            $this->markTestSkipped('ROSTER_BENCH_PROFILE=1');
        }

        $from = Carbon::parse(self::WEEK);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['time_limit_seconds' => 3]);
        $state = new \Botble\RestaurantRoster\Scheduling\ScheduleState($problem);
        $solver = new HeuristicSolver();
        (fn () => $this->warmStart($state))->call($solver);
        (fn () => $this->construct($state, microtime(true) + 3))->call($solver);
        $n = 2000;
        fwrite(STDERR, "\n=== Thời gian mỗi lần gọi (µs, trung bình {$n} lần) ===\n");

        foreach (['objectiveValues', 'sourceShortages', 'lobbyRound', 'femaleBalance', 'maleBalance', 'waiterBalance', 'homeBalance', 'managerOffSpread', 'offSpread', 'nightWithoutCore'] as $method) {
            $call = fn () => $this->{$method}();
            $start = hrtime(true);

            for ($k = 0; $k < $n; $k++) {
                $call->call($state);
            }

            fwrite(STDERR, sprintf("  %-20s %8.1f µs\n", $method, (hrtime(true) - $start) / 1e3 / $n));
        }

        $this->assertTrue(true);
    }

    /**
     * Dấu vân tay lịch với số vòng lặp cố định (không phụ thuộc thời gian máy): tối ưu tốc độ phải cho đúng cùng một lịch.
     * ROSTER_BENCH_FINGERPRINT=1.
     */
    public function test_fingerprint(): void
    {
        if (! getenv('ROSTER_BENCH_FINGERPRINT')) {
            $this->markTestSkipped('ROSTER_BENCH_FINGERPRINT=1');
        }

        $from = Carbon::parse(self::WEEK);
        $problem = app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['time_limit_seconds' => 600, 'max_iterations' => 30000]);
        $start = hrtime(true);
        $result = (new HeuristicSolver())->solve($problem);
        $ms = (hrtime(true) - $start) / 1e6;
        $cells = array_map(fn ($r) => implode('|', [$r['employee_id'], $r['date'], $r['kind'], $r['outlet_id'] ?? '', $r['shift_id'] ?? '', (int) ($r['support'] ?? 0)]), $result->assignments);
        sort($cells);
        // Đo chi tiết khi ScheduleState được gắn bộ đếm tạm (thuộc tính tĩnh $prof) – không có trong mã chính thức.
        foreach (property_exists(\Botble\RestaurantRoster\Scheduling\ScheduleState::class, 'prof') ? \Botble\RestaurantRoster\Scheduling\ScheduleState::$prof : [] as $fn => $ns) {
            $calls = \Botble\RestaurantRoster\Scheduling\ScheduleState::$calls[$fn];
            fwrite(STDERR, sprintf("  %-16s %7.0f ms  %8d lần  %6.1f µs/lần\n", $fn, $ns / 1e6, $calls, $ns / 1e3 / $calls));
        }

        fwrite(STDERR, sprintf("\n  Dấu vân tay: %s  điểm phạt %s  vòng lặp %s  %.0f ms\n", md5(implode(';', $cells)), $result->solverMetadata['best_cost'] ?? '?', $result->solverMetadata['iterations'] ?? '?', $ms));
        $this->assertTrue(true);
    }

    public function test_benchmark(): void
    {
        $employees = Employee::query()->count();
        fwrite(STDERR, "\n=== Đo hiệu năng – {$employees} nhân viên, tuần " . self::WEEK . " ===\n");
        $user = $this->user();
        $this->actingAs($user);
        $grid = fn () => $this->getJson(route('roster.grid', ['week' => self::WEEK]))->assertJson(['error' => false]);

        $grid(); // lần đầu nạp route / view
        [$ms, $q] = $this->measure($grid);
        $this->report('Mở bảng xếp ca (AJAX grid)', $ms, $q);

        [$ms, $q] = $this->measure(fn () => $this->get(route('roster.index', ['week' => self::WEEK]))->assertOk());
        $this->report('Mở trang Bảng xếp ca (đầy đủ)', $ms, $q);

        $from = Carbon::parse(self::WEEK);
        $to = $from->copy()->addDays(6);
        [$ms, $q, $problem] = $this->measure(fn () => app(ProblemBuilder::class)->build($from, $to));
        $this->report('Dựng dữ liệu thuật toán (ProblemBuilder)', $ms, $q);

        // Thuật toán với các giới hạn thời gian khác nhau: chất lượng (điểm phạt) so với thời gian.
        foreach (array_map('intval', explode(',', getenv('ROSTER_BENCH_LIMITS') ?: '30')) as $limit) {
            $problem = app(ProblemBuilder::class)->build($from, $to, null, ['time_limit_seconds' => $limit]);
            [$ms, , $result] = $this->measure(fn () => (new HeuristicSolver())->solve($problem));
            $meta = $result->solverMetadata;
            $this->report("Thuật toán (giới hạn {$limit}s)", $ms, null, sprintf('trạng thái %s, điểm phạt %s, vòng lặp %s, tốt nhất lúc %s ms, chưa đủ %d ô',
                $result->status, $meta['best_cost'] ?? '?', $meta['iterations'] ?? '?', $meta['best_found_ms'] ?? '?', count($result->unfilledRequirements)));

            if (getenv('ROSTER_BENCH_MOVES')) {
                foreach ($meta['moves'] ?? [] as $move => $m) {
                    fwrite(STDERR, sprintf("      %-22s thử %7d  hợp lệ %7d  nhận %6d  tốt hơn %4d  %7.0f ms  (%.1f µs/lần)
", $move, $m['tried'], $m['valid'], $m['accepted'], $m['improved'], $m['ms'], $m['ms'] * 1000 / max(1, $m['tried'])));
                }
            }
        }

        $scheduler = app(RosterScheduler::class);
        [$ms, $q, $run] = $this->measure(fn () => $scheduler->run($from, $to, null, 'generate', $user->getKey()));
        $this->report('Tạo lịch tự động (run, mặc định)', $ms, $q, 'trạng thái ' . $run->status);

        [$ms, $q] = $this->measure(fn () => $scheduler->apply($run, $user->getKey()));
        $this->report('Áp dụng lịch (apply)', $ms, $q, Shift::query()->count() . ' ô');

        [$ms, $q] = $this->measure($grid);
        $this->report('Mở bảng xếp ca sau khi có lịch', $ms, $q);

        // Tách phần dựng dữ liệu bảng và phần kết xuất HTML.
        $controller = app(\Botble\RestaurantRoster\Http\Controllers\RosterController::class);
        $request = \Illuminate\Http\Request::create('/', 'GET', ['week' => self::WEEK]);
        $request->setUserResolver(fn () => $user);
        [$ms, $q, $data] = $this->measure(fn () => (fn () => $this->buildGrid($request))->call($controller));
        $this->report('  – dựng dữ liệu bảng (buildGrid)', $ms, $q);

        $laps = $GLOBALS['__lap'] ?? [];
        $start = null;

        foreach (array_slice($laps, -8) as [$label, $t]) {
            if ($label === 'start') {
                $start = $prev = $t;

                continue;
            }

            if ($start !== null) {
                fwrite(STDERR, sprintf("        %-44s %7.0f ms
", $label, ($t - $prev) / 1e6));
                $prev = $t;
            }
        }
        [$ms, $q, $html] = $this->measure(fn () => view('plugins/restaurant-roster::roster.grid', ['grid' => $data, 'canManage' => true])->render());
        $this->report('  – kết xuất HTML (Blade)', $ms, $q, round(strlen($html) / 1024) . ' KB');

        // Chi phí từng kiểu đọc thuộc tính trên ô lịch đã tải (µs / lần).
        $loaded = Shift::query()->with(['template', 'employee'])->get();
        $probe = function (string $label, callable $read) use ($loaded): void {
            $start = hrtime(true);

            foreach ($loaded as $shift) {
                $read($shift);
            }

            fwrite(STDERR, sprintf("      %-30s %6.1f µs/lần\n", $label, (hrtime(true) - $start) / 1e3 / max(1, $loaded->count())));
        };
        $probe('date->toDateString()', fn (Shift $s) => $s->date->toDateString());
        $probe('start_at', fn (Shift $s) => $s->start_at);
        $probe('tags', fn (Shift $s) => $s->tags);
        $probe('isWorking()', fn (Shift $s) => $s->isWorking());
        $probe('template->code', fn (Shift $s) => $s->template?->code);
        $probe('notation', fn (Shift $s) => $s->notation);
        $probe('employee->name', fn (Shift $s) => $s->employee?->name);
        $probe('cellState()', fn (Shift $s) => $s->cellState());
        $probe('isRecordedOff()', fn (Shift $s) => $s->isRecordedOff());
        $probe('kind', fn (Shift $s) => $s->kind);
        $probe('getAttribute(kind)', fn (Shift $s) => $s->getAttribute('kind'));
        $probe('getAttributes()[kind]', fn (Shift $s) => $s->getAttributes()['kind']);
        $probe('hasGetMutator(kind)', fn (Shift $s) => $s->hasGetMutator('kind'));
        $probe('hasCast(kind)', fn (Shift $s) => $s->hasCast('kind'));
        $probe('getCasts()', fn (Shift $s) => $s->getCasts());
        $probe('(rỗng)', fn (Shift $s) => null);

        $cell = Shift::query()->where('is_locked', false)->where('kind', Shift::KIND_SHIFT)->first();
        [$ms, $q] = $this->measure(fn () => $this->postJson(route('roster.assign'), ['employee_id' => $cell->employee_id, 'date' => $cell->date->toDateString(),
            'week' => self::WEEK, 'action' => 'shift', 'shift_template_id' => $this->templates['M6']->getKey()])->assertJson(['error' => false]));
        $this->report('Sửa một ô (assign)', $ms, $q);

        $this->assertTrue(true);
    }
}
