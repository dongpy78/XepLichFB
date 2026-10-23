<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Chế độ "balance" (quản lý 23/10/2026) – DỮ LIỆU GIẢ LẬP: nhà hàng không còn định biên, chỉ có chỗ mở M6 / A2;
 * quản lý OFF đều theo ngày và cân bằng M6 / A2; nam / nữ waiter cân bằng M6 / A2 (sau Lobby, sau Night); Night đúng 3 người / ngày.
 */
class BalanceModeTest extends TestCase
{
    use SyntheticRoster;

    private const SKILLS = [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'];

    /** Chỗ mở M6 / A2 như ProblemBuilder::openSlots(): 0 tối thiểu, 0 mục tiêu, sức chứa lớn. */
    private function open(array $days): array
    {
        return [...array_map(fn ($d) => new Requirement($d, self::VILLA, self::M6, 0, 0, 30), $days), ...array_map(fn ($d) => new Requirement($d, self::VILLA, self::A2, 0, 0, 30), $days)];
    }

    private function manager(int $id): EmployeeSpec
    {
        return new EmployeeSpec($id, "Captain #{$id}", 'male', self::VILLA, 48, 2, self::SKILLS, [], 'cam_ranh', 200, isManager: true, managerShiftIds: [self::M6, self::A2]);
    }

    private function waiter(int $id, string $gender, array $extra = [], ?string $role = null): EmployeeSpec
    {
        return new EmployeeSpec($id, "Waiter #{$id}", $gender, self::VILLA, 48, 2, self::SKILLS + $extra, [], 'cam_ranh', self::WAITER,
            isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male', nightRole: $role);
    }

    private function onShift(SolveResult $result, array $ids, string $date, int $shift): int
    {
        return count(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['shift_id'] === $shift
            && $r['outlet_id'] === self::VILLA && ! $r['support'] && in_array($r['employee_id'], $ids, true)));
    }

    /** Quản lý: OFF chia đều theo ngày; mỗi ngày quản lý M6 / A2 lệch tối đa 1; không có ô thiếu (không còn định biên nhà hàng). */
    public function test_manager_offs_spread_and_shifts_balanced(): void
    {
        $days = $this->days();
        $managers = range(1, 7);
        $problem = $this->problem(array_map(fn ($id) => $this->manager($id), $managers), $this->open($days), ['time_limit' => 60, 'max_iterations' => 40000]);
        $result = $this->solve($problem);

        $offs = array_map(fn ($d) => count($managers) - $this->onShift($result, $managers, $d, self::M6) - $this->onShift($result, $managers, $d, self::A2), $days);
        // 7 quản lý × 2 ngày OFF liền nhau trong tuần: không thể đúng 2 người / ngày (thứ Hai / Chủ nhật chỉ ghép được một phía) → lệch tối đa 2.
        $this->assertLessThanOrEqual(2, max($offs) - min($offs), 'Quản lý OFF / ngày: ' . json_encode($offs));
        $this->assertLessThanOrEqual(3, max($offs), 'Không ngày nào dồn quá nhiều quản lý OFF: ' . json_encode($offs));

        foreach ($days as $d) {
            $this->assertLessThanOrEqual(1, abs($this->onShift($result, $managers, $d, self::M6) - $this->onShift($result, $managers, $d, self::A2)), "{$d}: quản lý M6 / A2");
        }

        $this->assertSame([], $result->unfilledRequirements);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Nhiều quản lý cùng có OFF đã duyệt một ngày: giữ nguyên OFF, có cảnh báo. */
    public function test_approved_manager_offs_kept_and_warned(): void
    {
        $days = $this->days();
        $day = $days[5];
        $hard = array_fill_keys([1, 2, 3, 4], [$day => 'Xin OFF']);
        $result = $this->solve($this->problem(array_map(fn ($id) => $this->manager($id), range(1, 7)), $this->open($days), ['hard' => $hard, 'time_limit' => 60, 'max_iterations' => 30000]));

        foreach ([1, 2, 3, 4] as $id) {
            $this->assertNull($this->shiftOf($result, $id, $day), "#{$id}: giữ OFF đã duyệt");
        }

        $warning = array_values(array_filter($result->warnings, fn ($w) => $w['code'] === 'manager_off' && $w['date'] === $day))[0] ?? null;
        $this->assertNotNull($warning, 'Cảnh báo nhiều quản lý OFF cùng ngày');
        $this->assertStringContainsString('đã duyệt', $warning['message']);
    }

    /** Nam waiter (4 người) và nữ waiter chia đều M6 / A2 – nữ sau khi đã rút người đi Lobby. */
    public function test_male_and_female_waiters_balanced_after_lobby(): void
    {
        $days = $this->days();
        $men = range(11, 14);
        $women = range(21, 29);
        $problem = $this->problem([
            ...array_map(fn ($id) => $this->waiter($id, 'male'), $men),
            ...array_map(fn ($id) => $this->waiter($id, 'female', [self::LOBBY . ':0' => 'eligible']), $women),
        ], $this->open($days), ['coverage' => array_map(fn ($d) => new Coverage($d, 540, 1005, 1, 1, 'Lobby', null, true), $days),
            'time_limit' => 60, 'max_iterations' => 60000]);
        $result = $this->solve($problem);

        foreach ($days as $d) {
            $this->assertLessThanOrEqual(1, abs($this->onShift($result, $men, $d, self::M6) - $this->onShift($result, $men, $d, self::A2)), "{$d}: nam M6 / A2");
            $this->assertLessThanOrEqual(1, abs($this->onShift($result, $women, $d, self::M6) - $this->onShift($result, $women, $d, self::A2)), "{$d}: nữ waiter trực tiếp M6 / A2");
        }

        $this->assertSame([], array_values(array_filter($result->unfilledRequirements, fn ($u) => isset($u['lobby']))), 'Lobby đủ');
        $this->assertHardConstraintsHold($problem, $result);
    }

    /**
     * Night: 2 Night cứng + người luân phiên; nhà hàng không còn định biên → không ai bị giữ lại vì "outlet nguồn thiếu tối thiểu":
     * Night đúng 3 người mọi ngày, ≥ 1 Night cứng; nam waiter còn lại chia đều M6 / A2.
     */
    public function test_night_always_three_with_open_restaurants(): void
    {
        $days = $this->days();
        $night = [self::NIGHT . ':' . self::N => 'primary'];
        $core = fn (int $id) => new EmployeeSpec($id, "Core #{$id}", 'male', self::NIGHT, 48, 2, $night, [self::NIGHT . ':' . self::N], 'cam_ranh', self::WAITER,
            isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_CORE);
        $rotation = [11, 12, 13, 14];
        $problem = $this->problem([$core(1), $core(2),
            ...array_map(fn ($id) => $this->waiter($id, 'male', [self::NIGHT . ':' . self::N => 'eligible'], EmployeeSpec::NIGHT_ROTATION), $rotation),
            ...array_map(fn ($id) => $this->waiter($id, 'male'), [15, 16]),
        ], [...$this->open($days), ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3)],
            ['night_plan' => array_fill_keys($days, $rotation), 'time_limit' => 90, 'max_iterations' => 120000]);
        $result = $this->solve($problem);

        foreach ($days as $d) {
            $ids = array_column(array_filter($result->assignments, fn ($r) => $r['date'] === $d && $r['shift_id'] === self::N), 'employee_id');
            $this->assertCount(3, $ids, "{$d}: Night đúng 3");
            $this->assertNotEmpty(array_intersect($ids, [1, 2]), "{$d}: có Night cứng");
        }

        $balance = $result->supportReport['balance'];
        $this->assertCount(7, $balance);
        $this->assertCount(3, $balance[0]['night']);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Thiếu người luân phiên (chỉ 1 người): Night không đủ 3 – báo rõ ngày thiếu, không phá OFF / ràng buộc. */
    public function test_night_shortage_reported_when_not_enough_people(): void
    {
        $days = $this->days();
        $night = [self::NIGHT . ':' . self::N => 'primary'];
        $core = fn (int $id) => new EmployeeSpec($id, "Core #{$id}", 'male', self::NIGHT, 48, 2, $night, [self::NIGHT . ':' . self::N], 'cam_ranh', self::WAITER,
            isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_CORE);
        $problem = $this->problem([$core(1), $core(2), $this->waiter(11, 'male', [self::NIGHT . ':' . self::N => 'eligible'], EmployeeSpec::NIGHT_ROTATION)],
            [...$this->open($days), ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3)],
            ['night_plan' => array_fill_keys($days, [11]), 'time_limit' => 30, 'max_iterations' => 30000]);
        $result = $this->solve($problem);

        $gaps = array_filter($result->unfilledRequirements, fn ($u) => $u['shift_id'] === self::N);
        $this->assertNotEmpty($gaps, 'Báo ngày Night thiếu người');
        $this->assertSame([], $result->hardConstraintViolations);
    }
}
