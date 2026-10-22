<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\BorrowPolicy;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Mượn nhân sự giữa các outlet (quản lý 23/10/2026) – DỮ LIỆU GIẢ LẬP:
 * chỉ mượn khi dưới tối thiểu (tổng / nam / nữ waiter theo ca), ưu tiên nhân viên đa năng Night, rồi người dư outlet khác,
 * không làm outlet nguồn dưới tối thiểu, không mượn sai giới tính, không mượn để đạt mục tiêu.
 */
class BorrowPolicyTest extends TestCase
{
    use SyntheticRoster;

    private function person(int $id, int $home, array $skills, string $gender, bool $flex = false): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", $gender, $home, 48, 2, $skills, [], 'cam_ranh', self::WAITER,
            isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male', isFlex: $flex);
    }

    private function restaurant(int $id, int $home, string $gender): EmployeeSpec
    {
        $other = $home === self::GUSTO ? self::VILLA : self::GUSTO;

        return $this->person($id, $home, [$home . ':' . self::M6 => 'primary', $home . ':' . self::A2 => 'primary', $other . ':0' => 'eligible'], $gender);
    }

    private function flex(int $id, string $gender = 'male'): EmployeeSpec
    {
        return $this->person($id, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary', self::GUSTO . ':0' => 'eligible', self::VILLA . ':0' => 'eligible'], $gender, true);
    }

    /**
     * Một ngày: Villa M6 / A2 mỗi ca tối thiểu 2 người, 1 nam waiter, 1 nữ waiter; Gusto như vậy; Night N cần $nightMin.
     *
     * @param EmployeeSpec[] $people
     */
    private function oneDay(array $people, array $hard = [], int $nightMin = 0, array $villa = [2, 1, 1], array $fixed = []): SolveResult
    {
        $day = $this->day(0);
        [$min, $male, $female] = $villa;
        $requirements = [
            new Requirement($day, self::VILLA, self::M6, $min, $min + 1, 4, minMale: $male, targetMale: $male, minFemale: $female, targetFemale: $female),
            new Requirement($day, self::VILLA, self::A2, $min, $min + 1, 4, minMale: $male, targetMale: $male, minFemale: $female, targetFemale: $female),
            new Requirement($day, self::GUSTO, self::M6, 2, 3, 4, minMale: 1, targetMale: 1, minFemale: 1, targetFemale: 1),
            new Requirement($day, self::GUSTO, self::A2, 2, 3, 4, minMale: 1, targetMale: 1, minFemale: 1, targetFemale: 1),
        ];

        if ($nightMin) {
            $requirements[] = new Requirement($day, self::NIGHT, self::N, $nightMin, $nightMin, $nightMin);
        }

        return $this->solve($this->problem($people, $requirements, ['days' => [$day], 'hard' => $hard, 'fixed' => $fixed,
            'time_limit' => 60, 'max_iterations' => 30000]));
    }

    private function cell(SolveResult $result, int $id): ?array
    {
        return $this->shiftOf($result, $id, $this->day(0));
    }

    /** Villa: 2 nam OFF, 2 nữ đi làm; Gusto: 3 nam + 3 nữ (dư 1 nam). */
    private function villaMalesOff(): array
    {
        return [
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'male'), [1, 2]),
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'female'), [3, 4, 5, 6]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'male'), [11, 12, 13]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'female'), [14, 15, 16]),
        ];
    }

    private function villaMaleOff(): array
    {
        return [1 => [$this->day(0) => 'Xin OFF'], 2 => [$this->day(0) => 'Xin OFF']];
    }

    private function maleAt(SolveResult $result, int $outlet, int $shift): array
    {
        return array_values(array_map(fn ($r) => $r['employee_id'], array_filter($result->assignments,
            fn ($r) => $r['kind'] === 'shift' && $r['outlet_id'] === $outlet && $r['shift_id'] === $shift && in_array($r['employee_id'], [11, 12, 13, 21, 22], true))));
    }

    /** Ưu tiên 1: Villa không còn nam (OFF) → mượn nhân viên đa năng Night trước, không lấy nam Gusto. */
    public function test_night_flex_is_borrowed_first(): void
    {
        $result = $this->oneDay([...$this->villaMalesOff(), $this->flex(21), $this->flex(22)], $this->villaMaleOff());

        $villaMales = array_merge($this->maleAt($result, self::VILLA, self::M6), $this->maleAt($result, self::VILLA, self::A2));
        $this->assertEqualsCanonicalizing([21, 22], $villaMales, 'Hai nhân viên đa năng Night sang Villa M6 / A2');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
        $borrows = $result->supportReport['borrows'];
        $this->assertCount(2, $borrows);
        $this->assertStringContainsString('Ưu tiên 1', $borrows[0]['priority']);
        $this->assertStringContainsString('thiếu nam waiter tối thiểu', $borrows[0]['reason']);
    }

    /** Ưu tiên 2: đa năng Night OFF → mượn nam dư của Gusto; Gusto vẫn đủ 1 nam mỗi ca. */
    public function test_other_outlet_lends_only_its_surplus_when_flex_unavailable(): void
    {
        $result = $this->oneDay([...$this->villaMalesOff(), $this->flex(21)], $this->villaMaleOff() + [21 => [$this->day(0) => 'Xin OFF']]);

        $this->assertGreaterThanOrEqual(1, count($this->maleAt($result, self::GUSTO, self::M6)), 'Gusto M6 vẫn có nam');
        $this->assertGreaterThanOrEqual(1, count($this->maleAt($result, self::GUSTO, self::A2)), 'Gusto A2 vẫn có nam');
        $borrowed = array_merge($this->maleAt($result, self::VILLA, self::M6), $this->maleAt($result, self::VILLA, self::A2));
        $this->assertCount(1, $borrowed, 'Gusto chỉ dư 1 nam → cho mượn đúng 1');
        $this->assertSame(0, (int) ($result->solverMetadata['objective']['source_shortage'] ?? 0));

        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::VILLA && $u['level'] === 'min'))[0] ?? null;
        $this->assertNotNull($gap, 'Ca còn lại vẫn thiếu nam → cảnh báo');
        $this->assertStringContainsString('Villa thiếu nhân viên nam', $gap['borrow']['message']);
        $this->assertStringContainsString('Nhân viên đa năng Night không thể hỗ trợ', $gap['borrow']['message']);
        $this->assertStringContainsString('OFF đã duyệt', $gap['borrow']['message']);
    }

    /** Không mượn khi outlet nguồn không còn người dư: báo rõ nguồn đã kiểm tra và lý do. */
    public function test_no_borrow_when_source_has_no_surplus(): void
    {
        $people = [
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'male'), [1, 2]),
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'female'), [3, 4, 5, 6]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'male'), [11, 12]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'female'), [14, 15, 16, 17]),
        ];
        $result = $this->oneDay($people, $this->villaMaleOff());

        $this->assertSame([], array_merge($this->maleAt($result, self::VILLA, self::M6), $this->maleAt($result, self::VILLA, self::A2)), 'Không rút nam Gusto');
        $gaps = array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::VILLA && $u['level'] === 'min');
        $this->assertCount(2, $gaps);
        $this->assertStringContainsString('outlet khác không còn người dư phù hợp', array_values($gaps)[0]['borrow']['message']);
        $this->assertStringContainsString('dưới tối thiểu', array_values($gaps)[0]['borrow']['message']);
    }

    /** Chỉ mượn khi dưới tối thiểu: Villa đủ tối thiểu nhưng dưới mục tiêu → không mượn, dù Gusto dư người. */
    public function test_no_borrow_for_target_only(): void
    {
        $people = [
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'male'), [1, 2]),
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'female'), [3, 4]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'male'), [11, 12, 13]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'female'), [14, 15, 16, 17]),
        ];
        $result = $this->oneDay($people);

        $this->assertSame([], $result->supportReport['borrows'], 'Không mượn người để đạt mục tiêu');
        $this->assertSame([], array_filter($result->assignments, fn ($r) => $r['employee_id'] >= 11 && $r['outlet_id'] === self::VILLA));
    }

    /** Thiếu nữ waiter: không dùng nhân viên đa năng nam; mượn nữ waiter dư của Gusto, Gusto vẫn đủ nữ tối thiểu. */
    public function test_female_shortage_borrows_female_never_male(): void
    {
        $day = $this->day(0);
        $people = [
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'male'), [1, 2, 7, 8]),
            ...array_map(fn ($id) => $this->restaurant($id, self::VILLA, 'female'), [3, 4]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'male'), [11, 12]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, 'female'), [14, 15, 16]),
            $this->flex(21),
        ];
        // Night cần 1 người: nhân viên đa năng nam có việc ở Night, không bị đẩy sang Villa cho đủ ngày công.
        $result = $this->oneDay($people, [3 => [$day => 'Xin OFF'], 4 => [$day => 'Xin OFF']], nightMin: 1);

        $villaFemales = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['outlet_id'] === self::VILLA && in_array($r['employee_id'], [14, 15, 16], true));
        $this->assertCount(1, $villaFemales, 'Gusto chỉ dư 1 nữ waiter → cho mượn 1');
        $this->assertNotSame(self::VILLA, $this->cell($result, 21)[0] ?? null, 'Đa năng nam không thay nữ waiter');

        $gusto = fn (int $shift) => count(array_filter($result->assignments, fn ($r) => $r['outlet_id'] === self::GUSTO && $r['shift_id'] === $shift && in_array($r['employee_id'], [14, 15, 16], true)));
        $this->assertGreaterThanOrEqual(1, $gusto(self::M6));
        $this->assertGreaterThanOrEqual(1, $gusto(self::A2));
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::VILLA && $u['level'] === 'min'))[0] ?? null;
        $this->assertStringContainsString('thiếu nữ waiter', $gap['borrow']['message'] ?? '');
    }

    /** Không rút nhân viên đa năng khi Night sẽ dưới tối thiểu; ô khóa của người nguồn giữ nguyên. */
    public function test_flex_not_taken_when_night_needs_him_and_locked_cells_kept(): void
    {
        $day = $this->day(0);
        $result = $this->oneDay([...$this->villaMalesOff(), $this->flex(21)], $this->villaMaleOff(), nightMin: 1,
            fixed: [new FixedAssignment(11, $day, FixedAssignment::KIND_SHIFT, self::GUSTO, self::M6)]);

        $this->assertSame([self::NIGHT, self::N], $this->cell($result, 21), 'Night cần người đa năng → giữ ở Night');
        $this->assertSame([self::GUSTO, self::M6], $this->cell($result, 11), 'Ô khóa không bị điều chuyển');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::NIGHT));
    }

    /** Phần "mượn vượt nhu cầu" tính riêng tổng / nam / nữ (cộng các ca của outlet trong ngày). */
    public function test_excess_counts_only_borrowing_beyond_the_minimum(): void
    {
        $min = [3, 1, 1];

        $this->assertSame(0, BorrowPolicy::excess($min, [3, 1, 2], [1, 1, 0]), 'Mượn 1 nam để có nam');
        $this->assertSame(1, BorrowPolicy::excess($min, [4, 1, 2], [2, 1, 1]), 'Mượn thêm 1 nữ khi nữ đã đủ → vượt 1');
        $this->assertSame(0, BorrowPolicy::excess($min, [3, 2, 1], [2, 1, 1]), 'Thiếu 2 người tổng → mượn 2 đều cần');
        $this->assertSame(1, BorrowPolicy::excess($min, [4, 1, 3], [1, 0, 1]), 'Đã đủ mọi tối thiểu → mượn là vượt');
    }
}
