<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Quy tắc Night và Room Service (quản lý 23/10/2026) – DỮ LIỆU GIẢ LẬP:
 * Night đúng 3 người / ngày, ≥ 1 Night cứng / ngày; 4 người luân phiên × 2 đêm liền nhau (3 khi cần), hôm sau OFF;
 * Room Service: chuyên trách từng ca, người dự phòng thay trước, chỉ người đã xác nhận kỹ năng mới được thay.
 */
class NightRotationRulesTest extends TestCase
{
    use SyntheticRoster;

    private const ROTATION = [11, 12, 13, 14];

    private function person(int $id, int $home, array $skills, string $gender = 'male', array $fixed = [], ?string $role = null): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", $gender, $home, 48, 2, $skills, $fixed, 'cam_ranh', self::WAITER,
            isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male', nightRole: $role);
    }

    private function core(int $id): EmployeeSpec
    {
        return $this->person($id, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary'], fixed: [self::NIGHT . ':' . self::N], role: EmployeeSpec::NIGHT_CORE);
    }

    private function rotation(int $id): EmployeeSpec
    {
        return $this->person($id, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible'],
            role: EmployeeSpec::NIGHT_ROTATION);
    }

    /**
     * Tuần chuẩn: 2 Night cứng, 4 người luân phiên đã duyệt, Villa M6 / A2 tối thiểu 2 với 6 nữ waiter.
     *
     * @return array{0: SolveResult, 1: \Botble\RestaurantRoster\Scheduling\Problem}
     */
    private function week(array $extra = [], array $options = [], array $requirements = []): array
    {
        $days = $this->days();
        $villa = array_map(fn ($id) => $this->person($id, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'], 'female'), range(50, 55));
        $problem = $this->problem([$this->core(1), $this->core(2), ...array_map(fn ($id) => $this->rotation($id), self::ROTATION), ...$villa, ...$extra], [
            ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 3),
            ...$requirements,
        ], ['night_plan' => array_fill_keys($days, self::ROTATION), 'time_limit' => 90, 'max_iterations' => 150000, ...$options]);

        return [$this->solve($problem), $problem];
    }

    private function nightIds(SolveResult $result, string $date): array
    {
        return array_values(array_map(fn ($r) => $r['employee_id'], array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['shift_id'] === self::N)));
    }

    /** 9.2–9.6 + 9.18: đúng 3 / ngày, ≥ 1 Night cứng; 2 Night cứng → 1 luân phiên, 1 Night cứng → 2; mỗi người 2–3 đêm liền nhau rồi OFF. */
    public function test_three_per_night_with_core_and_rotation_blocks(): void
    {
        [$result, $problem] = $this->week();
        $days = $this->days();

        foreach ($days as $date) {
            $ids = $this->nightIds($result, $date);
            $cores = count(array_intersect($ids, [1, 2]));
            $this->assertCount(3, $ids, "{$date}: Night đúng 3 người");
            $this->assertGreaterThanOrEqual(1, $cores, "{$date}: có ít nhất 1 Night cứng");
            $this->assertSame(3 - $cores, count(array_intersect($ids, self::ROTATION)), "{$date}: phần còn lại là người luân phiên");
        }

        foreach (self::ROTATION as $id) {
            $nights = array_keys(array_filter($days, fn ($d) => in_array($id, $this->nightIds($result, $d), true)));
            $this->assertContains(count($nights), [2, 3], "#{$id}: 2–3 đêm");
            $this->assertSame(count($nights) - 1, end($nights) - $nights[0], "#{$id}: các đêm liền nhau");

            if (isset($days[end($nights) + 1])) {
                $this->assertNull($this->shiftOf($result, $id, $days[end($nights) + 1]), "#{$id}: hôm sau đêm cuối OFF");
            }
        }

        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 9.4 + 9.8: hai Night cứng cùng OFF đã duyệt một ngày → giữ OFF, báo rõ "không có Night cứng" kèm lý do. */
    public function test_day_without_core_is_reported_and_off_kept(): void
    {
        $day = $this->day(3);
        [$result] = $this->week(options: ['hard' => [1 => [$day => 'Nghỉ phép năm'], 2 => [$day => 'Xin OFF']]]);

        $this->assertSame([], array_intersect($this->nightIds($result, $day), [1, 2]), 'Không phá OFF đã duyệt');
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['date'] === $day && $u['shift_code'] === 'Night cứng'))[0] ?? null;
        $this->assertNotNull($gap, 'Cảnh báo ngày không có Night cứng');
        $this->assertCount(2, $gap['reasons']['approved_off']);
        $this->assertTrue($gap['provably_impossible']);
    }

    /** 9.8: người luân phiên có OFF đã duyệt / ô khóa → giữ nguyên, không xếp Night ngày đó. */
    public function test_rotation_off_and_locked_cells_are_kept(): void
    {
        $day = $this->day(1);
        [$result] = $this->week(options: [
            'hard' => [11 => [$day => 'Xin OFF']],
            'fixed' => [new FixedAssignment(12, $day, FixedAssignment::KIND_SHIFT, self::VILLA, self::M6)],
        ]);

        $this->assertNull($this->shiftOf($result, 11, $day));
        $this->assertSame([self::VILLA, self::M6], $this->shiftOf($result, 12, $day));
    }

    /** 9.11 + 9.12: Room Service chuyên trách M6 / A2 OFF → người dự phòng thay trước người chỉ đủ kỹ năng; không ai khác thay. */
    public function test_room_service_backup_replaces_lead_before_substitute(): void
    {
        $days = $this->days();
        [$m6Off, $a2Off] = [$this->day(1), $this->day(4)];
        $leadM6 = $this->person(30, self::ROOM, [self::ROOM . ':' . self::M6 => 'primary'], fixed: [self::ROOM . ':' . self::M6]);
        $leadA2 = $this->person(31, self::ROOM, [self::ROOM . ':' . self::A2 => 'primary'], fixed: [self::ROOM . ':' . self::A2]);
        $backup = $this->person(32, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary', self::ROOM . ':0' => 'primary']);
        $substitute = $this->person(33, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary', self::ROOM . ':0' => 'eligible']);
        $unskilled = array_map(fn ($id) => $this->person($id, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary']), [34, 35, 36, 37]);

        [$result] = $this->week([$leadM6, $leadA2, $backup, $substitute, ...$unskilled], ['hard' => [30 => [$m6Off => 'Xin OFF'], 31 => [$a2Off => 'Xin OFF']]], [
            ...$this->dailyRequirements($days, self::ROOM, self::M6, 1, 1),
            ...$this->dailyRequirements($days, self::ROOM, self::A2, 1, 1),
            ...$this->dailyRequirements($days, self::GUSTO, self::M6, 1, 2),
            ...$this->dailyRequirements($days, self::GUSTO, self::A2, 1, 2),
        ]);

        $this->assertSame([self::ROOM, self::M6], $this->shiftOf($result, 32, $m6Off), 'Dự phòng thay Room Service M6');
        $this->assertSame([self::ROOM, self::A2], $this->shiftOf($result, 32, $a2Off), 'Dự phòng thay Room Service A2');
        $this->assertSame([], array_filter($result->assignments, fn ($r) => $r['outlet_id'] === self::ROOM && $r['employee_id'] >= 34), 'Người chưa xác nhận kỹ năng không thay Room Service');
        $this->assertSame([], array_filter($result->assignments, fn ($r) => in_array($r['employee_id'], [30, 31], true) && $r['kind'] === 'shift' && $r['outlet_id'] !== self::ROOM), 'Chuyên trách chỉ làm Room Service');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::ROOM));
    }

    /** 9.10: Night và Room Service cùng cần một người (dự phòng Room Service đang trong tuần luân phiên) → không xếp hai nơi, cả hai vẫn đủ. */
    public function test_night_and_room_service_do_not_share_one_person(): void
    {
        $days = $this->days();
        $off = $this->day(2);
        $lead = $this->person(30, self::ROOM, [self::ROOM . ':' . self::M6 => 'primary'], fixed: [self::ROOM . ':' . self::M6]);
        $backupRotation = $this->person(11, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary',
            self::NIGHT . ':' . self::N => 'eligible', self::ROOM . ':0' => 'primary'], role: EmployeeSpec::NIGHT_ROTATION);
        $substitute = $this->person(33, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary', self::ROOM . ':0' => 'eligible']);
        $villa = array_map(fn ($id) => $this->person($id, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'], 'female'), range(50, 55));

        $problem = $this->problem([$this->core(1), $this->core(2), $backupRotation, ...array_map(fn ($id) => $this->rotation($id), [12, 13, 14]), $lead, $substitute, ...$villa], [
            ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3),
            ...$this->dailyRequirements($days, self::ROOM, self::M6, 1, 1),
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 3),
        ], ['night_plan' => array_fill_keys($days, self::ROTATION), 'hard' => [30 => [$off => 'Xin OFF']], 'time_limit' => 90, 'max_iterations' => 150000]);
        $result = $this->solve($problem);

        $room = array_filter($result->assignments, fn ($r) => $r['date'] === $off && $r['outlet_id'] === self::ROOM);
        $this->assertCount(1, $room, 'Room Service M6 có người thay');
        $this->assertCount(3, $this->nightIds($result, $off), 'Night vẫn đủ 3');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
        $this->assertHardConstraintsHold($problem, $result);
    }
}
