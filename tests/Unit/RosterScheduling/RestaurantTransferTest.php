<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\Rules;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Lỗi Chủ nhật (quản lý 30/10/2026) – DỮ LIỆU GIẢ LẬP: nhân viên Villa bị xếp M6(G) / A2(G); nhân viên Gusto dồn hết vào M6.
 * Quy tắc: Villa ↔ Gusto chỉ tự điều chuyển vào ngày nhà hàng gốc đóng cửa; mỗi nhà hàng (theo outlet chính) cân bằng M6 / A2.
 */
class RestaurantTransferTest extends TestCase
{
    use SyntheticRoster;

    /** Waiter có năng lực outlet chính + được điều chuyển sang nhà hàng kia (như ProblemBuilder, chế độ "auto"). */
    private function waiter(int $id, int $home, string $gender): EmployeeSpec
    {
        $other = $home === self::VILLA ? self::GUSTO : self::VILLA;

        return new EmployeeSpec($id, "Waiter #{$id}", $gender, $home, 48, 2, [$home . ':0' => 'primary', $other . ':0' => 'eligible'], [], 'cam_ranh', self::WAITER,
            isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male');
    }

    /** Chỗ mở M6 / A2 của Villa và Gusto mỗi ngày (balance: không định biên), mục tiêu ≥ 1 nam, ≥ 1 nữ mỗi ca; bỏ ngày đóng cửa. */
    private function open(array $days, array $closed = []): array
    {
        $slots = [];

        foreach ($days as $d) {
            foreach ([self::VILLA, self::GUSTO] as $o) {
                if (isset($closed[$o][$d])) {
                    continue;
                }

                $slots[] = new Requirement($d, $o, self::M6, 0, 0, 30, 0, 1, true, [], 0, 1);
                $slots[] = new Requirement($d, $o, self::A2, 0, 0, 30, 0, 1, true, [], 0, 1);
            }
        }

        return $slots;
    }

    private function team(): array
    {
        return [
            ...array_map(fn ($id) => $this->waiter($id, self::VILLA, $id % 2 ? 'female' : 'male'), range(1, 10)),
            ...array_map(fn ($id) => $this->waiter($id, self::GUSTO, $id % 2 ? 'female' : 'male'), range(21, 26)),
        ];
    }

    private function solveWeek(array $closed = [], array $options = []): array
    {
        $days = $this->days();
        $problem = $this->problem($this->team(), $this->open($days, $closed), [
            'restaurants' => [self::VILLA, self::GUSTO], 'closed_days' => $closed, 'busy_weekdays' => [], 'time_limit' => 60, 'max_iterations' => 60000,
        ] + $options);

        return [$this->solve($problem), $problem, $days];
    }

    /** @return array{0: int, 1: int} số waiter của nhà hàng $home làm M6 / A2 trong ngày (ở bất kỳ nhà hàng nào) */
    private function homeSplit(SolveResult $result, string $day, int $home): array
    {
        $ids = $home === self::VILLA ? range(1, 10) : range(21, 26);
        $count = fn (int $shift) => count(array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift'
            && $r['shift_id'] === $shift && in_array($r['employee_id'], $ids, true)));

        return [$count(self::M6), $count(self::A2)];
    }

    /** Cả hai nhà hàng mở: không ai bị điều chuyển (không M6(G) / A2(G)) ngày nào, kể cả Chủ nhật; M6 / A2 cân bằng từng nhà hàng. */
    public function test_no_transfer_when_both_restaurants_open(): void
    {
        [$result, $problem, $days] = $this->solveWeek();

        $moved = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['off_home_outlet']);
        $this->assertSame([], array_values($moved), 'Không điều chuyển Villa ↔ Gusto khi cả hai mở');

        foreach ($days as $day) {
            foreach ([self::VILLA, self::GUSTO] as $home) {
                [$am, $pm] = $this->homeSplit($result, $day, $home);
                $this->assertLessThanOrEqual(($am + $pm) % 2, abs($am - $pm), "{$day} outlet {$home}: {$am}/{$pm}");
            }
        }

        $this->assertHardConstraintsHold($problem, $result);
    }

    /**
     * Villa đóng cửa Chủ nhật (Ngày đặc biệt): chỉ Chủ nhật nhân viên Villa sang Gusto; M6 / A2 tại Gusto cân bằng trên TỔNG người
     * Villa + Gusto (quản lý 30/10/2026: không chia riêng theo outlet gốc khi chỉ còn một nhà hàng).
     */
    public function test_villa_closed_on_sunday_transfers_only_that_day_and_balances_each_group(): void
    {
        $sunday = $this->day(6);
        [$result, $problem, $days] = $this->solveWeek([self::VILLA => [$sunday => true]]);

        foreach ($result->assignments as $r) {
            if ($r['kind'] === 'shift' && $r['off_home_outlet']) {
                $this->assertSame($sunday, $r['date'], "#{$r['employee_id']} chỉ điều chuyển ngày Villa đóng cửa");
                $this->assertSame(self::GUSTO, $r['outlet_id']);
            }
        }

        $gusto = $this->homeSplit($result, $sunday, self::GUSTO);
        $villa = $this->homeSplit($result, $sunday, self::VILLA);
        [$am, $pm] = [$gusto[0] + $villa[0], $gusto[1] + $villa[1]];
        $this->assertSame(($am + $pm) % 2, abs($am - $pm), "Tổng tại Gusto {$am}/{$pm} (chẵn bằng nhau, lẻ lệch 1)");
        $this->assertGreaterThan(0, array_sum($villa), 'Villa đóng cửa: người Villa vẫn có chỗ làm ở Gusto');
        $this->assertHardConstraintsHold($problem, $result);
    }

    /**
     * Gusto đóng cửa cả tuần: nhân viên Gusto làm ở Villa; mỗi ngày tổng người làm Villa chia M6 / A2 lệch ≤ 1 (chẵn: bằng nhau);
     * người Gusto có OFF đã duyệt không bị xếp; báo cáo ngày ghi rõ số người điều động.
     */
    public function test_gusto_closed_staff_work_at_villa_balanced(): void
    {
        $days = $this->days();
        $closed = [self::GUSTO => array_fill_keys($days, true)];
        [$result, $problem] = $this->solveWeek($closed, ['hard' => [21 => [$days[2] => 'Xin OFF']]]);

        $this->assertSame([], array_values(array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['outlet_id'] === self::GUSTO)), 'Không ai làm ở Gusto đang đóng');
        $this->assertNull($this->shiftOf($result, 21, $days[2]), 'OFF đã duyệt giữ nguyên');
        $this->assertNotEmpty(array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['employee_id'] >= 21 && $r['outlet_id'] === self::VILLA), 'Người Gusto làm ở Villa');

        foreach ($days as $day) {
            $count = fn (int $s) => count(array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift' && $r['outlet_id'] === self::VILLA && $r['shift_id'] === $s && ! $r['support']));
            [$am, $pm] = [$count(self::M6), $count(self::A2)];
            $this->assertSame(($am + $pm) % 2, abs($am - $pm), "{$day}: Villa {$am}/{$pm} (chẵn bằng nhau, lẻ lệch 1)");
        }

        $check = collect($result->supportReport['day_checks'])->firstWhere('date', $days[0])['checks']['restaurant'];
        $this->assertTrue($check['ok']);
        $this->assertMatchesRegularExpression('/Gusto đóng cửa → làm tại Villa: \d+ người \(M6 \d+ \/ A2 \d+\), điều động từ Gusto: [1-9]/', $check['text']);
        $this->assertStringContainsString('OFF đã duyệt', collect($result->supportReport['day_checks'])->firstWhere('date', $days[2])['checks']['restaurant']['text']);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Cả hai cùng đóng cửa một ngày: không xếp nhân viên vào Villa / Gusto ngày đó, có cảnh báo. */
    public function test_both_closed_warns_and_assigns_nobody(): void
    {
        $day = $this->day(3);
        [$result] = $this->solveWeek([self::VILLA => [$day => true], self::GUSTO => [$day => true]]);

        $this->assertSame([], array_values(array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift')));
        $check = collect($result->supportReport['day_checks'])->firstWhere('date', $day)['checks']['restaurant'];
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('đóng cửa', $check['text']);
    }

    /** Quản lý Villa như ProblemBuilder dựng: năng lực Gusto + ngoại lệ "nhà hàng gốc đóng cửa". */
    private function villaManager(int $id): EmployeeSpec
    {
        return new EmployeeSpec($id, "Captain #{$id}", $id % 2 ? 'female' : 'male', self::VILLA, 48, 2, [self::VILLA . ':0' => 'primary', self::GUSTO . ':0' => 'eligible'], [], 'cam_ranh', 200,
            isManager: true, managerShiftIds: [self::M6, self::A2], closedHomeOutletIds: [self::GUSTO]);
    }

    /**
     * Villa đóng cửa cả tuần (quản lý 30/10/2026): quản lý Villa (Junior Captain trở lên) được xếp tại Gusto, chỉ M6 / A2;
     * OFF đã duyệt của quản lý giữ nguyên.
     */
    public function test_villa_managers_work_at_gusto_when_villa_closed(): void
    {
        $days = $this->days();
        $closed = [self::VILLA => array_fill_keys($days, true)];
        $result = $this->solve($this->problem([...$this->team(), $this->villaManager(90), $this->villaManager(91)], $this->open($days, $closed), [
            'restaurants' => [self::VILLA, self::GUSTO], 'closed_days' => $closed, 'busy_weekdays' => [], 'time_limit' => 60, 'max_iterations' => 40000,
            'hard' => [90 => [$days[0] => 'Xin OFF']]]));

        $this->assertNull($this->shiftOf($result, 90, $days[0]), 'OFF đã duyệt của quản lý giữ nguyên');

        foreach ([90, 91] as $id) {
            $shifts = array_filter($result->assignments, fn ($r) => $r['employee_id'] === $id && $r['kind'] === 'shift');
            $this->assertGreaterThanOrEqual(4, count($shifts), "Captain #{$id} được xếp lịch tại Gusto");

            foreach ($shifts as $r) {
                $this->assertSame(self::GUSTO, $r['outlet_id'], "Captain #{$id} {$r['date']} làm tại Gusto");
                $this->assertContains($r['shift_id'], [self::M6, self::A2], 'Quản lý chỉ M6 / A2');
            }
        }

        foreach ($result->supportReport['day_checks'] as $day) {
            $this->assertStringNotContainsString('không tự điều chuyển', $day['checks']['restaurant']['text'], 'Không bỏ qua quản lý vì nhà hàng gốc đóng');
        }
    }

    /** Cả hai nhà hàng mở: quy tắc cũ giữ nguyên – quản lý Villa không tự sang Gusto. */
    public function test_manager_not_transferred_when_both_open(): void
    {
        $result = $this->solve($this->problem([...$this->team(), $this->villaManager(90)], $this->open($this->days()), [
            'restaurants' => [self::VILLA, self::GUSTO], 'busy_weekdays' => [], 'time_limit' => 60, 'max_iterations' => 40000]));

        $shifts = array_filter($result->assignments, fn ($r) => $r['employee_id'] === 90 && $r['kind'] === 'shift');
        $this->assertNotEmpty($shifts);
        $this->assertSame([self::VILLA], array_values(array_unique(array_column($shifts, 'outlet_id'))), 'Quản lý chỉ làm tại Villa');
    }

    /**
     * Lỗi quản lý 05/11/2026: cả hai nhà hàng mở, Villa ngày thứ 6 có 3 nam M6 / 1 nam A2 – waiter nam vẫn 1/1 nhưng hai quản lý nam cùng M6, hai quản lý nữ
     * cùng A2 (cân bằng quản lý chỉ đếm số người). Số nam trên bảng tính cả quản lý: phải chia đều (chẵn bằng nhau, lẻ lệch 1) từng nhà hàng, từng ngày.
     */
    public function test_total_men_including_managers_split_evenly_each_day(): void
    {
        $manager = fn (int $id, string $gender) => new EmployeeSpec($id, "Captain #{$id}", $gender, self::VILLA, 48, 2, [self::VILLA . ':0' => 'primary'], [], 'cam_ranh', 200,
            isManager: true, managerShiftIds: [self::M6, self::A2], isMaleStaff: false);
        $people = [$manager(90, 'male'), $manager(91, 'male'), $manager(92, 'female'), $manager(93, 'female'), $this->waiter(1, self::VILLA, 'male'), $this->waiter(2, self::VILLA, 'male'),
            $this->waiter(3, self::VILLA, 'female'), $this->waiter(4, self::VILLA, 'female')];
        $days = $this->days();
        $options = ['restaurants' => [self::VILLA, self::GUSTO], 'busy_weekdays' => [], 'time_limit' => 60];
        $m6 = ScheduleState::slotKey(self::VILLA, self::M6, false);
        $a2 = ScheduleState::slotKey(self::VILLA, self::A2, false);
        // Đúng tình huống báo lỗi, từng ngày: hai quản lý nam M6, hai quản lý nữ A2, waiter nam 1 / 1, waiter nữ 1 / 1 → nam 3 / 1.
        $plan = [90 => $m6, 91 => $m6, 92 => $a2, 93 => $a2, 1 => $m6, 2 => $a2, 3 => $m6, 4 => $a2];
        $state = new ScheduleState($this->problem($people, $this->open($days), $options));

        foreach ($plan as $id => $slot) {
            [$o, $shift] = ScheduleState::parseSlot($slot);
            $state->place($id, $state->index[$days[4]], $o, $shift);
        }

        $values = $state->objectiveValues();
        $this->assertSame(1, $values['male_total_balance'], 'Nhận ra 3 nam / 1 nam là lệch');
        $this->assertSame([0, 0, 0], [$values['manager_balance'], $values['male_balance'], $values['waiter_balance']], 'Các tiêu chí cũ không thấy lệch – đây là nguyên nhân lỗi');
        $this->assertGreaterThan(Rules::DEFAULT_WEIGHTS['heavy_transitions'], Rules::DEFAULT_WEIGHTS['male_total_balance'], 'Không đổi lấy việc tránh một ca nặng');

        // Chạy tiếp từ lịch lệch cả tuần: thuật toán đưa về chia đều, không làm lệch quản lý / waiter nam.
        $seed = [];

        foreach ($plan as $id => $slot) {
            foreach ($days as $k => $day) {
                if ($k < 5) {
                    $seed[$id][$day] = $slot;
                }
            }
        }

        $problem = $this->problem($people, $this->open($days), [...$options, 'max_iterations' => 40000, 'seed_plan' => $seed]);
        $result = $this->solve($problem);
        $objective = $result->softConstraintScores['objective'];
        $this->assertSame(0, $objective['male_total_balance'], 'Nam chia đều mọi ngày');
        $this->assertSame([0, 0], [$objective['manager_balance'], $objective['male_balance']], 'Quản lý, waiter nam vẫn cân bằng');
        $men = [90, 91, 1, 2];

        foreach ($days as $day) {
            $count = fn (int $shift) => count(array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift' && $r['outlet_id'] === self::VILLA
                && $r['shift_id'] === $shift && ! $r['support'] && in_array($r['employee_id'], $men, true)));
            [$am, $pm] = [$count(self::M6), $count(self::A2)];
            $this->assertLessThanOrEqual(($am + $pm) % 2, abs($am - $pm), "{$day}: nam {$am}/{$pm}");
        }

        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Nam đa năng thuộc Night (RS hỗ trợ): làm được Villa, Gusto; không phải quản lý. */
    private function nightFlex(int $id): EmployeeSpec
    {
        return new EmployeeSpec($id, "Flex #{$id}", 'male', self::NIGHT, 48, 2, [self::VILLA . ':0' => 'eligible', self::GUSTO . ':0' => 'eligible'], [], 'cam_ranh', self::WAITER,
            isMaleStaff: true, isFlex: true);
    }

    /**
     * @param int[] $villaMen, $gustoMen số waiter nam mỗi nhà hàng
     * @return array{0: \Botble\RestaurantRoster\Scheduling\Problem, 1: SolveResult, 2: EmployeeSpec[]}
     */
    private function flexWeek(int $villaMen, int $gustoMen, array $options = []): array
    {
        $people = [$this->nightFlex(80), $this->nightFlex(81)];
        $id = 1;

        foreach ([self::VILLA => $villaMen, self::GUSTO => $gustoMen] as $home => $men) {
            foreach (range(1, $men) as $k) {
                $people[] = $this->waiter($id++, $home, 'male');
            }

            foreach (range(1, 4) as $k) {
                $people[] = $this->waiter($id++, $home, 'female');
            }
        }

        $problem = $this->problem($people, $this->open($this->days()), ['restaurants' => [self::VILLA, self::GUSTO], 'busy_weekdays' => [], 'time_limit' => 60, 'max_iterations' => 60000, 'flex_overflow' => self::GUSTO, ...$options]);

        return [$problem, $this->solve($problem), $people];
    }

    /** Lần lượt: [ngày, outlet, ca, số nam kể cả người đa năng, số người đa năng] cho các ca M6 / A2 của hai nhà hàng. */
    private function menByCell(SolveResult $result, array $people): array
    {
        $men = array_map(fn (EmployeeSpec $e) => $e->id, array_filter($people, fn (EmployeeSpec $e) => $e->gender === 'male'));
        $cells = [];

        foreach ($this->days() as $day) {
            foreach ([self::VILLA, self::GUSTO] as $outlet) {
                foreach ([self::M6, self::A2] as $shift) {
                    $rows = array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift' && $r['outlet_id'] === $outlet && $r['shift_id'] === $shift && in_array($r['employee_id'], $men, true));
                    $flex = array_filter($rows, fn ($r) => in_array($r['employee_id'], [80, 81], true));
                    $cells[] = [$day, $outlet, $shift, count($rows), count($flex)];
                }
            }
        }

        return $cells;
    }

    /** Quản lý 06/11/2026: ca nào của Villa / Gusto còn dưới 2 nam thì RS hỗ trợ vào đó; không đứng thành người thứ 3 khi còn ca thiếu. */
    public function test_night_flex_men_go_where_a_shift_lacks_men(): void
    {
        // Villa ít nam, Gusto nhiều nam: người đa năng phải sang Villa.
        [, $result, $people] = $this->flexWeek(2, 8);
        $this->assertSame(0, $result->softConstraintScores['objective']['flex_placement']);
        $worked = 0;

        foreach ($this->menByCell($result, $people) as [$day, $outlet, $shift, $men, $flex]) {
            $worked += $flex;
            $this->assertLessThanOrEqual(max(0, $men - 2) === 0 ? 2 : 0, $outlet === self::VILLA && $men > 2 ? $flex : 0, "{$day} Villa ca {$shift}: {$men} nam, {$flex} đa năng");
        }

        $this->assertSame(10, $worked, 'Đủ ngày công');
        $villa = array_sum(array_map(fn ($c) => $c[1] === self::VILLA ? $c[4] : 0, $this->menByCell($result, $people)));
        $this->assertGreaterThan(5, $villa, 'Phần lớn ngày làm ở Villa – nơi thiếu nam');
    }

    /** Mọi ca của cả hai nhà hàng đã đủ 2 nam: người đa năng vào Gusto, không vào Villa. */
    public function test_night_flex_men_go_to_gusto_when_all_shifts_have_two_men(): void
    {
        [, $result, $people] = $this->flexWeek(8, 8);
        $cells = $this->menByCell($result, $people);
        $this->assertSame(0, $result->softConstraintScores['objective']['flex_placement']);
        $this->assertSame(0, array_sum(array_map(fn ($c) => $c[1] === self::VILLA && $c[3] > 2 ? $c[4] : 0, $cells)), 'Villa đã đủ thì không thêm');
        $this->assertSame(10, array_sum(array_column($cells, 4)), 'Đủ ngày công');
    }

    /** Thiếu nam tính theo waiter nam, không tính quản lý: ca có 2 quản lý nam nhưng chưa có waiter nam vẫn là ca thiếu. */
    public function test_night_flex_need_counts_male_waiters_not_managers(): void
    {
        $days = $this->days();
        $manager = fn (int $id, int $home) => new EmployeeSpec($id, "Captain #{$id}", 'male', $home, 48, 2, [$home . ':0' => 'primary'], [], 'cam_ranh', 200,
            isManager: true, managerShiftIds: [self::M6, self::A2], isMaleStaff: false);
        $people = [$this->nightFlex(80), $this->waiter(1, self::GUSTO, 'male'), $this->waiter(2, self::GUSTO, 'male')];
        $id = 90;
        $managers = [];

        foreach ([self::VILLA, self::GUSTO] as $outlet) {
            foreach ([self::M6, self::A2] as $shift) {
                foreach ([1, 2] as $k) {
                    $people[] = $manager($id, $outlet);
                    $managers[] = [$id++, $outlet, $shift];
                }
            }
        }

        $state = new ScheduleState($this->problem($people, $this->open($days), ['restaurants' => [self::VILLA, self::GUSTO], 'flex_overflow' => self::GUSTO]));
        $i = $state->index[$days[0]];

        foreach ($managers as [$managerId, $outlet, $shift]) {
            $state->place($managerId, $i, $outlet, $shift);
        }

        // Gusto M6: 2 waiter nam + người đa năng là người thứ 3, trong khi các ca khác có quản lý nam nhưng chưa có waiter nam.
        $state->place(1, $i, self::GUSTO, self::M6);
        $state->place(2, $i, self::GUSTO, self::M6);
        $state->place(80, $i, self::GUSTO, self::M6);
        $this->assertSame(4, $state->objectiveValues()['flex_placement'], 'Còn ca thiếu waiter nam dù ca đó đã có 2 quản lý nam');

        // Sang ca chưa có waiter nam: không còn đặt sai.
        $state->remove(80, $i);
        $state->place(80, $i, self::VILLA, self::M6);
        $this->assertSame(0, $state->objectiveValues()['flex_placement']);
    }

    /** Một nhà hàng đóng cửa: quy tắc này không áp dụng (chỉ khi cả hai mở). */
    public function test_night_flex_rule_not_applied_when_a_restaurant_closed(): void
    {
        $days = $this->days();
        $closed = [self::GUSTO => array_fill_keys($days, true)];
        $people = [$this->nightFlex(80), $this->waiter(1, self::VILLA, 'male'), $this->waiter(2, self::VILLA, 'male'), $this->waiter(3, self::VILLA, 'male')];
        $state = new ScheduleState($this->problem($people, $this->open($days, $closed), ['restaurants' => [self::VILLA, self::GUSTO], 'closed_days' => $closed, 'flex_overflow' => self::GUSTO]));

        foreach ([1, 2, 80] as $id) {
            $state->place($id, $state->index[$days[0]], self::VILLA, self::M6);
        }

        $this->assertSame(0, $state->objectiveValues()['flex_placement']);
    }

    /** Ô quản lý đã xếp tay / khóa sang nhà hàng kia (vd A2(G)) được giữ nguyên dù cả hai mở. */
    public function test_locked_manual_transfer_is_kept(): void
    {
        $day = $this->day(6);
        [$result] = $this->solveWeek([], ['fixed' => [$this->locked(1, $day, self::GUSTO, self::A2)]]);

        $this->assertSame([self::GUSTO, self::A2], $this->shiftOf($result, 1, $day));
        $moved = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['off_home_outlet'] && $r['employee_id'] !== 1);
        $this->assertSame([], array_values($moved), 'Ngoài ô khóa, không ai khác bị điều chuyển');
    }

    /** Chạy lại cùng dữ liệu: kết quả ổn định (cùng hạt giống), không trùng ca. */
    public function test_rerun_is_stable(): void
    {
        [$a] = $this->solveWeek();
        [$b, $problem] = $this->solveWeek();

        $key = fn (SolveResult $r) => array_map(fn ($x) => implode('|', [$x['employee_id'], $x['date'], $x['kind'], $x['outlet_id'] ?? '', $x['shift_id'] ?? '']), $r->assignments);
        $this->assertSame($key($a), $key($b), 'Cùng dữ liệu, cùng hạt giống → cùng lịch');
        $cells = array_map(fn ($x) => $x['employee_id'] . '|' . $x['date'], $a->assignments);
        $this->assertSame(count($cells), count(array_unique($cells)), 'Mỗi người mỗi ngày một ô');
        $this->assertHardConstraintsHold($problem, $b);
    }
}
