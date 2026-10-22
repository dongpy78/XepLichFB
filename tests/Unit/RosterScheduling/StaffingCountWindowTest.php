<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\RequirementResolver;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Định biên: khung đếm cho người hỗ trợ một phần ca, người làm trọn ca ở outlet đích, chọn dòng theo hiệu lực / trạng thái,
 * xung đột cùng mức ưu tiên, nhóm vị trí có mục tiêu – DỮ LIỆU GIẢ LẬP.
 */
class StaffingCountWindowTest extends TestCase
{
    use SyntheticRoster;

    private function waitress(int $id): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", 'female', self::VILLA, 48, 2, [self::VILLA . ':0' => 'primary', self::LOBBY . ':0' => 'eligible'],
            [], 'cam_ranh', self::WAITER, isFemaleStaff: true);
    }

    private function state(?array $countWindow, array $fixed): ScheduleState
    {
        $day = $this->day(0);
        $requirement = new Requirement($day, self::VILLA, self::M6, 2, 2, 4, minFemale: 2, targetFemale: 2, countWindow: $countWindow);

        return new ScheduleState($this->problem([$this->waitress(1), $this->waitress(2)], [$requirement], ['days' => [$day], 'fixed' => $fixed]));
    }

    /** 11.4: người M6(L) lên Lobby 09–12h; khung đếm 06–09h (setup buffet) → vẫn tính cho Villa; không có khung đếm → không tính. */
    public function test_partial_lobby_support_counts_only_inside_configured_window(): void
    {
        $day = $this->day(0);
        $fixed = [
            new FixedAssignment(1, $day, FixedAssignment::KIND_SHIFT, self::VILLA, self::M6),
            new FixedAssignment(2, $day, FixedAssignment::KIND_SHIFT, self::VILLA, self::M6, support: true),
        ];

        $withWindow = $this->state([6 * 60, 9 * 60], $fixed);
        $this->assertSame(2, $withWindow->femaleCount(0, self::VILLA, self::M6), 'M6(L) có mặt ở Villa 06–09h → tính');

        $without = $this->state(null, $fixed);
        $this->assertSame(1, $without->femaleCount(0, self::VILLA, self::M6), 'Không khung đếm: người (L) không tính cho outlet');

        // Khung đếm chạm giờ ở Lobby (10–13h) → không tính.
        $overlap = $this->state([10 * 60, 13 * 60], $fixed);
        $this->assertSame(1, $overlap->femaleCount(0, self::VILLA, self::M6));
    }

    /** 11.5: người làm trọn ca ở outlet đích (M9 ở Lobby) không bao giờ được tính cho outlet gốc. */
    public function test_full_shift_at_target_outlet_is_not_counted_at_home(): void
    {
        $day = $this->day(0);
        $fixed = [
            new FixedAssignment(1, $day, FixedAssignment::KIND_SHIFT, self::VILLA, self::M6),
            new FixedAssignment(2, $day, FixedAssignment::KIND_SHIFT, self::LOBBY, self::M9),
        ];
        $state = $this->state([6 * 60, 9 * 60], $fixed);

        $this->assertSame(1, $state->femaleCount(0, self::VILLA, self::M6));
        // Villa M6 thiếu 1 người và 1 nữ waiter so với tối thiểu.
        $this->assertSame(2, $state->objectiveValues()['min_shortage']);
    }

    /** 11.9, 11.10, 11.11: chỉ dòng đang áp dụng, đúng hiệu lực; hai dòng cùng mức khác nhau → không tự chọn, ghi xung đột. */
    public function test_resolver_respects_status_effective_dates_and_conflicts(): void
    {
        $base = ['outlet_id' => self::VILLA, 'shift_template_id' => self::M6, 'weekday' => null, 'date' => null, 'upper_floor' => null, 'target_staff' => 3, 'positions' => []];
        $resolver = new RequirementResolver([
            ['id' => 1, 'status' => 'active', 'min_staff' => 2, 'effective_to' => '2030-01-09', ...$base],
            ['id' => 2, 'status' => 'active', 'min_staff' => 3, 'effective_from' => '2030-01-10', ...$base, 'target_staff' => 4],
            ['id' => 3, 'status' => 'draft', 'min_staff' => 9, ...$base, 'weekday' => 4],
        ]);

        $this->assertSame([2, 1], [$resolver->resolve('2030-01-09', self::VILLA, self::M6)->min, $resolver->resolve('2030-01-09', self::VILLA, self::M6)->sourceId], 'Trước ngày đổi: dòng cũ');
        $this->assertSame([3, 2], [$resolver->resolve('2030-01-10', self::VILLA, self::M6)->min, $resolver->resolve('2030-01-10', self::VILLA, self::M6)->sourceId], 'Từ ngày đổi: dòng mới');
        $this->assertSame([], $resolver->conflicts(), 'Bản nháp không được dùng, không gây xung đột');

        $conflicting = new RequirementResolver([
            ['id' => 5, 'status' => 'active', 'min_staff' => 2, ...$base],
            ['id' => 6, 'status' => 'active', 'min_staff' => 3, ...$base],
        ]);
        $this->assertNull($conflicting->resolve('2030-01-09', self::VILLA, self::M6), 'Không tự chọn khi mâu thuẫn');
        $this->assertSame([5, 6], array_values($conflicting->conflicts())[0]['ids']);

        $identical = new RequirementResolver([['id' => 7, 'status' => 'active', 'min_staff' => 2, ...$base], ['id' => 8, 'status' => 'active', 'min_staff' => 2, ...$base]]);
        $this->assertSame(7, $identical->resolve('2030-01-09', self::VILLA, self::M6)->sourceId, 'Hai dòng giống hệt nhau: không coi là xung đột');
    }

    /** Nhóm vị trí có tối thiểu và mục tiêu riêng; nhóm cấp quản lý dùng khóa chung. */
    public function test_position_groups_have_min_and_target(): void
    {
        $resolver = new RequirementResolver([[
            'id' => 1, 'status' => 'active', 'outlet_id' => self::VILLA, 'shift_template_id' => self::M6, 'min_staff' => 3, 'target_staff' => 5,
            'min_male' => null, 'min_female' => 2, 'target_female' => 3, 'count_start_time' => '06:00:00', 'count_end_time' => '09:00:00',
            'positions' => [['kind' => 'position', 'position_id' => 101, 'min_staff' => 1, 'target_staff' => 2], ['kind' => 'managers', 'position_id' => null, 'min_staff' => 1, 'target_staff' => null]],
        ]]);
        $r = $resolver->resolve('2030-01-09', self::VILLA, self::M6);

        $this->assertSame(['101' => 1, RequirementResolver::MANAGER_GROUP => 1], $r->positionMins);
        $this->assertSame(2, $r->positionTarget('101'));
        $this->assertSame(1, $r->positionTarget(RequirementResolver::MANAGER_GROUP), 'Mục tiêu trống = bằng tối thiểu');
        $this->assertSame([0, 2, 3], [$r->minMale, $r->minFemale, $r->targetFemale], 'Nam trống = không áp dụng');
        $this->assertSame([360, 540], $r->countWindow);
    }
}
