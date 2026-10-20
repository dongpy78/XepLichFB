<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * OFF 2 ngày liền nhau (trừ khi nhân viên tự xin OFF lẻ) và hạn chế OFF thứ Bảy – DỮ LIỆU GIẢ LẬP.
 */
class OffRulesTest extends TestCase
{
    use SyntheticRoster;

    /** @return array<int, string> employeeId => chuỗi 7 ngày, "O" = OFF */
    private function offPattern(SolveResult $result): array
    {
        $cells = [];

        foreach ($result->assignments as $row) {
            $cells[$row['employee_id']][$row['date']] = $row['kind'] === 'shift' ? '.' : 'O';
        }

        return array_map(function (array $days) {
            ksort($days);

            return implode('', $days);
        }, $cells);
    }

    /** Người OFF 2 ngày/tuần: 2 ngày OFF luôn liền nhau. */
    public function test_two_days_off_are_always_consecutive(): void
    {
        $days = $this->days();
        $result = $this->solve($this->problem($this->team(1, 8, self::GUSTO), [
            ...$this->flexRequirements($days, self::GUSTO, self::M6, 2, 3),
            ...$this->flexRequirements($days, self::GUSTO, self::A2, 2, 3),
        ], ['time_limit' => 10, 'max_iterations' => 600000]));

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame(0, $result->softConstraintScores['objective']['off_split']);

        foreach ($this->offPattern($result) as $employeeId => $pattern) {
            $this->assertSame(2, substr_count($pattern, 'O'), "#{$employeeId}: {$pattern}");
            $this->assertStringContainsString('OO', $pattern, "#{$employeeId} có OFF lẻ: {$pattern}");
        }
    }

    /** Bắt buộc đúng số ngày OFF cấu hình mỗi tuần (1 hoặc 2 ngày), không OFF dư dù ca đã đủ mục tiêu. */
    public function test_days_off_match_each_employee_config(): void
    {
        $days = $this->days(14);
        $team = [...$this->team(1, 6, self::GUSTO), ...$this->team(7, 3, self::GUSTO, daysOff: 1)];
        $result = $this->solve($this->problem($team, [
            ...$this->flexRequirements($days, self::GUSTO, self::M6, 2, 3),
            ...$this->flexRequirements($days, self::GUSTO, self::A2, 2, 3),
        ], ['days' => $days, 'time_limit' => 6]));

        $this->assertSame(0, $result->softConstraintScores['objective']['under_norm']);

        foreach ($this->offPattern($result) as $employeeId => $pattern) {
            $expected = $employeeId >= 7 ? 1 : 2;

            foreach (str_split($pattern, 7) as $week => $weekDays) {
                $this->assertSame($expected, substr_count($weekDays, 'O'), "#{$employeeId} tuần {$week}: {$weekDays}");
            }
        }
    }

    /** OFF chỉ ghép cặp trong cùng tuần: không có tuần OFF thứ Hai + Chủ nhật (kể cả khi xếp 2 tuần liền). */
    public function test_no_week_with_monday_and_sunday_off(): void
    {
        $days = $this->days(14);
        $result = $this->solve($this->problem($this->team(1, 8, self::GUSTO), [
            ...$this->flexRequirements($days, self::GUSTO, self::M6, 2, 3),
            ...$this->flexRequirements($days, self::GUSTO, self::A2, 2, 3),
        ], ['days' => $days, 'time_limit' => 6]));

        foreach ($this->offPattern($result) as $employeeId => $pattern) {
            foreach (str_split($pattern, 7) as $week => $days) {
                $this->assertDoesNotMatchRegularExpression('/^O\.+O$/', $days, "#{$employeeId} tuần {$week}: OFF thứ Hai + Chủ nhật");
                $this->assertDoesNotMatchRegularExpression('/(^|\.)O(\.|$)/', $days, "#{$employeeId} tuần {$week}: OFF lẻ {$days}");
            }
        }
    }

    /** Nhân viên tự xin OFF lẻ cách nhau (đã duyệt): giữ đúng ngày xin, không bị tính là vi phạm. */
    public function test_requested_split_days_off_are_respected(): void
    {
        $days = $this->days();
        $result = $this->solve($this->problem($this->team(1, 8, self::GUSTO), [
            ...$this->flexRequirements($days, self::GUSTO, self::M6, 2, 3),
            ...$this->flexRequirements($days, self::GUSTO, self::A2, 2, 3),
        ], ['time_limit' => 10, 'max_iterations' => 600000, 'hard' => [1 => [$this->day(1) => 'Xin OFF', $this->day(3) => 'Xin OFF']]]));

        $pattern = $this->offPattern($result)[1];
        $this->assertSame(['O', 'O'], [$pattern[1], $pattern[3]], "Giữ đúng 2 ngày xin OFF lẻ: {$pattern}");
        $this->assertLessThanOrEqual(3, substr_count($pattern, 'O'), "Không dồn thêm OFF: {$pattern}");
        $this->assertSame(0, $result->softConstraintScores['objective']['off_split']);
    }

    /** Thứ Bảy đông khách: chỉ người thật sự không xếp được mới OFF thứ Bảy. */
    public function test_saturday_off_is_limited(): void
    {
        $days = $this->days();
        $result = $this->solve($this->problem($this->team(1, 8, self::GUSTO), [
            ...array_map(fn ($d) => new Requirement($d, self::GUSTO, self::M6, 2, 2, 3), $days),
            ...array_map(fn ($d) => new Requirement($d, self::GUSTO, self::A2, 2, 2, 3), $days),
        ], ['time_limit' => 10, 'max_iterations' => 600000]));

        $saturday = count(array_filter($this->offPattern($result), fn ($pattern) => $pattern[5] === 'O'));

        // 8 người, thứ Bảy nhận tối đa 6 → ít nhất 2 người OFF; không hơn.
        $this->assertSame(2, $saturday, json_encode($this->offPattern($result)));
        $this->assertSame(0, $result->softConstraintScores['objective']['off_split']);
    }
}
