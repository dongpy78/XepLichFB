<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * OFF rải đều các ngày trong tuần, kể cả thứ 7 / Chủ nhật (quản lý 28/10/2026) – DỮ LIỆU GIẢ LẬP.
 * Không hardcode số người OFF mỗi ngày: chỉ kiểm tra độ chênh và việc OFF đã duyệt được giữ, có cảnh báo khi dồn.
 */
class OffSpreadTest extends TestCase
{
    use SyntheticRoster;

    private const SKILLS = [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'];

    /** Chỗ mở M6 / A2 (chế độ balance): không định biên nhà hàng. */
    private function open(array $days): array
    {
        return [...array_map(fn ($d) => new Requirement($d, self::VILLA, self::M6, 0, 0, 30), $days), ...array_map(fn ($d) => new Requirement($d, self::VILLA, self::A2, 0, 0, 30), $days)];
    }

    private function staff(int $count): array
    {
        return array_map(fn ($id) => new EmployeeSpec($id, "Waiter #{$id}", $id % 2 ? 'female' : 'male', self::VILLA, 48, 2, self::SKILLS, [], 'cam_ranh', self::WAITER,
            isFemaleStaff: (bool) ($id % 2), isMaleStaff: ! ($id % 2)), range(1, $count));
    }

    /** @return int[] số người OFF mỗi ngày */
    private function offsPerDay(SolveResult $result, array $days, int $count): array
    {
        return array_map(fn ($d) => $count - count(array_filter($result->assignments, fn ($r) => $r['date'] === $d && $r['kind'] === 'shift')), $days);
    }

    private function options(array $extra = []): array
    {
        return ['busy_weekdays' => [], 'time_limit' => 60, 'max_iterations' => 40000] + $extra;
    }

    /** 14 nhân viên × 2 ngày OFF: rải đều, không dồn vào một ngày; thứ 7 và Chủ nhật đều có người OFF, không bị né. */
    public function test_offs_spread_across_week_including_weekend(): void
    {
        $days = $this->days();
        $problem = $this->problem($this->staff(14), $this->open($days), $this->options());
        $result = $this->solve($problem);
        $offs = $this->offsPerDay($result, $days, 14);

        $this->assertSame(28, array_sum($offs), 'Ai cũng đủ 2 ngày OFF');
        $this->assertLessThanOrEqual(2, max($offs) - min($offs), 'OFF / ngày lệch tối đa 2: ' . json_encode($offs));
        $this->assertGreaterThanOrEqual(2, $offs[5], 'Thứ 7 có người OFF: ' . json_encode($offs));
        $this->assertGreaterThanOrEqual(2, $offs[6], 'Chủ nhật có người OFF: ' . json_encode($offs));
        $this->assertLessThanOrEqual(2, abs($offs[5] - $offs[6]), 'OFF cuối tuần chia giữa thứ 7 và Chủ nhật: ' . json_encode($offs));
        $this->assertSame([], array_filter($result->warnings, fn ($w) => $w['code'] === 'staff_off'), 'Không có ngày OFF dồn bất thường');
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** OFF dồn trước đây (cuối tuần bị né) cho điểm phạt cao hơn phương án rải đều: thuật toán chọn phương án đều hơn. */
    public function test_even_spread_scores_better_than_bunched_offs(): void
    {
        $days = $this->days();
        $problem = $this->problem($this->staff(14), $this->open($days), $this->options());
        $result = $this->solve($problem);
        $offs = $this->offsPerDay($result, $days, 14);
        $bunched = [5, 5, 5, 5, 6, 1, 1]; // dồn OFF vào thứ 2 – thứ 6

        $squares = fn (array $c) => array_sum(array_map(fn ($n) => $n ** 2, $c));
        $this->assertLessThan($squares($bunched), $squares($offs), 'Phương án tìm được đều hơn phương án dồn: ' . json_encode($offs));
    }

    /** Nhân viên xin nghỉ thứ 7 và người khác xin nghỉ Chủ nhật: OFF đã duyệt được giữ, vẫn đủ 2 ngày OFF mỗi người. */
    public function test_requested_weekend_offs_are_kept(): void
    {
        $days = $this->days();
        $hard = [3 => [$days[5] => 'Xin OFF thứ 7'], 4 => [$days[6] => 'Xin OFF Chủ nhật']];
        $result = $this->solve($this->problem($this->staff(10), $this->open($days), $this->options(['hard' => $hard])));

        $this->assertNull($this->shiftOf($result, 3, $days[5]), 'Giữ OFF thứ 7 đã duyệt');
        $this->assertNull($this->shiftOf($result, 4, $days[6]), 'Giữ OFF Chủ nhật đã duyệt');
    }

    /** Nhiều người cùng có OFF đã duyệt thứ 7: giữ nguyên, có cảnh báo nêu ngày và người, các OFF còn lại tránh ngày đó. */
    public function test_many_approved_saturday_offs_kept_and_warned(): void
    {
        $days = $this->days();
        $hard = array_fill_keys([1, 2, 3, 4, 5, 6], [$days[5] => 'Xin OFF']);
        $result = $this->solve($this->problem($this->staff(12), $this->open($days), $this->options(['hard' => $hard])));

        foreach (array_keys($hard) as $id) {
            $this->assertNull($this->shiftOf($result, $id, $days[5]), "#{$id}: giữ OFF đã duyệt");
        }

        $warning = array_values(array_filter($result->warnings, fn ($w) => $w['code'] === 'staff_off' && $w['date'] === $days[5]))[0] ?? null;
        $this->assertNotNull($warning, 'Cảnh báo nhiều nhân viên OFF cùng thứ 7');
        $this->assertStringContainsString('OFF đã duyệt', $warning['message']);

        $offs = $this->offsPerDay($result, $days, 12);
        $this->assertSame(6, $offs[5], 'Thứ 7 chỉ có 6 người OFF đã duyệt – không thêm OFF tự xếp: ' . json_encode($offs));
    }

    /** Ô đã khóa (ca nhân viên xin, OFF quản lý cho) không bị ghi đè khi rải OFF. */
    public function test_locked_cells_not_overridden(): void
    {
        $days = $this->days();
        $fixed = [$this->locked(1, $days[5], self::VILLA, self::M6), $this->locked(2, $days[6], self::VILLA, self::A2)];
        $result = $this->solve($this->problem($this->staff(10), $this->open($days), $this->options(['fixed' => $fixed])));

        $this->assertSame([self::VILLA, self::M6], $this->shiftOf($result, 1, $days[5]));
        $this->assertSame([self::VILLA, self::A2], $this->shiftOf($result, 2, $days[6]));
    }

    /**
     * OFF chia theo nhóm (outlet × vị trí): Junior Restaurant Captain (quản lý), Hostess, Waiter mỗi nhóm rải đều trong chính nhóm –
     * Hostess ít người không bị dồn OFF cùng ngày (ngày nào cũng còn Hostess đi làm); quản lý OFF không dồn.
     */
    public function test_offs_spread_within_each_group(): void
    {
        $days = $this->days();
        $hostessPosition = 101;
        $managers = array_map(fn ($id) => new EmployeeSpec($id, "Captain #{$id}", 'male', self::VILLA, 48, 2, self::SKILLS, [], 'cam_ranh', 200,
            isManager: true, managerShiftIds: [self::M6, self::A2]), range(31, 34));
        $hostess = array_map(fn ($id) => new EmployeeSpec($id, "Hostess #{$id}", 'female', self::VILLA, 48, 2, self::SKILLS, [], 'cam_ranh', $hostessPosition), range(41, 43));
        $problem = $this->problem([...$this->staff(10), ...$managers, ...$hostess], $this->open($days), $this->options());
        $result = $this->solve($problem);
        $offOf = fn (array $ids) => array_map(fn ($d) => count($ids) - count(array_filter($result->assignments, fn ($r) => $r['date'] === $d && $r['kind'] === 'shift' && in_array($r['employee_id'], $ids, true))), $days);

        $hostessOff = $offOf(range(41, 43));
        $this->assertSame(6, array_sum($hostessOff));
        $this->assertLessThanOrEqual(1, max($hostessOff), 'Hostess: ngày nào cũng còn ≥ 2 người đi làm: ' . json_encode($hostessOff));

        $waiterOff = $offOf(range(1, 10));
        $this->assertLessThanOrEqual(2, max($waiterOff) - min($waiterOff), 'Waiter OFF đều: ' . json_encode($waiterOff));

        $managerOff = $offOf(range(31, 34));
        $this->assertLessThanOrEqual(2, max($managerOff), 'Quản lý OFF không dồn: ' . json_encode($managerOff));

        $groups = array_column($result->supportReport['balance'][0]['staff_off'], 'outlet');
        $this->assertEqualsCanonicalizing(['Villa · Waiter', 'Villa · Hostess'], $groups, 'Báo cáo OFF theo nhóm');
        $this->assertHardConstraintsHold($problem, $result);
    }
}
