<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Lobby theo khung: sáng OH(L) / M9, chiều A2(L); mỗi outlet 1 người mỗi khung; luân phiên nhân viên nữ – DỮ LIỆU GIẢ LẬP.
 */
class LobbyRotationTest extends TestCase
{
    use SyntheticRoster;

    private function waiter(int $id, int $outlet, ?string $residence = 'cam_ranh', array $extra = []): \Botble\RestaurantRoster\Scheduling\EmployeeSpec
    {
        return $this->employee($id, $outlet, [
            "{$outlet}:" . self::M6 => 'primary',
            "{$outlet}:" . self::A2 => 'primary',
            self::LOBBY . ':0' => 'eligible',
            ...$extra,
        ], 'female', residence: $residence, position: self::WAITER);
    }

    /**
     * Sáng chỉ tính OH(L) và M9, chiều chỉ tính A2(L): không giao M6(L);
     * mỗi khung 2 người, mỗi outlet 1 người (M9 tính vào outlet chính của nhân viên).
     */
    public function test_morning_by_oh_or_m9_afternoon_by_a2_one_person_per_outlet(): void
    {
        $day = $this->day(0);
        $employees = [
            ...array_map(fn ($k) => $this->waiter($k, self::GUSTO), range(1, 6)),
            ...array_map(fn ($k) => $this->waiter($k, self::VILLA), range(11, 15)),
            $this->waiter(16, self::VILLA, 'nha_trang', [self::VILLA . ':' . self::OH => 'primary']),
        ];

        $result = $this->solve($this->problem($employees, [
            new Requirement($day, self::GUSTO, self::M6, 2, 2, 4),
            new Requirement($day, self::GUSTO, self::A2, 2, 2, 4),
            new Requirement($day, self::VILLA, self::M6, 2, 2, 4),
            new Requirement($day, self::VILLA, self::A2, 2, 2, 4),
            new Requirement($day, self::VILLA, self::OH, 0, 0, 1),
            new Requirement($day, self::LOBBY, self::M9, 0, 0, 2),
        ], ['days' => [$day], 'time_limit' => 2, 'coverage' => [
            new Coverage($day, 9 * 60, 14 * 60, 2, 2, 'Lobby sáng', [self::OH, self::M9]),
            new Coverage($day, 14 * 60, 17 * 60, 2, 2, 'Lobby chiều', [self::A2]),
        ]]));

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([], $result->unfilledRequirements);

        $rows = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift');
        $home = fn (array $r) => $r['employee_id'] < 11 ? self::GUSTO : self::VILLA;
        $morning = array_filter($rows, fn ($r) => $r['shift_id'] === self::M9 || ($r['shift_id'] === self::OH && $r['support']));
        $afternoon = array_filter($rows, fn ($r) => $r['shift_id'] === self::A2 && $r['support']);

        $this->assertEmpty(array_filter($rows, fn ($r) => $r['shift_id'] === self::M6 && $r['support']), 'Không giao M6(L)');
        $this->assertCount(2, $morning);
        $this->assertCount(2, $afternoon);
        $this->assertEqualsCanonicalizing([self::GUSTO, self::VILLA], array_map($home, array_values($morning)), 'Sáng: mỗi outlet 1 người');
        $this->assertEqualsCanonicalizing([self::GUSTO, self::VILLA], array_map($home, array_values($afternoon)), 'Chiều: mỗi outlet 1 người');
    }

    /** Lượt Lobby chia đều trong outlet: không ai đi Lobby suốt trong khi người khác ở nhà hàng suốt. */
    public function test_lobby_turns_rotate_between_female_staff(): void
    {
        $days = $this->days();
        $team = array_map(fn ($k) => $this->waiter($k, self::GUSTO), range(1, 8));
        $coverage = array_map(fn ($d) => new Coverage($d, 14 * 60, 17 * 60, 1, 1, 'Lobby chiều', [self::A2]), $days);
        $requirements = [
            ...array_map(fn ($d) => new Requirement($d, self::GUSTO, self::M6, 2, 2, 3), $days),
            ...array_map(fn ($d) => new Requirement($d, self::GUSTO, self::A2, 2, 2, 3), $days),
        ];

        $result = $this->solve($this->problem($team, $requirements, ['coverage' => $coverage, 'time_limit' => 3]));
        $turns = $this->lobbyTurns($result, range(1, 8));

        $this->assertSame([], $result->unfilledRequirements);
        $this->assertSame(7, array_sum($turns));
        $this->assertLessThanOrEqual(1, max($turns) - min($turns), 'Lượt Lobby lệch tối đa 1 giữa các nhân viên: ' . json_encode($turns));

        // Người đã đi Lobby nhiều ở các tuần trước được để ở nhà hàng tuần này.
        $rotated = $this->solve($this->problem($team, $requirements, ['coverage' => $coverage, 'time_limit' => 3, 'lobby_history' => [1 => 2.0, 2 => 2.0]]));
        $turns = $this->lobbyTurns($rotated, range(1, 8));
        // 7 lượt / 8 người: 6 người chưa đi Lobby mỗi người 1 lượt, lượt thứ 7 cho 1 trong 2 người đã đi nhiều (không ai 2 lượt).
        $this->assertSame([1, 1, 1, 1, 1, 1], array_values(array_slice($turns, 2, null, true)), 'Người chưa đi Lobby các tuần trước được ưu tiên: ' . json_encode($turns));
        $this->assertSame(1, $turns[1] + $turns[2], 'Đã đi Lobby TB 2 lượt/tuần các tuần trước → tuần này chỉ 1 người đi 1 lượt: ' . json_encode($turns));
    }

    /** Lobby sáng chia đều loại ca: mỗi sáng 1 OH(L) (nữ Nha Trang) + 1 M9 (nữ Cam Ranh). */
    public function test_morning_lobby_pairs_oh_l_with_m9(): void
    {
        $days = $this->days();
        $employees = [
            ...array_map(fn ($k) => $this->waiter($k, self::VILLA, 'nha_trang', [self::VILLA . ':' . self::OH => 'primary']), range(1, 3)),
            ...array_map(fn ($k) => $this->waiter($k, self::VILLA), range(11, 16)),
            ...array_map(fn ($k) => $this->waiter($k, self::GUSTO), range(21, 26)),
        ];
        $requirements = [];

        foreach ($days as $day) {
            $requirements[] = new Requirement($day, self::LOBBY, self::M9, 0, 0, 2);
            $requirements[] = new Requirement($day, self::VILLA, self::OH, 0, 0, 0);

            foreach ([self::GUSTO, self::VILLA] as $outlet) {
                // Sức chứa như bản chạy thật (mục tiêu + 2) để ai cũng đủ ngày làm.
                $requirements[] = new Requirement($day, $outlet, self::M6, 1, 2, 4);
                $requirements[] = new Requirement($day, $outlet, self::A2, 1, 2, 4);
            }
        }

        $coverage = array_map(fn ($d) => new Coverage($d, 9 * 60, 14 * 60, 2, 2, 'Lobby sáng', [self::OH, self::M9]), $days);
        // Giới hạn theo số bước (không theo giây) để kết quả giống nhau trên mọi máy, kể cả khi chạy chung toàn bộ test.
        $result = $this->solve($this->problem($employees, $requirements, ['coverage' => $coverage, 'time_limit' => 60, 'max_iterations' => 40000]));
        $mixed = 0;

        foreach ($days as $day) {
            $rows = array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift');
            $oh = count(array_filter($rows, fn ($r) => $r['shift_id'] === self::OH && $r['support']));
            $m9 = count(array_filter($rows, fn ($r) => $r['shift_id'] === self::M9));
            $mixed += $oh === 1 && $m9 === 1 ? 1 : 0;
        }

        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
        // 3 người Nha Trang × 5 ngày làm, vẫn phải xen kẽ ca chiều: phần lớn các sáng là 1 OH(L) + 1 M9.
        $this->assertGreaterThanOrEqual(5, $mixed, 'Số sáng có đúng 1 OH(L) + 1 M9: ' . $mixed);
    }

    /** @return array<int, int> */
    private function lobbyTurns(SolveResult $result, array $employeeIds): array
    {
        $turns = array_fill_keys($employeeIds, 0);

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && ($row['support'] || $row['outlet_id'] === self::LOBBY)) {
                $turns[$row['employee_id']]++;
            }
        }

        return $turns;
    }
}
