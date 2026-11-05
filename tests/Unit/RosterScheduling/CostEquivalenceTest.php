<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Tối ưu hiệu năng (01/11/2026) không được đổi kết quả chấm điểm – DỮ LIỆU GIẢ LẬP: sau mỗi bước thay đổi ngẫu nhiên của bộ giải,
 * cách tính mới (một lượt, tăng dần) phải bằng đúng cách tính cũ (duyệt lại toàn bộ): cân bằng nữ / nam / Waiter,
 * OFF rải đều, cân bằng M6 / A2 theo nhà hàng gốc (có ngày Villa đóng cửa).
 */
class CostEquivalenceTest extends TestCase
{
    use SyntheticRoster;

    private function state(): ScheduleState
    {
        $people = [];

        foreach ([self::VILLA => range(1, 12), self::GUSTO => range(21, 30)] as $home => $ids) {
            $other = $home === self::VILLA ? self::GUSTO : self::VILLA;

            foreach ($ids as $id) {
                $gender = $id % 3 ? 'female' : 'male';
                $people[] = new EmployeeSpec($id, "Waiter #{$id}", $gender, $home, 48, 2, [$home . ':0' => 'primary', $other . ':0' => 'eligible'], [], 'cam_ranh', self::WAITER,
                    isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male');
            }
        }

        $slots = [];

        foreach ($this->days() as $d) {
            foreach ([self::VILLA, self::GUSTO] as $o) {
                $slots[] = new Requirement($d, $o, self::M6, 0, 0, 30, 0, 1, true, [], 0, 1);
                $slots[] = new Requirement($d, $o, self::A2, 0, 0, 30, 0, 1, true, [], 0, 1);
            }
        }

        $closed = [self::VILLA => [$this->day(6) => true]];
        $problem = $this->problem($people, $slots, ['restaurants' => [self::VILLA, self::GUSTO], 'closed_days' => $closed, 'busy_weekdays' => [], 'time_limit' => 5]);
        $state = new ScheduleState($problem);
        $solver = new HeuristicSolver();
        (fn () => $this->warmStart($state))->call($solver);
        (fn () => $this->construct($state, microtime(true) + 5))->call($solver);

        return $state;
    }

    public function test_single_pass_gender_balances_equal_legacy_three_passes(): void
    {
        $state = $this->state();
        $solver = new HeuristicSolver();
        $ids = array_keys($state->problem->employees);
        $scope = $state->scopeIndexes();
        $legacy = fn () => [
            $this->genderBalance(fn ($i, $o, $s) => $this->femaleCount($i, $o, $s)),
            $this->genderBalance(fn ($i, $o, $s) => $this->maleCount($i, $o, $s)),
            $this->genderBalance(fn ($i, $o, $s) => $this->waiterCount($i, $o, $s)),
            $this->genderBalance(fn ($i, $o, $s) => $this->allMaleCount["{$i}|{$o}|{$s}"] ?? 0),
            array_sum(array_map(fn ($i) => $this->flexDayPenalty($i), array_keys($this->balanceKeys ?? []))),
        ];
        mt_srand(7);
        $checked = 0;

        for ($k = 0; $k < 3000; $k++) {
            $undo = (fn () => $this->randomMove($state, $ids, $scope))->call($solver);

            if ($undo && $k % 3 === 0) {
                $undo();
            }

            $this->assertSame($legacy->call($state), $state->genderBalances(), "Bước {$k}");
            $this->assertSame((fn () => $this->offSpreadFull())->call($state), $state->offSpread(), "OFF rải đều – bước {$k}");
            $this->assertSame((fn () => $this->homeBalanceFull())->call($state), $state->homeBalance(), "Cân bằng nhà hàng gốc – bước {$k}");
            $checked++;
        }

        $this->assertSame(3000, $checked);
        $this->assertNotSame([0, 0, 0, 0, 0], $legacy->call($state), 'Dữ liệu có độ lệch để so sánh thật sự');
        $this->assertGreaterThan(0, $state->offSpread(), 'OFF rải đều có giá trị để so sánh');
    }
}
