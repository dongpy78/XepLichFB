<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
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
     * Villa đóng cửa Chủ nhật (Ngày đặc biệt): chỉ Chủ nhật nhân viên Villa sang Gusto; người Gusto không dồn vào M6 –
     * cả nhóm Gusto và nhóm Villa (đang ở Gusto) đều chia đều M6 / A2.
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
        $this->assertLessThanOrEqual(1, abs($gusto[0] - $gusto[1]), 'Người Gusto không dồn hết vào M6: ' . json_encode($gusto));
        $this->assertLessThanOrEqual(1, abs($villa[0] - $villa[1]), 'Người Villa sang Gusto cũng chia đều: ' . json_encode($villa));
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

    /** Quản lý (cấp Captain trở lên) không tự điều chuyển: Villa đóng → quản lý Villa chưa xếp, có lý do. */
    public function test_manager_not_transferred_when_home_closed(): void
    {
        $day = $this->day(1);
        $manager = new EmployeeSpec(90, 'Captain #90', 'male', self::VILLA, 48, 2, [self::VILLA . ':0' => 'primary'], [], 'cam_ranh', 200,
            isManager: true, managerShiftIds: [self::M6, self::A2]);
        $days = $this->days();
        $closed = [self::VILLA => [$day => true]];
        $result = $this->solve($this->problem([...$this->team(), $manager], $this->open($days, $closed), [
            'restaurants' => [self::VILLA, self::GUSTO], 'closed_days' => $closed, 'busy_weekdays' => [], 'time_limit' => 60, 'max_iterations' => 40000]));

        $this->assertNull($this->shiftOf($result, 90, $day), 'Quản lý Villa không bị điều sang Gusto');
        $this->assertStringContainsString('Captain #90 (cấp quản lý không tự điều chuyển', collect($result->supportReport['day_checks'])->firstWhere('date', $day)['checks']['restaurant']['text']);
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
