<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Nam waiter M6 / A2 theo outlet và nhân sự Night (cố định, đa năng, luân phiên theo kế hoạch tuần) – DỮ LIỆU GIẢ LẬP.
 */
class MaleWaiterAndNightTest extends TestCase
{
    use SyntheticRoster;

    private function waiter(int $id, int $home, array $skills, string $gender = 'male', array $fixed = [], ?string $nightRole = null): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", $gender, $home, 48, 2, $skills, $fixed, 'cam_ranh', self::WAITER,
            isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male', nightRole: $nightRole);
    }

    private function dayShifts(int $outlet): array
    {
        return [$outlet . ':' . self::M6 => 'primary', $outlet . ':' . self::A2 => 'primary'];
    }

    /** Một ngày tại Villa: M6 / A2 mỗi ca tối thiểu 1 nam waiter, mục tiêu 2; nữ waiter đủ tổng. */
    private function villaDay(array $males, array $options = []): array
    {
        $day = $this->day(0);
        $females = array_map(fn ($id) => $this->waiter($id, self::VILLA, $this->dayShifts(self::VILLA), 'female'), range(50, 57));
        $problem = $this->problem([...$males, ...$females], [
            new Requirement($day, self::VILLA, self::M6, 4, 4, 6, minMale: 1, targetMale: 2),
            new Requirement($day, self::VILLA, self::A2, 4, 4, 6, minMale: 1, targetMale: 2),
        ], ['days' => [$day], 'time_limit' => 3, 'max_iterations' => 30000, ...$options]);

        return [$this->solve($problem), $problem];
    }

    private function malesOn(SolveResult $result, int $shift, array $ids): int
    {
        return count(array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['shift_id'] === $shift && in_array($r['employee_id'], $ids, true)));
    }

    /** 9.1: 2 nam waiter → 1 M6 + 1 A2. */
    public function test_two_male_waiters_split_between_m6_and_a2(): void
    {
        [$result] = $this->villaDay([$this->waiter(1, self::VILLA, $this->dayShifts(self::VILLA)), $this->waiter(2, self::VILLA, $this->dayShifts(self::VILLA))]);

        $this->assertSame([1, 1], [$this->malesOn($result, self::M6, [1, 2]), $this->malesOn($result, self::A2, [1, 2])]);
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
    }

    /** 9.2: 4 nam waiter → 2 M6 + 2 A2 (không 3 / 1). */
    public function test_four_male_waiters_split_two_and_two(): void
    {
        $males = array_map(fn ($id) => $this->waiter($id, self::VILLA, $this->dayShifts(self::VILLA)), [1, 2, 3, 4]);
        [$result] = $this->villaDay($males);

        $this->assertSame([2, 2], [$this->malesOn($result, self::M6, [1, 2, 3, 4]), $this->malesOn($result, self::A2, [1, 2, 3, 4])]);
    }

    /** 9.4 + 9.15: OFF đã duyệt làm thiếu nam waiter → giữ OFF, báo thiếu kèm nguyên nhân; ô khóa giữ nguyên. */
    public function test_approved_off_kept_and_male_shortage_explained(): void
    {
        $day = $this->day(0);
        $males = [$this->waiter(1, self::VILLA, $this->dayShifts(self::VILLA)), $this->waiter(2, self::VILLA, $this->dayShifts(self::VILLA))];
        [$result, $problem] = $this->villaDay($males, [
            'hard' => [2 => [$day => 'Xin OFF (quản lý đã cho OFF)']],
            'fixed' => [new FixedAssignment(1, $day, FixedAssignment::KIND_SHIFT, self::VILLA, self::M6)],
        ]);

        $this->assertNull($this->shiftOf($result, 2, $day), 'Không phá OFF đã duyệt');
        $this->assertSame([self::VILLA, self::M6], $this->shiftOf($result, 1, $day), 'Ô khóa không bị đổi');
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['shift_id'] === self::A2 && $u['level'] === 'min'))[0] ?? null;
        $this->assertNotNull($gap, 'A2 không có nam waiter → cảnh báo');
        $this->assertSame(0, $gap['assigned_male']);
        $this->assertNotEmpty($gap['reasons']['approved_off']);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 9.3: không có năng lực outlet khác (bộ dựng dữ liệu đã chặn điều chuyển) → không lấy nam Gusto bù Villa. */
    public function test_no_male_borrowed_from_other_outlet_without_transfer_permission(): void
    {
        $gustoMales = array_map(fn ($id) => $this->waiter($id, self::GUSTO, $this->dayShifts(self::GUSTO)), [11, 12]);
        [$result] = $this->villaDay([$this->waiter(1, self::VILLA, $this->dayShifts(self::VILLA)), ...$gustoMales]);

        $this->assertSame([], array_filter($result->assignments, fn ($r) => in_array($r['employee_id'], [11, 12], true) && $r['outlet_id'] === self::VILLA));
        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::VILLA && $u['level'] === 'min'), 'Báo thiếu thay vì mượn người');
    }

    /**
     * Night 3 người / ngày: 2 Night cố định (ca cứng N) + 1 Night đa năng / luân phiên.
     *
     * @param array<int, array<string, string>> $hard
     */
    private function nightWeek(array $extra, array $hard = [], array $plan = [], int $roomMin = 0): array
    {
        $days = $this->days();
        $night = [self::NIGHT . ':' . self::N => 'primary'];
        $core = [
            $this->waiter(1, self::NIGHT, $night, fixed: [self::NIGHT . ':' . self::N], nightRole: EmployeeSpec::NIGHT_CORE),
            $this->waiter(2, self::NIGHT, $night, fixed: [self::NIGHT . ':' . self::N], nightRole: EmployeeSpec::NIGHT_CORE),
        ];
        $requirements = $this->dailyRequirements($days, self::NIGHT, self::N, 3, 3);

        if ($roomMin) {
            $requirements = [...$requirements, ...$this->dailyRequirements($days, self::ROOM, self::A2, $roomMin, $roomMin)];
        }

        $problem = $this->problem([...$core, ...$extra], $requirements,
            ['hard' => $hard, 'night_plan' => $plan, 'time_limit' => 4, 'max_iterations' => 60000]);

        return [$this->solve($problem), $problem];
    }

    private function nightCount(SolveResult $result, string $date): int
    {
        return count(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['shift_id'] === self::N));
    }

    /** 9.5: đủ nhân sự hợp lệ → Night đủ 3 người mỗi ngày. */
    public function test_night_has_three_people_every_day(): void
    {
        $flex = $this->waiter(3, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary', self::ROOM . ':' . self::A2 => 'primary'], nightRole: EmployeeSpec::NIGHT_FLEX);
        $rotation = array_map(fn ($id) => $this->waiter($id, self::VILLA, [...$this->dayShifts(self::VILLA), self::NIGHT . ':' . self::N => 'eligible']), [11, 12]);
        [$result, $problem] = $this->nightWeek([$flex, ...$rotation]);

        foreach ($this->days() as $date) {
            $this->assertSame(3, $this->nightCount($result, $date), "{$date}: Night đủ 3 người");
        }

        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 9.6: Night thiếu người do OFF → báo thiếu, không phá OFF. */
    public function test_night_short_because_of_approved_off(): void
    {
        $day = $this->day(2);
        $flex = $this->waiter(3, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary'], nightRole: EmployeeSpec::NIGHT_FLEX);
        [$result] = $this->nightWeek([$flex], hard: [3 => [$day => 'Nghỉ phép năm']]);

        $this->assertNull($this->shiftOf($result, 3, $day));
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $day && $u['shift_id'] === self::N))[0] ?? null;
        $this->assertNotNull($gap, 'Night thiếu người ngày OFF');
        $this->assertNotEmpty($gap['reasons']['approved_off']);
    }

    /** 9.7: nhân viên Room Service chuyên trách OFF → Night đa năng có năng lực Room Service thay; Night vẫn đủ nhờ luân phiên. */
    public function test_flex_covers_room_service_when_dedicated_staff_is_off(): void
    {
        $day = $this->day(1);
        $room = $this->waiter(5, self::ROOM, [self::ROOM . ':' . self::A2 => 'primary'], 'female');
        $flex = $this->waiter(3, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary', self::ROOM . ':' . self::A2 => 'eligible'], nightRole: EmployeeSpec::NIGHT_FLEX);
        $rotation = array_map(fn ($id) => $this->waiter($id, self::VILLA, [self::NIGHT . ':' . self::N => 'eligible']), [11, 12]);
        [$result] = $this->nightWeek([$room, $flex, ...$rotation], hard: [5 => [$day => 'Xin OFF']], roomMin: 1);

        $this->assertSame([self::ROOM, self::A2], $this->shiftOf($result, 3, $day), 'Night đa năng thay Room Service');
        $this->assertSame(3, $this->nightCount($result, $day), 'Night vẫn đủ 3 người');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
    }

    /** 9.8: không rút Night đa năng sang Room Service nếu Night xuống dưới tối thiểu. */
    public function test_flex_not_moved_when_night_would_drop_below_minimum(): void
    {
        $day = $this->day(1);
        $room = $this->waiter(5, self::ROOM, [self::ROOM . ':' . self::A2 => 'primary'], 'female');
        $flex = $this->waiter(3, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary', self::ROOM . ':' . self::A2 => 'primary'], nightRole: EmployeeSpec::NIGHT_FLEX);
        [$result] = $this->nightWeek([$room, $flex], hard: [5 => [$day => 'Xin OFF']], roomMin: 1);

        // Night chỉ có 2 cố định + 1 đa năng (thiếu cả tuần): người đa năng không bao giờ bị rút sang Room Service.
        $this->assertSame([], array_filter($result->assignments, fn ($r) => $r['employee_id'] === 3 && $r['outlet_id'] === self::ROOM), 'Giữ người đa năng ở Night');
        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $day && $u['outlet_id'] === self::ROOM), 'Room Service báo thiếu');
        $this->assertSame(0, $result->solverMetadata['objective']['source_shortage'] ?? 0);
    }

    /** 9.12 + 9.16: chỉ người có tên trong kế hoạch tuần được làm Night luân phiên; vẫn thuộc outlet / nhóm của mình, không trùng giờ. */
    public function test_only_planned_rotation_staff_work_night(): void
    {
        $flex = $this->waiter(3, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary'], nightRole: EmployeeSpec::NIGHT_FLEX);
        $rotation = array_map(fn ($id) => $this->waiter($id, self::VILLA, [...$this->dayShifts(self::VILLA), self::NIGHT . ':' . self::N => 'eligible']), [11, 12, 13]);
        $plan = array_fill_keys($this->days(), [11, 12]);
        [$result, $problem] = $this->nightWeek([$flex, ...$rotation], plan: $plan);

        $nights = fn (int $id) => count(array_filter($result->assignments, fn ($r) => $r['employee_id'] === $id && $r['shift_id'] === self::N));
        $this->assertSame(0, $nights(13), 'Người không được chọn tuần này không làm Night');
        $this->assertGreaterThan(0, $nights(11) + $nights(12), 'Người được chọn làm Night luân phiên');
        $this->assertSame(self::VILLA, $problem->employees[11]->homeOutletId, 'Outlet chính / nhóm hiển thị không đổi');

        foreach ($result->assignments as $row) {
            $this->assertCount(1, array_filter($result->assignments, fn ($r) => $r['employee_id'] === $row['employee_id'] && $r['date'] === $row['date']), 'Một người một ô mỗi ngày');
        }

        $this->assertHardConstraintsHold($problem, $result);
    }
}
