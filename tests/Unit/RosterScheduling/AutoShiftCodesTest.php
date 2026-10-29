<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Danh sách ca được tự xếp (quản lý 24/10/2026) và giới hạn người luân phiên Night mỗi ngày – DỮ LIỆU GIẢ LẬP.
 * Lỗi thực tế: chỗ "OH đi Lobby" ở nhà hàng (ProblemBuilder::supportSlots) bị dùng để xếp OH thường (không (L)) cho đủ ngày công.
 */
class AutoShiftCodesTest extends TestCase
{
    use SyntheticRoster;

    private const ALLOWED = ['M6', 'A2', 'N', 'OH(L)', 'M9(L)', 'M6(L)', 'A2(L)', 'M6(K)', 'A2(K)'];

    private function waitress(int $id, string $residence = 'nha_trang'): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", 'female', self::VILLA, 48, 2,
            [self::VILLA . ':0' => 'primary', self::LOBBY . ':0' => 'eligible'], [], $residence, self::WAITER, isFemaleStaff: true);
    }

    /**
     * Villa thiếu chỗ M6 / A2 (người dư cần ngày công) nhưng có chỗ OH sức chứa 2 (như chỗ OH(L) thật) và Lobby cần 1 người.
     *
     * @return array{0: \Botble\RestaurantRoster\Scheduling\SolveResult, 1: \Botble\RestaurantRoster\Scheduling\Problem}
     */
    private function week(?array $allowed): array
    {
        $days = $this->days();
        $problem = $this->problem(array_map(fn ($id) => $this->waitress($id), range(1, 8)), [
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 2),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 2),
            ...array_map(fn ($d) => new Requirement($d, self::VILLA, self::OH, 0, 0, 2), $days),
        ], ['coverage' => array_map(fn ($d) => new Coverage($d, 540, 1005, 1, 1, 'Lobby', null, true), $days),
            'auto_shift_codes' => $allowed, 'time_limit' => 60, 'max_iterations' => 40000]);

        return [$this->solve($problem), $problem];
    }

    /** Thuật toán không bao giờ tự xếp "OH" (OH ở nhà hàng); "OH(L)" vẫn được xếp khi Lobby cần; M6 / A2 bình thường. */
    public function test_auto_scheduling_never_creates_plain_oh(): void
    {
        [$result, $problem] = $this->week(self::ALLOWED);

        $plainOh = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['shift_id'] === self::OH && ! $r['support']);
        $this->assertSame([], array_values($plainOh), 'Không có ca OH thường');
        $this->assertNotEmpty(array_filter($result->assignments, fn ($r) => $r['shift_id'] === self::OH && $r['support']), 'OH(L) vẫn được xếp');
        $this->assertNotEmpty(array_filter($result->assignments, fn ($r) => in_array($r['shift_id'], [self::M6, self::A2], true)));
        $this->assertSame([], $result->hardConstraintViolations);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Lỗi gốc: không có danh sách ca được phép thì người dư bị xếp OH thường cho đủ ngày công. */
    public function test_without_allowlist_plain_oh_was_produced(): void
    {
        [$result] = $this->week(null);

        $this->assertNotEmpty(array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['shift_id'] === self::OH && ! $r['support']),
            'Tái hiện lỗi cũ: chỗ OH(L) bị dùng làm OH thường');
    }

    /** "OH" và "OH(L)" là hai mã khác nhau khi kiểm tra; M9 ở Lobby là "M9(L)"; Kid-Deline là "(K)". */
    public function test_shift_keys_distinguish_oh_and_oh_l(): void
    {
        $state = new ScheduleState($this->problem([$this->waitress(1)], [], ['days' => [$this->day(0)], 'auto_shift_codes' => self::ALLOWED, 'outlet_parents' => [self::KID => self::GUSTO]]));

        $this->assertSame(['OH', 'OH(L)', 'M9(L)', 'M6(K)', 'N'], [
            $state->shiftKey(self::VILLA, self::OH, false), $state->shiftKey(self::VILLA, self::OH, true), $state->shiftKey(self::LOBBY, self::M9, false),
            $state->shiftKey(self::KID, self::M6, false), $state->shiftKey(self::NIGHT, self::N, false),
        ]);
        $this->assertSame('not_allowed', $state->violation(1, 0, self::VILLA, self::OH)[0] ?? null);
        $this->assertNull($state->violation(1, 0, self::VILLA, self::OH, checkEligibility: false), 'Ô quản lý xếp tay không bị lọc');
    }

    /** Hai Night cứng cùng nghỉ một ngày: không lấp bằng 3 người luân phiên (tối đa 2), báo thiếu Night cứng. */
    public function test_day_without_core_is_not_filled_with_three_rotation_staff(): void
    {
        $days = $this->days();
        $day = $days[2];
        $core = fn (int $id) => new EmployeeSpec($id, "Synthetic #{$id}", 'male', self::NIGHT, 48, 2, [self::NIGHT . ':' . self::N => 'primary'], [self::NIGHT . ':' . self::N],
            'cam_ranh', self::WAITER, isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_CORE);
        $rotation = fn (int $id) => new EmployeeSpec($id, "Synthetic #{$id}", 'male', self::VILLA, 48, 2,
            [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible'], [], 'cam_ranh', self::WAITER,
            isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_ROTATION);
        $ids = [11, 12, 13, 14, 15, 16];
        $result = $this->solve($this->problem([$core(1), $core(2), ...array_map($rotation, $ids)], [
            ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 0, 2),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 0, 2),
        ], ['night_plan' => array_fill_keys($days, $ids), 'hard' => [1 => [$day => 'Nghỉ phép'], 2 => [$day => 'Nghỉ phép']],
            'auto_shift_codes' => self::ALLOWED, 'time_limit' => 60, 'max_iterations' => 60000]));

        $night = array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['shift_id'] === self::N);
        $this->assertLessThanOrEqual(2, count($night), 'Không xếp 3 người luân phiên thay Night cứng');
        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $day && $u['shift_code'] === 'Night cố định'), 'Báo không có Night cứng');

        foreach (array_diff($days, [$day]) as $date) {
            $ids3 = array_column(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['shift_id'] === self::N), 'employee_id');
            $this->assertNotEmpty(array_intersect($ids3, [1, 2]), "{$date}: có Night cứng");
            $this->assertLessThanOrEqual(2, count(array_diff($ids3, [1, 2])), "{$date}: tối đa 2 người luân phiên");
        }
    }
}
