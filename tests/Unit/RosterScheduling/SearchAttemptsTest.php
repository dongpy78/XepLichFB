<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Nâng cấp tìm lịch tốt nhất (quản lý 02/11/2026) – DỮ LIỆU GIẢ LẬP: nhiều lượt độc lập giữ lịch tốt nhất, dừng sớm khi không còn tốt hơn;
 * chạy tiếp từ phương án có sẵn không bao giờ kém đi; bước sửa ca nặng (A2 nối M6) giữ nguyên số người mỗi ca mỗi ngày.
 */
class SearchAttemptsTest extends TestCase
{
    use SyntheticRoster;

    private function open(array $days): array
    {
        $slots = [];

        foreach ($days as $d) {
            $slots[] = new Requirement($d, self::GUSTO, self::M6, 0, 0, 30, 0, 1, true, [], 0, 1);
            $slots[] = new Requirement($d, self::GUSTO, self::A2, 0, 0, 30, 0, 1, true, [], 0, 1);
        }

        return $slots;
    }

    private function teamProblem(array $options = [])
    {
        return $this->problem($this->team(1, 12, self::GUSTO, 'female'), $this->open($this->days()), $options + ['time_limit' => 1, 'busy_weekdays' => []]);
    }

    private function seedFrom(SolveResult $result): array
    {
        $seed = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && empty($row['locked'])) {
                $seed[$row['employee_id']][$row['date']] = ScheduleState::slotKey((int) $row['outlet_id'], (int) $row['shift_id'], (bool) ($row['support'] ?? false));
            }
        }

        return $seed;
    }

    public function test_one_attempt_without_search_min_seconds(): void
    {
        $result = $this->solve($this->teamProblem(['max_iterations' => 2000]));

        $this->assertSame(1, $result->solverMetadata['attempts']);
        $this->assertCount(1, $result->solverMetadata['attempt_costs']);
    }

    public function test_keeps_best_of_several_attempts(): void
    {
        // Ngưỡng hội tụ âm rất lớn: không dừng sớm, chạy hết thời gian.
        $problem = $this->teamProblem(['time_limit' => 1.5, 'search_min' => 0.4, 'converge_gain' => -1e15]);
        $result = $this->solve($problem);
        $meta = $result->solverMetadata;

        $this->assertGreaterThanOrEqual(2, $meta['attempts']);
        $this->assertEqualsWithDelta(min($meta['attempt_costs']), $meta['final_cost'], 0.001, 'Kết quả là lượt tốt nhất');
        $this->assertLessThan(3.5, $meta['duration_ms'] / 1000, 'Không vượt xa giới hạn');
        $this->assertHardConstraintsHold($problem, $result);
    }

    public function test_stops_early_when_attempts_agree(): void
    {
        // Ngưỡng rất lớn: lượt thứ hai luôn "sát lịch tốt nhất" → dừng sau 2 lượt dù còn nhiều thời gian.
        $result = $this->solve($this->teamProblem(['time_limit' => 30, 'search_min' => 0.3, 'converge_gain' => 1e15]));

        $this->assertSame(2, $result->solverMetadata['attempts']);
        $this->assertLessThan(5, $result->solverMetadata['duration_ms'] / 1000);
    }

    public function test_attempts_stop_at_iteration_budget_and_are_reproducible(): void
    {
        $run = fn () => $this->solve($this->teamProblem(['time_limit' => 60, 'search_min' => 30, 'max_iterations' => 3000, 'converge_gain' => -1e15]));
        [$a, $b] = [$run(), $run()];

        $this->assertSame(1, $a->solverMetadata['attempts'], 'Hết số vòng cho phép thì không chạy thêm lượt');
        $this->assertEquals($a->assignments, $b->assignments, 'Cùng hạt giống, cùng số vòng → cùng lịch');
    }

    public function test_continuing_from_seed_plan_is_never_worse_and_keeps_cells(): void
    {
        $first = $this->solve($this->teamProblem(['max_iterations' => 300]));
        $seed = $this->seedFrom($first);

        // Không có vòng tìm kiếm nào: khôi phục đúng phương án cũ, điểm không tệ hơn (các bước sửa cuối chỉ nhận khi tốt hơn).
        $same = $this->solve($this->teamProblem(['max_iterations' => 1, 'seed_plan' => $seed]));
        $this->assertLessThanOrEqual($first->solverMetadata['final_cost'] + 0.001, $same->solverMetadata['final_cost']);

        $problem = $this->teamProblem(['time_limit' => 1, 'seed_plan' => $seed]);
        $better = $this->solve($problem);
        $this->assertLessThanOrEqual($first->solverMetadata['final_cost'] + 0.001, $better->solverMetadata['final_cost'], 'Chạy tiếp không bao giờ kém đi');
        $this->assertHardConstraintsHold($problem, $better);
    }

    public function test_repair_heavy_removes_a2_then_m6_keeping_daily_counts(): void
    {
        $days = $this->days(5);
        $problem = $this->problem($this->team(1, 2, self::GUSTO, 'female', 0), $this->open($days), ['days' => $days, 'busy_weekdays' => [], 'max_consecutive' => 6]);
        $state = new ScheduleState($problem);
        $a2 = ScheduleState::slotKey(self::GUSTO, self::A2, false);
        $m6 = ScheduleState::slotKey(self::GUSTO, self::M6, false);
        // Người 1: A2 M6 A2 M6 A2 (3 ca nặng... A2 nối M6), người 2 ngược lại.
        $patterns = [1 => [$a2, $m6, $a2, $m6, $a2], 2 => [$m6, $a2, $m6, $a2, $m6]];

        foreach ($patterns as $employee => $slots) {
            foreach ($slots as $k => $slot) {
                [$o, $s] = ScheduleState::parseSlot($slot);
                $state->place($employee, $state->index[$days[$k]], $o, $s);
            }
        }

        $heavy = fn () => $state->metricsFor(1)['heavy'] + $state->metricsFor(2)['heavy'];
        $counts = fn () => array_map(fn ($d) => [$state->count($state->index[$d], self::GUSTO, self::M6), $state->count($state->index[$d], self::GUSTO, self::A2)], $days);
        $before = [$heavy(), $state->cost(), $counts()];
        $this->assertGreaterThan(0, $before[0]);

        $solver = new HeuristicSolver();
        $repaired = (fn () => $this->repairHeavy($state, microtime(true) + 2))->call($solver);

        $this->assertGreaterThan(0, $repaired);
        $this->assertLessThan($before[0], $heavy(), 'Giảm ca nặng');
        $this->assertLessThan($before[1], $state->cost(), 'Điểm tổng giảm');
        $this->assertSame(10, count(array_merge(...array_values(array_map('array_values', $state->plan)))), 'Không mất / thêm ô nào');
    }
}
