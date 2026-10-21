<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Quy tắc cấp quản lý (Junior Restaurant Captain trở lên) – DỮ LIỆU GIẢ LẬP:
 * chỉ tự xếp M6 / A2, giữ ở outlet chính, không đi Lobby, cân bằng quản lý ca sáng / chiều theo outlet × ngày,
 * không đổi OFF / ô khóa để cân bằng – lệch không tránh được thì có cảnh báo.
 */
class ManagerRulesTest extends TestCase
{
    use SyntheticRoster;

    private const CAPTAIN = 201;

    /** Quản lý có năng lực ở nhiều nơi (Villa, Lobby, OH) – thuật toán vẫn chỉ được dùng M6 / A2 tại outlet chính. */
    private function manager(int $id, int $home = self::GUSTO, ?string $gender = 'female'): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic manager #{$id}", $gender, $home, 48, 2, [
            "{$home}:0" => 'primary', self::VILLA . ':0' => 'eligible', self::LOBBY . ':0' => 'eligible',
        ], [], 'nha_trang', self::CAPTAIN, isManager: true, managerShiftIds: [self::M6, self::A2]);
    }

    /** @return Requirement[] */
    private function requirements(array $days, int $outlet, int $min, int $target): array
    {
        $rows = [];

        foreach ($days as $day) {
            foreach ([self::M6, self::A2] as $shift) {
                $rows[] = new Requirement($day, $outlet, $shift, $min, $target, $target + 2, positionMins: ['grp:mgr' => 1]);
            }
        }

        return $rows;
    }

    private function options(array $extra = []): array
    {
        return ['time_limit' => 4, 'position_groups' => ['grp:mgr' => ['ids' => [self::CAPTAIN], 'label' => 'Quản lý']], ...$extra];
    }

    public function test_managers_only_work_m6_a2_at_home_outlet_even_when_other_outlet_is_short(): void
    {
        $days = $this->days();
        $managers = array_map(fn ($id) => $this->manager($id), range(21, 24));
        // Villa thiếu nhiều người, có ca OH và khung Lobby cần người: quản lý Gusto vẫn không bị điều sang.
        $requirements = [...$this->requirements($days, self::GUSTO, 1, 2), ...$this->requirements($days, self::VILLA, 3, 5)];

        foreach ($days as $day) {
            $requirements[] = new Requirement($day, self::GUSTO, self::OH, 1, 1);
        }

        $coverage = array_map(fn ($d) => new Coverage($d, 14 * 60, 17 * 60, 1, 2, 'Lobby chiều', [self::A2]), $days);
        $problem = $this->problem([...$managers, ...$this->team(1, 6, self::GUSTO), ...$this->team(11, 2, self::VILLA)], $requirements, $this->options(['coverage' => $coverage]));
        $result = $this->solve($problem);

        foreach ($result->assignments as $row) {
            if ($row['employee_id'] >= 21 && $row['kind'] === 'shift') {
                $this->assertSame(self::GUSTO, $row['outlet_id'], "Quản lý #{$row['employee_id']} {$row['date']} bị điều khỏi outlet chính");
                $this->assertContains($row['shift_id'], [self::M6, self::A2], "Quản lý #{$row['employee_id']} {$row['date']} bị tự xếp ngoài M6 / A2");
                $this->assertFalse($row['support'], 'Quản lý không đi hỗ trợ Lobby');
            }
        }

        $this->assertHardConstraintsHold($problem, $result);
    }

    public function test_managers_are_balanced_between_morning_and_afternoon(): void
    {
        $days = $this->days(14);
        $managers = array_map(fn ($id) => $this->manager($id), range(21, 25));
        $problem = $this->problem([...$managers, ...$this->team(1, 8, self::GUSTO)], $this->requirements($days, self::GUSTO, 2, 4),
            $this->options(['days' => $days, 'time_limit' => 6]));
        $result = $this->solve($problem);
        $split = [];

        foreach ($result->assignments as $row) {
            if ($row['employee_id'] >= 21 && $row['kind'] === 'shift') {
                $split[$row['date']][$row['shift_id']] = ($split[$row['date']][$row['shift_id']] ?? 0) + 1;
            }
        }

        foreach ($split as $date => $counts) {
            $m6 = $counts[self::M6] ?? 0;
            $a2 = $counts[self::A2] ?? 0;
            $this->assertSame(0, ScheduleState::managerPenalty($m6, $a2), "{$date}: quản lý sáng {$m6} / chiều {$a2} không cân bằng");
        }

        $this->assertSame(0, $result->softConstraintScores['objective']['manager_balance']);
        $this->assertSame([], $result->warnings);
    }

    public function test_locked_shifts_and_off_are_kept_and_imbalance_is_reported(): void
    {
        $days = $this->days();
        $managers = array_map(fn ($id) => $this->manager($id), range(21, 25));
        // Ngày đầu: 4 quản lý bị khóa ca M6, 1 quản lý OFF đã cho → lệch 4 / 0 không tránh được.
        $fixed = array_map(fn ($id) => $this->locked($id, $days[0], self::GUSTO, self::M6), range(21, 24));
        $problem = $this->problem([...$managers, ...$this->team(1, 8, self::GUSTO)], $this->requirements($days, self::GUSTO, 2, 4), $this->options([
            'fixed' => $fixed, 'hard' => [25 => [$days[0] => 'OFF quản lý đã cho']],
        ]));
        $result = $this->solve($problem);

        foreach (range(21, 24) as $id) {
            $this->assertSame([self::GUSTO, self::M6], $this->shiftOf($result, $id, $days[0]), 'Ca đã khóa giữ nguyên');
        }

        $this->assertNull($this->shiftOf($result, 25, $days[0]), 'OFF đã cho không bị đổi để cân bằng');

        $warning = array_values(array_filter($result->warnings, fn ($w) => $w['date'] === $days[0]))[0] ?? null;
        $this->assertNotNull($warning, 'Có cảnh báo lệch quản lý ngày đầu');
        $this->assertStringContainsString('sáng 4 / ca chiều 0', $warning['message']);
        $this->assertStringContainsString('đã khóa', $warning['message']);
    }

    public function test_penalty_only_counts_avoidable_imbalance(): void
    {
        $this->assertSame(0, ScheduleState::managerPenalty(2, 2));
        $this->assertSame(0, ScheduleState::managerPenalty(3, 2));
        $this->assertSame(0, ScheduleState::managerPenalty(1, 0));
        $this->assertSame(2, ScheduleState::managerPenalty(4, 1));
        $this->assertSame(2, ScheduleState::managerPenalty(1, 3));
    }

    public function test_non_managers_keep_their_normal_rules(): void
    {
        $waiter = $this->employee(1, self::GUSTO, [self::GUSTO . ':0' => 'primary', self::VILLA . ':0' => 'eligible']);

        $this->assertSame('eligible', $waiter->skillLevel(self::VILLA, self::M6), 'Nhân viên thường vẫn được điều chuyển khi thiếu');
        $this->assertNull($this->manager(21)->skillLevel(self::VILLA, self::M6));
        $this->assertNull($this->manager(21)->skillLevel(self::GUSTO, self::OH));
        $this->assertSame('primary', $this->manager(21)->skillLevel(self::GUSTO, self::A2));
    }
}
