<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Hỗ trợ quầy bar Lobby, ca theo khu vực sinh sống, OFF theo cặp 2 tuần – DỮ LIỆU GIẢ LẬP.
 */
class LobbyAndDaysOffTest extends TestCase
{
    use SyntheticRoster;

    private function lobbyCoverage(array $days, int $am, int $pm, ?int $amMin = null, ?int $pmMin = null): array
    {
        $rows = [];

        foreach ($days as $date) {
            $rows[] = new Coverage($date, 9 * 60, 14 * 60, $amMin ?? $am, $am, 'Lobby sáng');
            $rows[] = new Coverage($date, 14 * 60, 17 * 60, $pmMin ?? $pm, $pm, 'Lobby chiều');
        }

        return $rows;
    }

    /**
     * Người đi hỗ trợ Lobby rời nhà hàng: nhà hàng vẫn phải đủ định biên KHÔNG kể người (L),
     * người (L) là người dư / xếp thêm.
     */
    public function test_lobby_support_comes_on_top_of_restaurant_staffing(): void
    {
        $days = $this->days();
        $team = array_map(fn ($k) => $this->employee($k, self::VILLA, [
            self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::LOBBY . ':0' => 'eligible',
        ]), range(1, 10));

        $result = $this->solve($this->problem($team, [
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 2),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 2),
        ], ['coverage' => $this->lobbyCoverage($days, 2, 1), 'time_limit' => 3]));

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([], $result->unfilledRequirements, 'Nhà hàng và Lobby đều phải đủ người');

        foreach ($days as $date) {
            $rows = array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift');
            $inRestaurant = fn (int $shift) => count(array_filter($rows, fn ($r) => $r['shift_id'] === $shift && ! $r['support']));
            $atLobby = fn (int $shift) => count(array_filter($rows, fn ($r) => $r['shift_id'] === $shift && $r['support']));

            $this->assertGreaterThanOrEqual(2, $inRestaurant(self::M6), "{$date}: nhà hàng M6 vẫn đủ 2 người ngoài người đi Lobby");
            $this->assertGreaterThanOrEqual(2, $inRestaurant(self::A2), "{$date}: nhà hàng A2 vẫn đủ 2 người ngoài người đi Lobby");
            $this->assertSame(2, $atLobby(self::M6), "{$date}: 2 người M6(L) cho Lobby sáng");
            $this->assertSame(1, $atLobby(self::A2), "{$date}: 1 người A2(L) cho Lobby chiều – không giao thừa");
        }
    }

    /** Mỗi outlet góp tối đa 2 người cho Lobby trong một ca; các outlet chia nhau. */
    public function test_each_outlet_contributes_at_most_two_people_to_lobby(): void
    {
        $day = $this->day(0);
        $skills = fn (int $outlet) => [$outlet . ':' . self::M6 => 'primary', self::LOBBY . ':0' => 'eligible'];
        $employees = [
            ...array_map(fn ($k) => $this->employee($k, self::GUSTO, $skills(self::GUSTO)), range(1, 5)),
            ...array_map(fn ($k) => $this->employee($k, self::VILLA, $skills(self::VILLA)), range(11, 15)),
        ];

        $result = $this->solve($this->problem($employees, [
            new Requirement($day, self::GUSTO, self::M6, 2, 2, 5),
            new Requirement($day, self::VILLA, self::M6, 2, 2, 5),
        ], ['days' => [$day], 'coverage' => [new Coverage($day, 9 * 60, 14 * 60, 4, 4, 'Lobby sáng')]]));

        $flags = array_filter($result->assignments, fn ($r) => $r['support']);
        $gusto = count(array_filter($flags, fn ($r) => $r['outlet_id'] === self::GUSTO));
        $villa = count(array_filter($flags, fn ($r) => $r['outlet_id'] === self::VILLA));

        $this->assertSame([2, 2], [$gusto, $villa], 'Lobby cần 4 người: mỗi outlet góp 2, không dồn 1 outlet');
        $this->assertSame([], $result->unfilledRequirements);

        // Chỉ một outlet: tối đa 2 người, phần còn lại báo thiếu thay vì rút hết người nhà hàng.
        $single = $this->solve($this->problem(array_slice($employees, 0, 5), [new Requirement($day, self::GUSTO, self::M6, 2, 2, 5)],
            ['days' => [$day], 'coverage' => [new Coverage($day, 9 * 60, 14 * 60, 4, 4, 'Lobby sáng')]]));
        $this->assertCount(2, array_filter($single->assignments, fn ($r) => $r['support']));
        $this->assertNotEmpty(array_filter($single->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::LOBBY));
    }

    /** Người chưa có năng lực Lobby không bao giờ bị giao (L); thiếu thì báo thiếu kèm lý do. */
    public function test_support_requires_lobby_skill(): void
    {
        $day = $this->day(0);
        $result = $this->solve($this->problem(
            [$this->employee(1, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary'])],
            [new Requirement($day, self::VILLA, self::M6, 1, 1)],
            ['days' => [$day], 'coverage' => [new Coverage($day, 9 * 60, 14 * 60, 1, 1, 'Lobby sáng')]]
        ));

        $this->assertFalse($result->assignments[0]['support']);
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::LOBBY))[0] ?? null;
        $this->assertNotNull($gap);
        $this->assertSame('min', $gap['level']);
    }

    /** M9 làm trọn ở Lobby: chỉ nhân viên nữ, vị trí Waiter, ở Cam Ranh. */
    public function test_m9_only_for_female_waiters_living_in_cam_ranh(): void
    {
        $day = $this->day(0);
        $m9 = [self::LOBBY . ':' . self::M9 => 'primary'];
        $employees = [
            $this->employee(1, self::VILLA, $m9, 'male', residence: 'cam_ranh', position: self::WAITER),
            $this->employee(2, self::VILLA, $m9, 'female', residence: 'nha_trang', position: self::WAITER),
            $this->employee(3, self::VILLA, $m9, 'female', residence: 'cam_ranh', position: 200),
            $this->employee(4, self::VILLA, $m9, 'female', residence: 'cam_ranh', position: self::WAITER),
        ];

        $result = $this->solve($this->problem($employees, [
            // Ô M9 tại Lobby: sức chứa 1, chỉ phục vụ phủ Lobby (không tự tạo thiếu người).
            new Requirement($day, self::LOBBY, self::M9, 0, 0, 1),
        ], ['days' => [$day], 'coverage' => [new Coverage($day, 9 * 60, 14 * 60, 1, 1, 'Lobby sáng')]]));

        $this->assertNull($this->shiftOf($result, 1, $day), 'Nam không được xếp M9');
        $this->assertNull($this->shiftOf($result, 2, $day), 'Người ở Nha Trang không được xếp M9');
        $this->assertNull($this->shiftOf($result, 3, $day), 'Không phải Waiter không được xếp M9');
        $this->assertSame([self::LOBBY, self::M9], $this->shiftOf($result, 4, $day));
        $this->assertSame([], $result->unfilledRequirements);
    }

    /** OH làm ở nhà hàng (nhân viên Nha Trang); OH(L) = OH có hỗ trợ Lobby cả ca. */
    public function test_oh_is_a_restaurant_shift_and_oh_l_supports_lobby(): void
    {
        $day = $this->day(0);
        $skills = [self::VILLA . ':' . self::OH => 'primary', self::LOBBY . ':0' => 'eligible'];
        $employees = [
            $this->employee(1, self::VILLA, $skills, 'female', residence: 'cam_ranh'),
            $this->employee(2, self::VILLA, $skills, 'female', residence: 'nha_trang'),
        ];

        $result = $this->solve($this->problem($employees, [new Requirement($day, self::VILLA, self::OH, 0, 0, 1)], [
            'days' => [$day],
            // Khung chính Lobby kết thúc 16:45 (16:45–17:00 không bắt buộc).
            'coverage' => [new Coverage($day, 9 * 60, 14 * 60, 1, 1, 'Lobby sáng'), new Coverage($day, 14 * 60, 16 * 60 + 45, 1, 1, 'Lobby chiều')],
        ]));

        $this->assertNull($this->shiftOf($result, 1, $day), 'OH chỉ cho nhân viên ở Nha Trang');
        $this->assertSame([self::VILLA, self::OH], $this->shiftOf($result, 2, $day), 'OH tính cho nhà hàng, không phải Lobby');
        $row = array_values(array_filter($result->assignments, fn ($r) => $r['employee_id'] === 2))[0];
        $this->assertTrue($row['support'], 'Thiếu Lobby → giao OH(L)');
        $this->assertSame([], $result->unfilledRequirements, 'OH(L) 08:45–16:45 phủ cả khung sáng và chiều');
    }

    /** Hỗ trợ Lobby (L) chỉ giao cho nhân viên nữ, dù nam có năng lực Lobby. */
    public function test_support_flag_only_for_female_staff(): void
    {
        $day = $this->day(0);
        $skills = [self::GUSTO . ':' . self::M6 => 'primary', self::LOBBY . ':0' => 'eligible'];

        $result = $this->solve($this->problem(
            [$this->employee(1, self::GUSTO, $skills, 'male')],
            [new Requirement($day, self::GUSTO, self::M6, 1, 1)],
            ['days' => [$day], 'coverage' => [new Coverage($day, 9 * 60, 14 * 60, 1, 1, 'Lobby sáng')]]
        ));

        $this->assertSame([self::GUSTO, self::M6], $this->shiftOf($result, 1, $day));
        $this->assertFalse($result->assignments[0]['support'], 'Nam không được giao (L)');
        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::LOBBY));
    }

    /** OFF theo cặp 2 tuần: tuần có đợt đi chơi, người ở lại làm 6 ngày để đủ tối thiểu, tuần sau được bù. */
    public function test_fortnight_mode_allows_six_day_week_only_to_meet_minimum(): void
    {
        $days = $this->days(14);
        $trip = [];

        // 3/7 người đi chơi T4–T6 tuần 1 (đã duyệt).
        foreach ([1, 2, 3] as $id) {
            foreach ([2, 3, 4] as $d) {
                $trip[$id][$this->day($d)] = 'Đi chơi đợt 1 (giả lập)';
            }
        }

        // Nhu cầu tuần: T2 6 người, T4–T6 4 người, ngày khác 5 người = 33 ca.
        // Tuần đi chơi: 3 người đi chơi chỉ làm được 4 ngày (12) + 4 người × 5 ngày (20) = 32 < 33.
        $requirements = array_map(function (string $date) {
            $weekday = (int) date('N', strtotime($date));
            $need = match (true) {
                $weekday === 1 => 6,
                in_array($weekday, [3, 4, 5], true) => 4,
                default => 5,
            };

            return new Requirement($date, self::GUSTO, self::M6, $need, $need);
        }, $days);
        $team = $this->team(1, 7, self::GUSTO);

        $weekly = $this->solve($this->problem($team, $requirements, ['days' => $days, 'hard' => $trip, 'days_off_mode' => 'weekly', 'time_limit' => 4]));
        $fortnight = $this->solve($this->problem($team, $requirements, ['days' => $days, 'hard' => $trip, 'days_off_mode' => 'fortnight', 'time_limit' => 4]));

        $minGaps = fn ($r) => count(array_filter($r->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
        $this->assertGreaterThan(0, $minGaps($weekly), 'Chế độ từng tuần: không đủ người trong tuần đi chơi');
        $this->assertSame(0, $minGaps($fortnight), 'Chế độ cặp 2 tuần: người ở lại làm thêm 1 ngày để đủ tối thiểu');
        $this->assertSame(SolveResult::FEASIBLE, $fortnight->status);

        foreach ($fortnight->employeeWorkload as $row) {
            $this->assertLessThanOrEqual(10, $row['worked_days'], "#{$row['employee_id']}: vẫn đủ 4 ngày OFF trong 2 tuần");
        }

        $this->assertHardConstraintsHoldFortnight($fortnight);
    }

    /** Không dùng ngày làm thứ 6 chỉ để đạt định biên mục tiêu (không bắt buộc). */
    public function test_fortnight_mode_does_not_overwork_for_target_only(): void
    {
        $days = $this->days(14);
        $result = $this->solve($this->problem(
            $this->team(1, 5, self::GUSTO),
            $this->dailyRequirements($days, self::GUSTO, self::M6, 2, 5),
            ['days' => $days, 'days_off_mode' => 'fortnight', 'time_limit' => 3]
        ));

        $this->assertSame(0, $result->softConstraintScores['objective']['over_norm']);

        foreach ($result->employeeWorkload as $row) {
            $this->assertSame(0, $row['over_norm'], "#{$row['employee_id']} làm quá 5 ngày/tuần chỉ để đạt mục tiêu");
        }
    }

    private function assertHardConstraintsHoldFortnight($result): void
    {
        $weeks = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift') {
                $week = intdiv((int) ((strtotime($row['date']) - strtotime(self::MONDAY)) / 86400), 7);
                $weeks[$row['employee_id']][$week] = ($weeks[$row['employee_id']][$week] ?? 0) + 1;
            }
        }

        foreach ($weeks as $employeeId => $counts) {
            foreach ($counts as $week => $count) {
                $this->assertLessThanOrEqual(6, $count, "#{$employeeId} tuần {$week}: mỗi tuần phải có ít nhất 1 ngày OFF");
            }
        }
    }
}
