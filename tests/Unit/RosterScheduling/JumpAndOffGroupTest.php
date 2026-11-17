<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Nâng cấp 17/11/2026 – DỮ LIỆU GIẢ LẬP: chỉ A2 → M6 và N → M6 là nhảy ca; tự đổi OFF với đồng nghiệp cùng nhà hàng để gỡ nhảy ca;
 * rải OFF theo 4 nhóm (Incharge, Hostess, Waiter nam, Waiter nữ) theo nhà hàng, gộp khi một nhà hàng đóng cả tuần.
 */
class JumpAndOffGroupTest extends TestCase
{
    use SyntheticRoster;

    private function waiterSpec(int $id, int $home, string $gender = 'female', ?int $scope = null, string $role = 'waiter', int $position = self::WAITER): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", $gender, $home, 48, 2, ["{$home}:0" => 'primary'], [], 'cam_ranh', $position,
            offRole: $role, offScopeOutletId: $scope ?? $home);
    }

    /** Chỗ mở mọi ca (0 tối thiểu, sức chứa lớn) tại một outlet. */
    private function open(array $days, int $outlet = self::GUSTO): array
    {
        $rows = [];

        foreach ($days as $d) {
            foreach ([self::M6, self::A2, self::OH, self::M9, self::N] as $shift) {
                $rows[] = new \Botble\RestaurantRoster\Scheduling\Requirement($d, $outlet, $shift, 0, 0, 30);
            }
        }

        return $rows;
    }

    private function place(ScheduleState $state, int $employee, string $date, int $outlet, int $shift): void
    {
        $state->place($employee, $state->index[$date], $outlet, $shift);
    }

    /** Chỉ A2 → M6 và N → M6 bị tính; A2 → OH, A2 → M9, OH → M6, M6 → A2 thì không. */
    public function test_only_two_jump_pairs_are_counted(): void
    {
        $days = $this->days();
        $state = new ScheduleState($this->problem([$this->waiterSpec(1, self::GUSTO)], $this->open($days)));

        foreach ([[0, self::A2], [1, self::OH], [2, self::M6], [3, self::A2], [4, self::M9], [5, self::A2], [6, self::M6]] as [$d, $shift]) {
            $this->place($state, 1, $days[$d], self::GUSTO, $shift);
        }

        $this->assertSame(1, $state->metricsFor(1)['heavy'], 'Chỉ A2 → M6 (thứ Bảy → Chủ nhật)');
        $this->assertFalse($state->isHeavyAt(1, 0), 'A2 → OH không tính');
        $this->assertFalse($state->isHeavyAt(1, 1), 'OH → M6 không tính');
        $this->assertFalse($state->isHeavyAt(1, 3), 'A2 → M9 không tính');
        $this->assertTrue($state->isHeavyAt(1, 5));

        $night = new ScheduleState($this->problem([$this->waiterSpec(2, self::GUSTO)], $this->open($days)));
        $this->place($night, 2, $days[0], self::GUSTO, self::N);
        $this->place($night, 2, $days[1], self::GUSTO, self::M6);
        $this->assertTrue($night->isHeavyAt(2, 0), 'N → M6 được nhận diện (vd ô xếp tay / khóa)');
    }

    /**
     * Ví dụ của quản lý: A làm A2 thứ Hai (ô khóa) rồi M6 thứ Ba. Đồng nghiệp C cùng nhà hàng OFF thứ Ba, làm thứ Tư:
     * A OFF thứ Ba, C nhận M6 thứ Ba; A làm ca của C thứ Tư. Số người mỗi ca giữ nguyên, không ai nhảy ca.
     */
    public function test_jump_is_fixed_by_swapping_off_with_same_restaurant_colleague(): void
    {
        $days = $this->days(3);
        $people = [$this->waiterSpec(1, self::GUSTO), $this->waiterSpec(2, self::GUSTO), $this->waiterSpec(3, self::GUSTO)];
        $problem = $this->problem($people, $this->open($days), ['days' => $days, 'busy_weekdays' => [],
            'fixed' => [new FixedAssignment(1, $days[0], FixedAssignment::KIND_SHIFT, self::GUSTO, self::A2)]]);
        $state = new ScheduleState($problem);
        $this->place($state, 1, $days[1], self::GUSTO, self::M6);
        $this->place($state, 2, $days[1], self::GUSTO, self::M6);
        $this->place($state, 2, $days[2], self::GUSTO, self::A2);
        $this->place($state, 3, $days[0], self::GUSTO, self::M6);
        $this->place($state, 3, $days[2], self::GUSTO, self::M6);
        $counts = fn () => array_map(fn ($d) => [$state->count($state->index[$d], self::GUSTO, self::M6), $state->count($state->index[$d], self::GUSTO, self::A2)], $days);
        $before = $counts();
        $this->assertTrue($state->isHeavyAt(1, 0));

        $repaired = (fn () => $this->repairHeavy($state, microtime(true) + 2))->call(new HeuristicSolver());

        $this->assertGreaterThan(0, $repaired);
        $this->assertFalse($state->isHeavyAt(1, 0), 'Hết nhảy ca');
        $this->assertSame(0, array_sum(array_map(fn ($e) => $state->metricsFor($e)['heavy'], [1, 2, 3])), 'Không tạo nhảy ca mới cho người khác');
        $this->assertSame($before, $counts(), 'Số người mỗi ca mỗi ngày giữ nguyên');
        $this->assertSame([self::GUSTO, self::A2], array_slice($state->shiftAt(1, 0), 0, 2), 'Ô khóa giữ nguyên');
        $this->assertNull($state->shiftAt(1, 1), 'A OFF thứ Ba');
    }

    /** Hai nhà hàng cùng mở: không đổi OFF chéo Villa ↔ Gusto – đồng nghiệp duy nhất phù hợp ở Villa thì không bị đụng tới. */
    public function test_no_cross_restaurant_off_swap(): void
    {
        $days = $this->days(3);
        $skills = [self::GUSTO . ':0' => 'primary', self::VILLA . ':0' => 'eligible'];
        $a = new EmployeeSpec(1, 'A', 'female', self::GUSTO, 48, 2, $skills, [], 'cam_ranh', self::WAITER, offRole: 'waiter', offScopeOutletId: self::GUSTO);
        $villa = new EmployeeSpec(3, 'C', 'female', self::VILLA, 48, 2, [self::VILLA . ':0' => 'primary', self::GUSTO . ':0' => 'eligible'], [], 'cam_ranh', self::WAITER,
            offRole: 'waiter', offScopeOutletId: self::VILLA);
        $problem = $this->problem([$a, $villa], [...$this->open($days), ...$this->open($days, self::VILLA)], ['days' => $days, 'busy_weekdays' => [],
            'fixed' => [new FixedAssignment(1, $days[0], FixedAssignment::KIND_SHIFT, self::GUSTO, self::A2), new FixedAssignment(1, $days[2], FixedAssignment::KIND_OFF, null, null)]]);
        $state = new ScheduleState($problem);
        $this->place($state, 1, $days[1], self::GUSTO, self::M6);
        $this->place($state, 3, $days[0], self::GUSTO, self::M6);
        $this->place($state, 3, $days[2], self::GUSTO, self::A2);
        $before = $state->plan[3];

        (fn () => $this->repairHeavy($state, microtime(true) + 2))->call(new HeuristicSolver());

        $this->assertSame($before, $state->plan[3], 'Không đổi OFF với người Villa');
    }

    /** Không tránh được (A2 và M6 đều đã khóa): lịch giữ nguyên ô khóa, có cảnh báo nêu người và ngày. */
    public function test_unavoidable_jump_is_reported(): void
    {
        $days = $this->days();
        $problem = $this->problem($this->team(1, 4, self::GUSTO), $this->open($days), ['time_limit' => 3, 'max_iterations' => 20000, 'busy_weekdays' => [],
            'fixed' => [new FixedAssignment(1, $days[0], FixedAssignment::KIND_SHIFT, self::GUSTO, self::A2), new FixedAssignment(1, $days[1], FixedAssignment::KIND_SHIFT, self::GUSTO, self::M6)]]);
        $result = $this->solve($problem);
        $jumps = array_values(array_filter($result->warnings, fn ($w) => $w['code'] === 'jump'));

        $this->assertCount(1, $jumps);
        $this->assertStringContainsString('A2 ' . date('d/m', strtotime($days[0])) . ' → M6 ' . date('d/m', strtotime($days[1])), $jumps[0]['message']);
        $this->assertStringContainsString('đã khóa', $jumps[0]['message']);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Nhóm rải OFF: Waiter nam / nữ riêng, Hostess một nhóm, Restaurant / Assistant Manager và người ngoài 4 nhóm không tính; gộp phạm vi khi đóng cả tuần. */
    public function test_off_spread_groups(): void
    {
        $days = $this->days();
        $people = [
            $this->waiterSpec(1, self::GUSTO, 'female'), $this->waiterSpec(2, self::GUSTO, 'female'),
            $this->waiterSpec(3, self::GUSTO, 'male'), $this->waiterSpec(4, self::GUSTO, 'male'),
            $this->waiterSpec(5, self::GUSTO, 'female', null, 'hostess', 101), $this->waiterSpec(6, self::GUSTO, 'male', null, 'hostess', 101),
            $this->waiterSpec(7, self::GUSTO, 'male', null, 'none', 300),
            // Waiter Villa khi Villa đóng cả tuần: tính chung nhóm với Gusto.
            $this->waiterSpec(8, self::VILLA, 'female', self::GUSTO),
        ];
        $state = new ScheduleState($this->problem($people, $this->open($days)));
        $totals = $state->staffTotals();

        $this->assertSame([
            self::GUSTO . '|' . self::WAITER . '|female' => 3,
            self::GUSTO . '|' . self::WAITER . '|male' => 2,
            self::GUSTO . '|101|' => 2,
        ], $totals);
    }

    /** Incharge rải OFF theo phạm vi; Restaurant / Assistant Manager không tính. */
    public function test_incharge_spread_excludes_top_managers(): void
    {
        $days = $this->days();
        $manager = fn (int $id, bool $top) => new EmployeeSpec($id, "M{$id}", 'female', self::GUSTO, 48, 2, [self::GUSTO . ':0' => 'primary'], [], 'cam_ranh', 200,
            isManager: true, managerShiftIds: [self::M6, self::A2], isMaleStaff: false, isTopManager: $top, offRole: $top ? 'none' : 'incharge', offScopeOutletId: self::GUSTO);
        $state = new ScheduleState($this->problem([$manager(1, false), $manager(2, false), $manager(3, true), $manager(4, true)], $this->open($days)));

        // Không ai đi làm: 2 incharge OFF mỗi ngày → 7 × 2² = 28; hai quản lý cấp trên không tính.
        $this->assertSame(28, $state->managerOffSpread());
    }
}
