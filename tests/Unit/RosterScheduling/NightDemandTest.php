<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Nhu cầu Night theo ngày: 2 (ít khách) hoặc 3 (đông khách) – DỮ LIỆU GIẢ LẬP. Thiếu người thì báo rõ, không tự hạ nhu cầu.
 */
class NightDemandTest extends TestCase
{
    use SyntheticRoster;

    private function person(int $id, int $home, array $skills, array $fixed = [], ?string $role = null): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", 'male', $home, 48, 2, $skills, $fixed, 'cam_ranh', self::WAITER, isMaleStaff: true, nightRole: $role);
    }

    private function core(int $id): EmployeeSpec
    {
        return $this->person($id, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary'], [self::NIGHT . ':' . self::N], EmployeeSpec::NIGHT_CORE);
    }

    private function rotation(int $id): EmployeeSpec
    {
        return $this->person($id, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible'],
            role: EmployeeSpec::NIGHT_ROTATION);
    }

    private function nights(SolveResult $result, string $date): int
    {
        return count(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['shift_id'] === self::N));
    }

    /** Ngày ít khách 2, đông khách 3: xếp đúng số người mỗi ngày, mỗi ngày có Night cứng. */
    public function test_quiet_two_busy_three(): void
    {
        $days = $this->days();
        $need = [2, 2, 2, 3, 3, 3, 2];
        $rotation = [11, 12, 13, 14];
        $requirements = array_map(fn ($k) => new \Botble\RestaurantRoster\Scheduling\Requirement($days[$k], self::NIGHT, self::N, $need[$k], $need[$k], $need[$k]), array_keys($days));
        $villa = array_map(fn ($id) => $this->person($id, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary']), range(50, 55));
        $problem = $this->problem([$this->core(1), $this->core(2), ...array_map(fn ($id) => $this->rotation($id), $rotation), ...$villa], [
            ...$requirements, ...$this->dailyRequirements($days, self::VILLA, self::M6, 1, 3), ...$this->dailyRequirements($days, self::VILLA, self::A2, 1, 3),
        ], ['night_plan' => array_fill_keys($days, $rotation), 'time_limit' => 90, 'max_iterations' => 150000]);
        $result = $this->solve($problem);

        foreach ($days as $k => $date) {
            $this->assertSame($need[$k], $this->nights($result, $date), "{$date}: Night đúng {$need[$k]} người");
            $ids = array_column(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['shift_id'] === self::N), 'employee_id');
            $this->assertNotEmpty(array_intersect($ids, [1, 2]), "{$date}: có Night cứng");
        }

        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Ngày cần 3 nhưng chỉ có 2 người đủ điều kiện: không hạ nhu cầu – báo thiếu 1, lịch "còn thiếu người", bảng kiểm tra ngày đỏ. */
    public function test_short_night_is_reported_not_lowered(): void
    {
        $day = $this->day(2);
        $requirements = [new \Botble\RestaurantRoster\Scheduling\Requirement($day, self::NIGHT, self::N, 3, 3, 3)];
        $people = [$this->person(1, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary']), $this->person(2, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary'])];
        $result = $this->solve($this->problem($people, $requirements, ['days' => [$day], 'time_limit' => 10, 'max_iterations' => 5000]));

        $this->assertSame(2, $this->nights($result, $day));
        // Chỉ có 2 người đủ điều kiện → chứng minh được không thể đủ 3: "không đủ tối thiểu" (không báo thành công).
        $this->assertSame(SolveResult::INFEASIBLE, $result->status, 'Không báo thành công khi Night thiếu người');
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $day && $u['shift_id'] === self::N))[0] ?? null;
        $this->assertNotNull($gap);
        $this->assertSame([3, 2, 1], [$gap['min'], $gap['assigned'], $gap['missing']], 'Cần 3, có 2, thiếu 1');

        $check = collect($result->supportReport['day_checks'])->firstWhere('date', $day);
        $this->assertFalse($check['checks']['night']['ok']);
        $this->assertStringContainsString('2 / 3', $check['checks']['night']['text']);
    }
}
