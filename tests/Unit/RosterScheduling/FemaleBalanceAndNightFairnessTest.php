<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Cân bằng nữ waiter M6 / A2 sau khi rút người đi Lobby, và Night công bằng / không xếp ngày thiếu Night cứng (quản lý 23/10/2026) – DỮ LIỆU GIẢ LẬP.
 */
class FemaleBalanceAndNightFairnessTest extends TestCase
{
    use SyntheticRoster;

    private function waitress(int $id, array $extra = []): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", 'female', self::VILLA, 48, 2,
            [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', ...$extra], [], 'cam_ranh', self::WAITER, isFemaleStaff: true);
    }

    private function female(SolveResult $result, string $date, int $shift): int
    {
        return count(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['outlet_id'] === self::VILLA
            && $r['shift_id'] === $shift && ! $r['support'] && $r['employee_id'] < 40));
    }

    /**
     * Không khai nữ waiter tối thiểu / mục tiêu: sau khi rút người đi Lobby (M6(L) 09–14h / A2(L) 14–17h), nữ waiter trực tiếp
     * vẫn được chia M6 / A2 theo tỉ lệ định biên của ca (ở đây 1:1), không dồn một ca.
     */
    public function test_female_waiters_balanced_between_m6_and_a2_after_lobby(): void
    {
        $days = $this->days();
        $women = array_map(fn ($id) => $this->waitress($id, [self::LOBBY . ':0' => 'eligible']), range(1, 9));
        $men = array_map(fn ($id) => new EmployeeSpec($id, "Synthetic #{$id}", 'male', self::VILLA, 48, 2,
            [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'], [], 'cam_ranh', self::WAITER, isMaleStaff: true), range(40, 45));
        $problem = $this->problem([...$women, ...$men], [
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 4, 5),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 4, 5),
        ], ['coverage' => array_map(fn ($d) => new Coverage($d, 540, 1005, 1, 1, 'Lobby', null, true), $days), 'time_limit' => 60, 'max_iterations' => 60000]);
        $result = $this->solve($problem);

        foreach ($days as $date) {
            [$am, $pm] = [$this->female($result, $date, self::M6), $this->female($result, $date, self::A2)];
            $this->assertLessThanOrEqual(1, abs($am - $pm), "{$date}: nữ waiter trực tiếp M6 {$am} / A2 {$pm}");
        }

        $this->assertSame([], array_filter($this->lobbyGaps($result)));
        $this->assertSame([], array_filter($result->warnings, fn ($w) => $w['code'] === 'female_balance'));
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Tỉ lệ theo định biên, không ép bằng nhau: mục tiêu nữ waiter M6 3, A2 2 → 3 / 2. */
    public function test_balance_follows_configured_ratio(): void
    {
        $day = $this->day(0);
        $result = $this->solve($this->problem(array_map(fn ($id) => $this->waitress($id), range(1, 5)), [
            new Requirement($day, self::VILLA, self::M6, 0, 3, 5, targetFemale: 3),
            new Requirement($day, self::VILLA, self::A2, 0, 2, 5, targetFemale: 2),
        ], ['days' => [$day], 'time_limit' => 30, 'max_iterations' => 20000]));

        $this->assertSame([3, 2], [$this->female($result, $day, self::M6), $this->female($result, $day, self::A2)]);
    }

    /** Ô khóa không bị đổi để cân bằng; không cân được thì có cảnh báo nêu số liệu. */
    public function test_locked_cells_kept_and_imbalance_explained(): void
    {
        $day = $this->day(0);
        $fixed = array_map(fn ($id) => new FixedAssignment($id, $day, FixedAssignment::KIND_SHIFT, self::VILLA, self::A2), range(1, 5));
        $result = $this->solve($this->problem(array_map(fn ($id) => $this->waitress($id), range(1, 6)), [
            new Requirement($day, self::VILLA, self::M6, 0, 3, 6),
            new Requirement($day, self::VILLA, self::A2, 0, 3, 6),
        ], ['days' => [$day], 'fixed' => $fixed, 'time_limit' => 30, 'max_iterations' => 10000]));

        $this->assertSame(5, $this->female($result, $day, self::A2), 'Ô khóa giữ nguyên');
        $warning = array_values(array_filter($result->warnings, fn ($w) => $w['code'] === 'female_balance'))[0] ?? null;
        $this->assertNotNull($warning);
        $this->assertStringContainsString('A2 5', $warning['message']);
    }

    /** Night: ngày cả hai Night cứng nghỉ → không xếp người luân phiên nào, báo không có Night cứng. */
    public function test_no_rotation_night_on_day_without_core(): void
    {
        $day = $this->day(3);
        [$result] = $this->nightWeek(['hard' => [1 => [$day => 'Nghỉ phép'], 2 => [$day => 'Nghỉ phép']]]);

        $this->assertSame([], array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['shift_id'] === self::N), 'Không xếp Night ngày không có Night cứng');
        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $day && $u['shift_code'] === 'Night cố định'));
        $this->assertSame([], $result->hardConstraintViolations);
    }

    /** Night công bằng dài hạn: người nhiều đêm Night các tuần trước nhận ít đêm hơn trong tuần (đêm thứ 3 dành cho người ít lịch sử). */
    public function test_extra_nights_go_to_people_with_less_history(): void
    {
        [$result, , $days] = $this->nightWeek(['night_history' => [11 => 4, 12 => 4]]);
        $nights = fn (int $id) => count(array_filter($days, fn ($d) => $this->shiftOf($result, $id, $d) === [self::NIGHT, self::N]));

        foreach ($days as $date) {
            $this->assertCount(3, array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['shift_id'] === self::N), "{$date}: đủ 3 người");
        }

        $this->assertLessThanOrEqual($nights(13) + $nights(14), $nights(11) + $nights(12), 'Người nhiều lịch sử Night nhận ít đêm hơn');
    }

    /** @return array{0: SolveResult, 1: \Botble\RestaurantRoster\Scheduling\Problem, 2: string[]} */
    private function nightWeek(array $options): array
    {
        $days = $this->days();
        $core = fn (int $id) => new EmployeeSpec($id, "Synthetic #{$id}", 'male', self::NIGHT, 48, 2, [self::NIGHT . ':' . self::N => 'primary'], [self::NIGHT . ':' . self::N],
            'cam_ranh', self::WAITER, isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_CORE);
        $rotation = fn (int $id) => new EmployeeSpec($id, "Synthetic #{$id}", 'male', self::VILLA, 48, 2,
            [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible'], [], 'cam_ranh', self::WAITER,
            isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_ROTATION);
        $women = array_map(fn ($id) => $this->waitress($id), range(50, 55));
        $problem = $this->problem([$core(1), $core(2), ...array_map($rotation, [11, 12, 13, 14]), ...$women], [
            ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 3),
        ], ['night_plan' => array_fill_keys($days, [11, 12, 13, 14]), 'time_limit' => 90, 'max_iterations' => 120000, ...$options]);

        return [$this->solve($problem), $problem, $days];
    }

    private function lobbyGaps(SolveResult $result): array
    {
        return array_values(array_filter($result->unfilledRequirements, fn ($u) => isset($u['lobby'])));
    }
}
