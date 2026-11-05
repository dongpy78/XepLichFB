<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * DỮ LIỆU GIẢ LẬP. Lỗi quản lý: thứ 7 nhiều incharge OFF, chủ nhật 4 Hostess cùng A2.
 * Incharge = cấp quản lý trừ Assistant Manager, Restaurant Manager; cân bằng M6 / A2 và OFF từng nhà hàng; Hostess chia đều M6 / A2.
 */
class InChargeHostessBalanceTest extends TestCase
{
    use SyntheticRoster;

    private function manager(int $id, int $home, bool $top = false): EmployeeSpec
    {
        return new EmployeeSpec($id, "Captain #{$id}", 'female', $home, 48, 2, ["{$home}:0" => 'primary'], [], 'cam_ranh', 200,
            isManager: true, managerShiftIds: [self::M6, self::A2], isMaleStaff: false, isTopManager: $top);
    }

    private function hostess(int $id, int $home): EmployeeSpec
    {
        return new EmployeeSpec($id, "Hostess #{$id}", 'female', $home, 48, 2, ["{$home}:0" => 'primary'], [], 'cam_ranh', 101, isPositionBalanced: true);
    }

    /** @return Requirement[] */
    private function openSlots(array $days, array $outlets): array
    {
        $rows = [];

        foreach ($days as $day) {
            foreach ($outlets as $outlet) {
                foreach ([self::M6, self::A2] as $shift) {
                    $rows[] = new Requirement($day, $outlet, $shift, 0, 0, 20);
                }
            }
        }

        return $rows;
    }

    public function test_top_managers_are_not_counted_in_incharge_balance(): void
    {
        $days = $this->days();
        $state = new ScheduleState($this->problem([$this->manager(1, self::GUSTO, true), $this->manager(2, self::GUSTO, true), $this->manager(3, self::GUSTO), $this->manager(4, self::GUSTO)], $this->openSlots($days, [self::GUSTO])));
        $i = $state->index[$days[5]];

        // Hai incharge cùng M6, Assistant + Restaurant Manager cùng A2: đếm cả bốn thì "2 / 2", thực tế incharge lệch.
        $state->place(3, $i, self::GUSTO, self::M6);
        $state->place(4, $i, self::GUSTO, self::M6);
        $state->place(1, $i, self::GUSTO, self::A2);
        $state->place(2, $i, self::GUSTO, self::A2);
        $this->assertSame(2, $state->objectiveValues()['manager_balance']);

        // Incharge 1 / 1: cân bằng, dù cả hai người cấp trên cùng một ca.
        $state->remove(4, $i);
        $state->place(4, $i, self::GUSTO, self::A2);
        $state->remove(1, $i);
        $state->place(1, $i, self::GUSTO, self::M6);
        $this->assertSame(0, $state->objectiveValues()['manager_balance']);
    }

    /** Cả tuần mỗi nhà hàng dồn OFF một ngày khác nhau: gộp hai nhà hàng thì trông đều, tách từng nhà hàng mới thấy dồn. */
    public function test_incharge_off_spread_is_counted_per_restaurant(): void
    {
        $days = $this->days();
        $gusto = [11, 12, 13, 14];
        $villa = [21, 22, 23, 24];
        $spread = function (array $offs) use ($days, $gusto, $villa): int {
            $state = new ScheduleState($this->problem(array_map(fn ($id) => $this->manager($id, $id < 20 ? self::GUSTO : self::VILLA), [...$gusto, ...$villa]), $this->openSlots($days, [self::GUSTO, self::VILLA])));

            foreach ($days as $d => $day) {
                foreach ([...$gusto, ...$villa] as $id) {
                    if (! in_array($id, $offs[$d] ?? [], true)) {
                        $state->place($id, $state->index[$day], $id < 20 ? self::GUSTO : self::VILLA, self::M6);
                    }
                }
            }

            return $state->managerOffSpread();
        };

        $piled = $spread([1 => $villa, 5 => $gusto]);
        $even = $spread([1 => [21, 22, 11, 12], 5 => [23, 24, 13, 14]]);

        $this->assertSame([32, 16], [$piled, $even]);
    }

    public function test_solver_splits_hostess_and_incharge_each_day_and_spreads_off(): void
    {
        $days = $this->days();
        $hostess = array_map(fn ($id) => $this->hostess($id, self::GUSTO), range(31, 34));
        $managers = array_map(fn ($id) => $this->manager($id, self::GUSTO), range(11, 18));
        $top = [$this->manager(1, self::GUSTO, true), $this->manager(2, self::GUSTO, true)];
        $villa = array_map(fn ($id) => $this->manager($id, self::VILLA), range(21, 22));
        $problem = $this->problem([...$hostess, ...$managers, ...$top, ...$villa], $this->openSlots($days, [self::GUSTO, self::VILLA]), ['time_limit' => 10, 'restaurants' => [self::VILLA, self::GUSTO], 'busy_weekdays' => []]);
        $result = $this->solve($problem);
        $objective = $result->softConstraintScores['objective'];

        $this->assertSame([0, 0], [$objective['position_balance'], $objective['manager_balance']]);

        foreach ($days as $day) {
            $working = fn (array $ids) => array_values(array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift' && in_array($r['employee_id'], $ids, true)));
            $cell = $working(range(31, 34));
            $am = count(array_filter($cell, fn ($r) => $r['shift_id'] === self::M6));
            $this->assertLessThanOrEqual(1, abs($am - (count($cell) - $am)), "{$day}: Hostess M6 {$am} / tổng " . count($cell));
            $this->assertGreaterThanOrEqual(5, count($working(range(11, 18))), "{$day}: 8 incharge, 16 ngày OFF chia 7 ngày → không ngày nào quá 3 người OFF");
        }

        $this->assertHardConstraintsHold($problem, $result);
    }
}
