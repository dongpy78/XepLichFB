<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Hostess mỗi ca, tối thiểu 1 nam (mượn người outlet NIGHT khi thiếu), luân phiên ca đêm theo tuần – DỮ LIỆU GIẢ LẬP.
 */
class StaffMixAndNightTest extends TestCase
{
    use SyntheticRoster;

    private const HOSTESS = 101;

    /** Mỗi ca M6 / A2 của Gusto và Villa đều có Hostess; Gusto ít Hostess thì mượn Hostess Villa. */
    public function test_every_shift_has_a_hostess(): void
    {
        $days = $this->days();
        $hostess = fn (int $id, int $home, int $other) => $this->employee($id, $home, [
            "{$home}:" . self::M6 => 'primary', "{$home}:" . self::A2 => 'primary',
            "{$other}:" . self::M6 => 'eligible', "{$other}:" . self::A2 => 'eligible',
        ], position: self::HOSTESS);
        $employees = [
            ...$this->team(1, 8, self::GUSTO),
            ...$this->team(11, 8, self::VILLA),
            $hostess(21, self::GUSTO, self::VILLA), $hostess(22, self::GUSTO, self::VILLA),
            ...array_map(fn ($id) => $hostess($id, self::VILLA, self::GUSTO), range(23, 26)),
        ];
        $requirements = [];

        foreach ([self::GUSTO, self::VILLA] as $outlet) {
            foreach ([self::M6, self::A2] as $shift) {
                foreach ($days as $day) {
                    $requirements[] = new Requirement($day, $outlet, $shift, 3, 3, positionMins: [self::HOSTESS => 1]);
                }
            }
        }

        $result = $this->solve($this->problem($employees, $requirements, ['time_limit' => 4]));
        $hostesses = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['employee_id'] >= 21) {
                $hostesses["{$row['date']}|{$row['outlet_id']}|{$row['shift_id']}"] = true;
            }
        }

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertCount(28, $hostesses, 'Thiếu Hostess ở ' . (28 - count($hostesses)) . ' ca');
        $this->assertSame([], array_filter(array_column($result->unfilledRequirements, 'missing_positions')));
        $this->assertHardConstraintsHold($this->problem($employees, $requirements), $result);
    }

    /** Mỗi ca M6 / A2 có ít nhất 1 người quản lý (nhóm nhiều vị trí: Junior Captain trở lên). */
    public function test_every_shift_has_a_manager_from_position_group(): void
    {
        $days = $this->days();
        $captain = fn (int $id, int $position) => $this->employee($id, self::GUSTO, [
            self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary',
        ], position: $position);
        // 3 người thuộc 2 vị trí quản lý khác nhau (Captain 201, Supervisor 202) + 8 người không quản lý.
        $employees = [...$this->team(1, 8, self::GUSTO), $captain(21, 201), $captain(22, 201), $captain(23, 202)];
        $requirements = [];

        foreach ($days as $day) {
            foreach ([self::M6, self::A2] as $shift) {
                $requirements[] = new Requirement($day, self::GUSTO, $shift, 3, 3, positionMins: ['grp:lead' => 1]);
            }
        }

        $result = $this->solve($this->problem($employees, $requirements, [
            'time_limit' => 4, 'position_groups' => ['grp:lead' => ['ids' => [201, 202], 'label' => 'Quản lý']],
        ]));
        $staffing = $this->staffing($result);

        foreach ($days as $day) {
            foreach ([self::M6, self::A2] as $shift) {
                $this->assertNotEmpty(array_intersect($staffing["{$day}|" . self::GUSTO . "|{$shift}"] ?? [], [21, 22, 23]), "{$day} ca {$shift}: có quản lý");
            }
        }

        $this->assertSame([], array_filter(array_column($result->unfilledRequirements, 'missing_positions')));
    }

    /** Nam duy nhất của Gusto xin OFF: đưa nam outlet NIGHT sang để M6 và A2 vẫn có nam. */
    public function test_night_outlet_men_cover_when_restaurant_has_no_man(): void
    {
        $day = $this->day(2);
        $nightMan = fn (int $id) => $this->employee($id, self::NIGHT, [
            self::NIGHT . ':' . self::N => 'primary', self::GUSTO . ':' . self::M6 => 'eligible', self::GUSTO . ':' . self::A2 => 'eligible',
        ], 'male');
        $employees = [
            ...$this->team(1, 4, self::GUSTO),
            $this->employee(5, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary'], 'male'),
            $nightMan(6), $nightMan(7), $nightMan(8),
        ];

        $result = $this->solve($this->problem($employees, [
            new Requirement($day, self::GUSTO, self::M6, 2, 2, null, 1, 1),
            new Requirement($day, self::GUSTO, self::A2, 2, 2, null, 1, 1),
            new Requirement($day, self::NIGHT, self::N, 1, 1),
        ], ['days' => [$day], 'hard' => [5 => [$day => 'Xin OFF']]]));

        $staffing = $this->staffing($result);
        $men = fn (int $shift) => count(array_intersect($staffing["{$day}|" . self::GUSTO . "|{$shift}"] ?? [], [5, 6, 7, 8]));

        $this->assertSame(1, $men(self::M6), 'Gusto M6 có 1 nam từ outlet NIGHT');
        $this->assertSame(1, $men(self::A2), 'Gusto A2 có 1 nam từ outlet NIGHT');
        $this->assertCount(1, $staffing["{$day}|" . self::NIGHT . '|' . self::N] ?? [], 'Ca đêm vẫn đủ người');
        $this->assertSame([], $result->unfilledRequirements);
    }

    /** Ca đêm luân phiên theo tuần giữa các nam waiter; người có ca cứng Night làm đêm như cài đặt. */
    public function test_night_shift_rotates_weekly_between_male_waiters(): void
    {
        $days = $this->days(14);
        $waiter = fn (int $id) => $this->employee($id, self::VILLA, [
            self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible',
        ], 'male');
        $employees = [
            ...array_map($waiter, range(1, 6)),
            ...$this->team(11, 4, self::VILLA),
            // Ca cứng Night: luôn làm đêm, không tham gia luân phiên.
            $this->employee(20, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary'], 'male', fixed: [self::NIGHT . ':' . self::N]),
        ];

        $result = $this->solve($this->problem($employees, [
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 3),
            ...$this->dailyRequirements($days, self::NIGHT, self::N, 2, 2),
        ], ['days' => $days, 'time_limit' => 6, 'lobby_history' => [], 'night_history' => [1 => 4]]));

        $nights = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['shift_id'] === self::N) {
                $week = intdiv(array_search($row['date'], $days, true), 7);
                $nights[$week][$row['employee_id']] = ($nights[$week][$row['employee_id']] ?? 0) + 1;
            }
        }

        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
        $this->assertGreaterThanOrEqual(4, min($nights[0][20] ?? 0, $nights[1][20] ?? 0), 'Ca cứng Night làm đêm cả 2 tuần');
        $this->assertEmpty(array_filter($result->assignments, fn ($r) => $r['employee_id'] === 20 && $r['kind'] === 'shift' && $r['shift_id'] !== self::N));

        $rotating = fn (int $week) => array_keys(array_diff_key($nights[$week], [20 => true]));
        $this->assertLessThanOrEqual(2, count($rotating(0)), 'Tuần 1 dồn ca đêm cho ít người: ' . json_encode($nights[0]));
        $this->assertLessThanOrEqual(2, count($rotating(1)), 'Tuần 2 dồn ca đêm cho ít người: ' . json_encode($nights[1]));
        $this->assertSame([], array_intersect($rotating(0), $rotating(1)), 'Tuần sau đổi người làm đêm: ' . json_encode($nights));
        $this->assertNotContains(1, [...$rotating(0), ...$rotating(1)], 'Người đã làm đêm nhiều tuần trước được nghỉ đêm');
    }
}
