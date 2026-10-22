<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Lỗi quản lý báo 24/10/2026 – DỮ LIỆU GIẢ LẬP:
 * (1) Night thiếu người đầu tuần do lịch tuần trước (A2 tối Chủ nhật, đêm cuối tuần trước) – khung tuần không xét ngày liền kề;
 * (2) "OH(G)(L)", "A2(G)(L)": người Villa đứng tên ca Gusto rồi đi Lobby (lách giới hạn 1 người / outlet);
 * (3) một người 2 ca OH(L) trong tuần khi người cùng nhóm chưa có ca.
 */
class ReportedIssuesTest extends TestCase
{
    use SyntheticRoster;

    private function person(int $id, int $home, array $skills, string $gender = 'male', array $fixed = [], ?string $role = null, string $residence = 'cam_ranh'): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", $gender, $home, 48, 2, $skills, $fixed, $residence, self::WAITER,
            isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male', nightRole: $role);
    }

    /** (1) Lịch tuần trước chặn đầu tuần: vẫn đủ 3 người Night mọi ngày, có Night cứng, người luân phiên 2–3 đêm liền nhau. */
    public function test_night_full_at_start_of_week_despite_previous_week(): void
    {
        $days = $this->days();
        $sunday = '2030-01-06';
        $saturday = '2030-01-05';
        $night = [self::NIGHT . ':' . self::N => 'primary'];
        $rotationSkills = [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible'];
        $rotation = [11, 12, 13, 14];
        $people = [
            $this->person(1, self::NIGHT, $night, fixed: [self::NIGHT . ':' . self::N], role: EmployeeSpec::NIGHT_CORE),
            $this->person(2, self::NIGHT, $night, fixed: [self::NIGHT . ':' . self::N], role: EmployeeSpec::NIGHT_CORE),
            ...array_map(fn ($id) => $this->person($id, self::VILLA, $rotationSkills, role: EmployeeSpec::NIGHT_ROTATION), $rotation),
            ...array_map(fn ($id) => $this->person($id, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'], 'female'), range(50, 55)),
        ];
        // Tuần trước (đã công bố): #11 A2 tối Chủ nhật, #12 làm đêm Thứ Bảy + Chủ nhật, Night cứng #1 nghỉ Chủ nhật.
        $context = [
            new FixedAssignment(11, $sunday, FixedAssignment::KIND_SHIFT, self::VILLA, self::A2),
            new FixedAssignment(12, $saturday, FixedAssignment::KIND_SHIFT, self::NIGHT, self::N),
            new FixedAssignment(12, $sunday, FixedAssignment::KIND_SHIFT, self::NIGHT, self::N),
            new FixedAssignment(2, $sunday, FixedAssignment::KIND_SHIFT, self::NIGHT, self::N),
            new FixedAssignment(1, $sunday, FixedAssignment::KIND_OFF, null, null),
        ];
        $problem = $this->problem($people, [
            ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 3),
        ], ['fixed' => $context, 'night_plan' => array_fill_keys($days, $rotation), 'time_limit' => 90, 'max_iterations' => 150000]);
        $result = $this->solve($problem);

        foreach ($days as $date) {
            $ids = array_column(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['shift_id'] === self::N), 'employee_id');
            $this->assertCount(3, $ids, "{$date}: Night đủ 3 người");
            $this->assertNotEmpty(array_intersect($ids, [1, 2]), "{$date}: có Night cứng");
        }

        foreach ($rotation as $id) {
            $nights = array_keys(array_filter($days, fn ($d) => $this->shiftOf($result, $id, $d) === [self::NIGHT, self::N]));
            $this->assertContains(count($nights), [2, 3], "#{$id}: 2–3 đêm");
        }

        $this->assertHardConstraintsHold($problem, $result);
    }

    /**
     * (1b) Ngày OFF của Night cứng đã khóa sẵn (quản lý nhập) rơi vào thế khó: không ghép được cụm đêm liền nhau nào vừa khít.
     * Ưu tiên đủ 3 người mỗi ngày: người luân phiên được làm tới 3 đêm, có thể tách 2 cụm khi bắt buộc.
     */
    public function test_night_full_when_core_offs_are_locked_awkwardly(): void
    {
        $days = $this->days();
        $night = [self::NIGHT . ':' . self::N => 'primary'];
        $rotationSkills = [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible'];
        $rotation = [11, 12, 13, 14];
        $off = fn (int $id, int $d) => new FixedAssignment($id, $days[$d], FixedAssignment::KIND_OFF, null, null);
        $people = [
            $this->person(1, self::NIGHT, $night, fixed: [self::NIGHT . ':' . self::N], role: EmployeeSpec::NIGHT_CORE),
            $this->person(2, self::NIGHT, $night, fixed: [self::NIGHT . ':' . self::N], role: EmployeeSpec::NIGHT_CORE),
            ...array_map(fn ($id) => $this->person($id, self::VILLA, $rotationSkills, role: EmployeeSpec::NIGHT_ROTATION), $rotation),
            ...array_map(fn ($id) => $this->person($id, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'], 'female'), range(50, 55)),
        ];
        // OFF đã khóa: Night cứng #1 nghỉ T2 + T4, #2 nghỉ T3 + T6 → 4 ngày chỉ 1 Night cứng xen kẽ.
        $problem = $this->problem($people, [
            ...$this->dailyRequirements($days, self::NIGHT, self::N, 3, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 3),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 3),
        ], ['fixed' => [$off(1, 0), $off(1, 2), $off(2, 1), $off(2, 4)], 'night_plan' => array_fill_keys($days, $rotation), 'time_limit' => 90, 'max_iterations' => 150000]);
        $result = $this->solve($problem);

        foreach ($days as $date) {
            $ids = array_column(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['kind'] === 'shift' && $r['shift_id'] === self::N), 'employee_id');
            $this->assertCount(3, $ids, "{$date}: Night đủ 3 người");
        }

        foreach ($rotation as $id) {
            $this->assertLessThanOrEqual(3, count(array_filter($days, fn ($d) => $this->shiftOf($result, $id, $d) === [self::NIGHT, self::N])), "#{$id}: tối đa 3 đêm");
        }

        $this->assertHardConstraintsHold($problem, $result);
    }

    /** (2) Người hỗ trợ Lobby (L) chỉ đi từ outlet chính – không có "OH(G)(L)" / "A2(G)(L)". */
    public function test_lobby_support_only_from_home_outlet(): void
    {
        $days = $this->days();
        $villa = fn (int $id) => $this->person($id, self::VILLA, [self::VILLA . ':0' => 'primary', self::GUSTO . ':0' => 'eligible', self::LOBBY . ':0' => 'eligible'], 'female', residence: 'nha_trang');
        $gusto = fn (int $id) => $this->person($id, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary', self::VILLA . ':0' => 'eligible'], 'female');
        $requirements = [];

        foreach ([self::VILLA, self::GUSTO] as $outlet) {
            $requirements = [...$requirements, ...$this->dailyRequirements($days, $outlet, self::M6, 1, 2), ...$this->dailyRequirements($days, $outlet, self::A2, 1, 2),
                ...array_map(fn ($d) => new Requirement($d, $outlet, self::OH, 0, 0, 2), $days)];
        }

        $result = $this->solve($this->problem([...array_map($villa, range(1, 8)), ...array_map($gusto, range(11, 16))], $requirements, [
            'coverage' => array_map(fn ($d) => new Coverage($d, 540, 1005, 2, 2, 'Lobby', null, true), $days),
            'max_support' => 1, 'time_limit' => 60, 'max_iterations' => 40000,
        ]));

        $foreign = array_filter($result->assignments, fn ($r) => $r['support'] && $r['outlet_id'] !== self::VILLA);
        $this->assertSame([], array_values($foreign), 'Không có (L) đứng tên outlet khác');
    }
}
