<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\BirthdayRule;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Kiểm thử Scheduling Engine với DỮ LIỆU GIẢ LẬP (xem SyntheticRoster).
 * Đánh số theo danh sách tình huống bắt buộc trong yêu cầu nghiệp vụ.
 */
class SchedulingEngineTest extends TestCase
{
    use SyntheticRoster;

    /** 1. Đủ nhân viên, không ai xin OFF. */
    public function test_full_staff_without_off_requests_meets_every_target(): void
    {
        $days = $this->days();
        $problem = $this->problem(
            $this->team(1, 8, self::GUSTO),
            [...$this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 2, 2)]
        );

        $result = $this->solve($problem);

        // 8 người × 5 ngày = 40 ca ≥ 28 ca cần.
        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([], $result->unfilledRequirements);
        $this->assertSame([], $result->hardConstraintViolations);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 2. Một nhân viên xin OFF và đã được duyệt. */
    public function test_approved_off_is_never_scheduled(): void
    {
        $days = $this->days();
        $problem = $this->problem(
            $this->team(1, 6, self::GUSTO),
            $this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2),
            ['hard' => [1 => [$this->day(2) => 'OFF đã duyệt (giả lập)']]]
        );

        $result = $this->solve($problem);

        $this->assertNull($this->shiftOf($result, 1, $this->day(2)));
        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 3. Nhân viên xin OFF nhưng chưa được duyệt: tránh nếu có thể, không coi là đã duyệt. */
    public function test_pending_off_is_respected_when_possible_but_not_treated_as_approved(): void
    {
        $days = $this->days();
        $soft = [1 => [$this->day(3) => 'Đơn xin OFF chờ duyệt (giả lập)']];

        // Đủ người: thuật toán tránh ngày bận.
        $roomy = $this->problem($this->team(1, 6, self::GUSTO), $this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2), ['soft' => $soft]);
        $this->assertNull($this->shiftOf($this->solve($roomy), 1, $this->day(3)));

        // Chỉ nhân viên #1 làm được ca ngày đó: vẫn được xếp (đơn chưa duyệt không chặn cứng) và được ghi nhận.
        $only = $this->problem(
            [$this->employee(1, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary'], daysOff: 1)],
            [new Requirement($this->day(3), self::GUSTO, self::M6, 1, 1)],
            ['soft' => $soft]
        );
        $result = $this->solve($only);

        $this->assertSame([self::GUSTO, self::M6], $this->shiftOf($result, 1, $this->day(3)));
        $this->assertSame(1, $result->employeeWorkload[0]['unapproved_request_conflicts']);
    }

    /** 4. Hai nhân viên cùng xin OFF một ngày (đã duyệt). */
    public function test_two_approved_offs_on_same_day_are_covered_by_others(): void
    {
        $days = $this->days();
        $hard = [1 => [$this->day(4) => 'OFF'], 2 => [$this->day(4) => 'OFF']];
        $problem = $this->problem($this->team(1, 7, self::VILLA), [...$this->dailyRequirements($days, self::VILLA, self::M6, 2, 2), ...$this->dailyRequirements($days, self::VILLA, self::A2, 2, 2)], ['hard' => $hard]);

        $result = $this->solve($problem);

        $this->assertNull($this->shiftOf($result, 1, $this->day(4)));
        $this->assertNull($this->shiftOf($result, 2, $this->day(4)));
        $this->assertCount(2, $this->staffing($result)[$this->day(4) . '|' . self::VILLA . '|' . self::M6]);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 5 & 6. Nhiều nhân viên cùng đợt đi chơi / khám sức khỏe: báo thiếu kèm lý do, không xếp người đang đi. */
    public function test_group_event_creates_explained_shortage(): void
    {
        $days = $this->days();
        $hard = [];

        foreach ([1, 2, 3, 4] as $id) {
            $hard[$id][$this->day(2)] = 'Đi chơi đợt 2 (giả lập)';
        }

        $problem = $this->problem($this->team(1, 5, self::GUSTO), $this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2), ['hard' => $hard]);
        $result = $this->solve($problem);

        $shortage = $this->findUnfilled($result, $this->day(2), self::GUSTO, self::M6);
        $this->assertNotNull($shortage);
        $this->assertSame('min', $shortage['level']);
        $this->assertTrue($shortage['provably_impossible'], 'Chỉ còn 1 người không đi chơi < tối thiểu 2');
        $this->assertCount(4, $shortage['reasons']['approved_off']);
        $this->assertSame(SolveResult::INFEASIBLE, $result->status);
        $this->assertContains('keep_warning', array_column($shortage['proposals'], 'type'));
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 7. Gusto chỉ còn một nam đủ điều kiện: đạt tối thiểu 1 nam, báo thiếu mục tiêu 2 nam và gợi ý giảm định biên. */
    public function test_gusto_with_single_male_reports_target_gap_and_reduce_option(): void
    {
        $days = [$this->day(0)];
        $employees = [
            $this->employee(1, self::GUSTO, [self::GUSTO . ':' . self::A2 => 'primary'], 'male'),
            ...array_map(fn ($id) => $this->employee($id, self::GUSTO, [self::GUSTO . ':' . self::A2 => 'primary'], 'female'), [2, 3, 4]),
        ];
        $problem = $this->problem($employees, [new Requirement($days[0], self::GUSTO, self::A2, 3, 4, null, 1, 2, true)], ['days' => $days]);

        $result = $this->solve($problem);

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([self::GUSTO, self::A2], $this->shiftOf($result, 1, $days[0]), 'Nam duy nhất phải được xếp');
        $gap = $this->findUnfilled($result, $days[0], self::GUSTO, self::A2);
        $this->assertSame('target', $gap['level']);
        $this->assertSame(1, $gap['assigned_male']);
        $this->assertContains('reduce', array_column($gap['proposals'], 'type'));
    }

    /** 10 & 11. Nhân viên Room nghỉ: lấy người Night đủ điều kiện nếu ca N vẫn đủ. */
    public function test_room_absence_is_covered_from_night_without_breaking_night(): void
    {
        $day = $this->day(0);
        $room = fn (int $shift) => self::ROOM . ':' . $shift;
        $employees = [
            $this->employee(1, self::ROOM, [$room(self::M6) => 'primary'], 'male'),           // Room sáng – nghỉ
            $this->employee(2, self::ROOM, [$room(self::A2) => 'primary'], 'male'),           // Room chiều
            $this->employee(3, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary', $room(self::M6) => 'eligible'], 'male'),
            $this->employee(4, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary'], 'male'),
        ];
        $requirements = [
            new Requirement($day, self::ROOM, self::M6, 1, 1, null, 1, 1),
            new Requirement($day, self::ROOM, self::A2, 1, 1, null, 1, 1),
            new Requirement($day, self::NIGHT, self::N, 1, 1),
        ];

        // Night còn #4 giữ ca N → #3 được điều sang Room sáng.
        $result = $this->solve($this->problem($employees, $requirements, ['days' => [$day], 'hard' => [1 => [$day => 'Nghỉ ốm (giả lập)']]]));
        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([self::ROOM, self::M6], $this->shiftOf($result, 3, $day));
        $this->assertSame([self::NIGHT, self::N], $this->shiftOf($result, 4, $day));

        // #4 cũng nghỉ: #3 phải giữ ca N, Room sáng báo thiếu và giải thích.
        $result = $this->solve($this->problem($employees, $requirements, ['days' => [$day], 'hard' => [1 => [$day => 'Nghỉ ốm'], 4 => [$day => 'OFF đã duyệt']]]));
        $this->assertSame([self::NIGHT, self::N], $this->shiftOf($result, 3, $day));
        $gap = $this->findUnfilled($result, $day, self::ROOM, self::M6);
        $this->assertSame('min', $gap['level']);
        $this->assertNotEmpty($gap['reasons']['busy_elsewhere'], 'Phải nêu #3 đang giữ ca N và nơi đó sẽ thiếu nếu điều đi');
    }

    /** 12. Nhân viên Night nghỉ nhưng ca N vẫn cần đủ người: dùng người thay thế đủ điều kiện. */
    public function test_night_absence_is_filled_by_eligible_substitute(): void
    {
        $day = $this->day(0);
        $employees = [
            $this->employee(1, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary']),
            $this->employee(2, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary']),
            $this->employee(3, self::VILLA, [self::VILLA . ':' . self::A2 => 'primary', self::NIGHT . ':' . self::N => 'eligible']),
            $this->employee(4, self::VILLA, [self::VILLA . ':' . self::A2 => 'primary']),
        ];
        $problem = $this->problem($employees, [new Requirement($day, self::NIGHT, self::N, 2, 2)], ['days' => [$day], 'hard' => [1 => [$day => 'OFF đã duyệt']]]);

        $result = $this->solve($problem);

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([self::NIGHT, self::N], $this->shiftOf($result, 3, $day));
        $this->assertNull($this->shiftOf($result, 4, $day), '#4 không đủ điều kiện ca N');
    }

    /**
     * 13. Nhân viên chuyên trách M6 (ca cứng M6) nghỉ: người thay thế đủ điều kiện nhận ca.
     * Người thay thế không có ca cứng nên được xen kẽ M6/A2 (quy định 15/10); người ca cứng chỉ làm M6.
     */
    public function test_dedicated_m6_absence_uses_substitute_and_prefers_dedicated_otherwise(): void
    {
        $days = $this->days();
        $g = fn (int $shift) => self::GUSTO . ':' . $shift;
        $employees = [
            $this->employee(1, self::GUSTO, [$g(self::M6) => 'primary'], fixed: [$g(self::M6)]),
            $this->employee(4, self::GUSTO, [$g(self::M6) => 'primary'], fixed: [$g(self::M6)]),
            $this->employee(2, self::GUSTO, [$g(self::A2) => 'primary', $g(self::M6) => 'eligible']),
            $this->employee(5, self::GUSTO, [$g(self::A2) => 'primary']),
        ];
        $absence = $this->day(3);
        $problem = $this->problem(
            $employees,
            [...$this->dailyRequirements($days, self::GUSTO, self::M6, 1, 1), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 1, 1)],
            ['hard' => [1 => [$absence => 'OFF đã duyệt'], 4 => [$absence => 'OFF đã duyệt']]]
        );

        $result = $this->solve($problem);
        $m6 = array_filter($this->staffing($result), fn ($key) => str_ends_with($key, '|' . self::GUSTO . '|' . self::M6), ARRAY_FILTER_USE_KEY);

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([2], $m6["{$absence}|" . self::GUSTO . '|' . self::M6], 'Ngày cả hai người M6 nghỉ: người thay thế đủ điều kiện nhận ca');

        // Người chuyên trách (ca cứng) chỉ làm M6; người thay thế xen kẽ, nhưng phần lớn ca M6 vẫn do người chuyên trách.
        $byEmployee = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift') {
                $byEmployee[$row['employee_id']][$row['shift_id']] = ($byEmployee[$row['employee_id']][$row['shift_id']] ?? 0) + 1;
            }
        }

        $this->assertSame([self::M6], array_keys($byEmployee[1]));
        $this->assertSame([self::M6], array_keys($byEmployee[4]));
        $this->assertGreaterThanOrEqual(5, $byEmployee[1][self::M6] + $byEmployee[4][self::M6]);
        $this->assertArrayHasKey(self::A2, $byEmployee[2], 'Người thay thế không có ca cứng vẫn làm ca A2 sở trường');

        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 14. Ô đã khóa tự mâu thuẫn (ca đêm rồi ca sáng ngay sau): báo không hợp lệ, không âm thầm bỏ qua. */
    public function test_locked_assignments_that_conflict_are_reported_as_infeasible(): void
    {
        $days = $this->days(2);
        $problem = $this->problem(
            [$this->employee(1, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary', self::NIGHT . ':' . self::M6 => 'eligible'])],
            [],
            ['days' => $days, 'fixed' => [$this->locked(1, $days[0], self::NIGHT, self::N), $this->locked(1, $days[1], self::NIGHT, self::M6)]]
        );

        $result = $this->solve($problem);

        $this->assertSame(SolveResult::INFEASIBLE, $result->status);
        $this->assertSame('rest', $result->hardConstraintViolations[0]['code']);
    }

    /** 15. Nhân viên không đủ năng lực không bao giờ bị xếp, kể cả khi thiếu người. */
    public function test_ineligible_employee_is_never_used_even_when_short(): void
    {
        $day = $this->day(0);
        $problem = $this->problem(
            [$this->employee(1, self::VILLA, [self::VILLA . ':' . self::M6 => 'primary'])],
            [new Requirement($day, self::GUSTO, self::M6, 1, 1)],
            ['days' => [$day]]
        );

        $result = $this->solve($problem);

        $this->assertNull($this->shiftOf($result, 1, $day));
        $gap = $this->findUnfilled($result, $day, self::GUSTO, self::M6);
        $this->assertSame(0, $gap['eligible_total']);
        $this->assertTrue($gap['provably_impossible']);
    }

    /** 16. Hết ca đêm không đủ giờ nghỉ để nhận ca sáng hôm sau. */
    public function test_night_followed_by_morning_is_never_created(): void
    {
        $days = $this->days(2);
        $problem = $this->problem(
            [$this->employee(1, self::NIGHT, [self::NIGHT . ':' . self::N => 'primary', self::NIGHT . ':' . self::M6 => 'eligible'], daysOff: 0)],
            [new Requirement($days[0], self::NIGHT, self::N, 1, 1), new Requirement($days[1], self::NIGHT, self::M6, 1, 1)],
            ['days' => $days]
        );

        $result = $this->solve($problem);

        $this->assertFalse($this->shiftOf($result, 1, $days[0]) && $this->shiftOf($result, 1, $days[1]), 'N rồi M6 chỉ nghỉ 0 giờ');
        $this->assertNotEmpty($result->unfilledRequirements);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** A2 → M6 (nghỉ 8h) được phép nhưng bị hạn chế: đủ người thì không tạo ca nặng. */
    public function test_heavy_transition_is_avoided_when_staff_allows(): void
    {
        $days = $this->days();
        $problem = $this->problem(
            $this->team(1, 8, self::GUSTO),
            [...$this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 2, 2)],
            // Giới hạn theo số bước (không theo giây) để kết quả giống nhau trên mọi máy, kể cả khi chạy chung toàn bộ test.
            ['time_limit' => 60, 'max_iterations' => 80000]
        );

        $result = $this->solve($problem);

        // OFF liền nhau trong cùng tuần là bắt buộc (Chủ nhật OFF chỉ ghép với thứ Bảy) và xếp trên ca nặng:
        // 4 người buộc OFF Chủ nhật nên đôi khi còn 1 ca nặng.
        $this->assertSame(0, $result->softConstraintScores['objective']['off_split']);
        $this->assertLessThanOrEqual(1, $result->softConstraintScores['objective']['heavy_transitions']);
    }

    /** 17. Ô đã khóa được giữ nguyên. */
    public function test_locked_assignment_is_kept(): void
    {
        $days = $this->days();
        $problem = $this->problem(
            $this->team(1, 6, self::GUSTO),
            $this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2),
            ['fixed' => [$this->locked(3, $this->day(1), self::GUSTO, self::A2), $this->locked(4, $this->day(2), null, null, FixedAssignment::KIND_OFF)]]
        );

        $result = $this->solve($problem);

        $this->assertSame([self::GUSTO, self::A2], $this->shiftOf($result, 3, $this->day(1)));
        $this->assertNull($this->shiftOf($result, 4, $this->day(2)));
    }

    /** 18. Thiếu người, không còn phương án hợp lệ: chỉ còn giữ cảnh báo. */
    public function test_shortage_without_any_valid_alternative(): void
    {
        $day = $this->day(0);
        $problem = $this->problem(
            [$this->employee(1, self::GUSTO, [self::GUSTO . ':' . self::A2 => 'primary'])],
            [new Requirement($day, self::GUSTO, self::A2, 2, 2, null, 0, 0, false)],
            ['days' => [$day]]
        );

        $result = $this->solve($problem);

        $gap = $this->findUnfilled($result, $day, self::GUSTO, self::A2);
        $this->assertSame(1, $gap['missing']);
        $this->assertSame(['keep_warning'], array_column($gap['proposals'], 'type'));
        $this->assertNotEmpty($result->explanation);
    }

    /** 20. Tối ưu lại sau khi một nhân viên nghỉ đột xuất: chỉ đổi phần cần thiết. */
    public function test_reoptimization_after_sudden_absence_changes_little(): void
    {
        $days = $this->days();
        $employees = $this->team(1, 8, self::GUSTO);
        $requirements = [...$this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 2, 2)];
        $first = $this->solve($this->problem($employees, $requirements));
        $baseline = $this->baselineFrom($first);

        $victim = array_values(array_filter($first->assignments, fn ($r) => $r['kind'] === 'shift' && $r['date'] === $this->day(3)))[0];
        $result = $this->solve($this->problem($employees, $requirements, [
            'baseline' => $baseline,
            'stability_first' => true,
            'hard' => [$victim['employee_id'] => [$this->day(3) => 'Nghỉ đột xuất (giả lập)']],
        ]));

        $this->assertNull($this->shiftOf($result, $victim['employee_id'], $this->day(3)));
        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $changes = count(array_filter($result->assignments, fn ($r) => $r['changed']));
        // Bù ngày trống mà vẫn giữ OFF liền nhau trong tuần thường phải dời cặp OFF của 2 người.
        $this->assertLessThanOrEqual(8, $changes, "Quá nhiều thay đổi: {$changes}");
    }

    /** 21. Cân bằng công bằng: nhân viên đồng nhất có số ngày làm gần như nhau. */
    public function test_identical_employees_receive_balanced_workload(): void
    {
        $days = $this->days(14);
        $problem = $this->problem(
            $this->team(1, 10, self::VILLA),
            [...$this->dailyRequirements($days, self::VILLA, self::M6, 3, 3), ...$this->dailyRequirements($days, self::VILLA, self::A2, 3, 3)],
            ['days' => $days, 'time_limit' => 8]
        );

        $result = $this->solve($problem);
        $worked = array_column($result->employeeWorkload, 'worked_days');

        $this->assertLessThanOrEqual(1, max($worked) - min($worked), 'Chênh lệch ngày làm: ' . implode(',', $worked));
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** 22. Tối ưu lại lịch đã hợp lệ không làm thay đổi phân công không cần thiết. */
    public function test_reoptimizing_a_valid_schedule_keeps_it_unchanged(): void
    {
        $days = $this->days();
        $employees = $this->team(1, 8, self::GUSTO);
        $requirements = [...$this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 2, 2)];
        $first = $this->solve($this->problem($employees, $requirements));

        $again = $this->solve($this->problem($employees, $requirements, ['baseline' => $this->baselineFrom($first), 'seed' => 99, 'stability_first' => true]));

        $this->assertSame(0, count(array_filter($again->assignments, fn ($r) => $r['changed'])));
    }

    /** Cùng dữ liệu + cùng seed → cùng kết quả (để tái hiện tình huống). */
    public function test_solver_is_deterministic(): void
    {
        $days = $this->days();
        $problem = fn () => $this->problem($this->team(1, 8, self::GUSTO), $this->dailyRequirements($days, self::GUSTO, self::M6, 2, 3), ['time_limit' => 60, 'max_iterations' => 3000]);

        $this->assertSame($this->solve($problem())->assignments, $this->solve($problem())->assignments);
    }

    /** Không tự ép tăng ca: hết quota ngày làm thì chỉ đề xuất, không xếp. */
    public function test_overtime_is_proposed_not_forced(): void
    {
        $days = $this->days();
        $problem = $this->problem(
            [$this->employee(1, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary'], daysOff: 2)],
            $this->dailyRequirements($days, self::GUSTO, self::M6, 1, 1)
        );

        $result = $this->solve($problem);

        $this->assertSame(5, $result->employeeWorkload[0]['worked_days']);
        $types = array_merge(...array_map(fn ($u) => array_column($u['proposals'], 'type'), $result->unfilledRequirements));
        $this->assertContains('extra_shift', $types);
    }

    /** Quy định sinh nhật: OFF đúng ngày sinh nhật trong phạm vi xếp. */
    public function test_birthday_rule_finds_birthday_dates(): void
    {
        $days = $this->days(14);

        $this->assertSame([$this->day(2)], BirthdayRule::offDates('9/1', $days), '09/01/2030 là ngày thứ 3 trong phạm vi');
        $this->assertSame([], BirthdayRule::offDates('28/10', $days));
        $this->assertSame([], BirthdayRule::offDates(null, $days));
        $this->assertSame([], BirthdayRule::offDates('không hợp lệ', $days));
    }

    /** OFF nên liền nhau 2–3 ngày: không có ngày OFF lẻ giữa tuần và không cụm OFF quá 3 ngày. */
    public function test_off_days_are_grouped_into_two_or_three_day_blocks(): void
    {
        $days = $this->days(14);
        $problem = $this->problem(
            $this->team(1, 10, self::VILLA),
            [...$this->dailyRequirements($days, self::VILLA, self::M6, 3, 3), ...$this->dailyRequirements($days, self::VILLA, self::A2, 3, 3)],
            ['days' => $days, 'time_limit' => 60, 'max_iterations' => 80000]
        );

        $result = $this->solve($problem);
        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertHardConstraintsHold($problem, $result);

        $offByEmployee = [];

        foreach ($result->assignments as $row) {
            $offByEmployee[$row['employee_id']][$row['date']] = $row['kind'] !== 'shift';
        }

        foreach ($offByEmployee as $employeeId => $cells) {
            ksort($cells);
            $flags = array_values($cells);
            $run = 0;

            foreach ($flags as $k => $off) {
                $run = $off ? $run + 1 : 0;
                $endsHere = $off && (! isset($flags[$k + 1]) || ! $flags[$k + 1]);
                $touchesEdge = $k - $run + 1 === 0 || ! isset($flags[$k + 1]);

                if ($endsHere && ! $touchesEdge) {
                    $this->assertGreaterThanOrEqual(2, $run, "#{$employeeId} có ngày OFF lẻ: " . implode('', array_map(fn ($f) => $f ? 'O' : '.', $flags)));
                    $this->assertLessThanOrEqual(3, $run, "#{$employeeId} OFF dồn quá 3 ngày: " . implode('', array_map(fn ($f) => $f ? 'O' : '.', $flags)));
                }
            }
        }
    }

    /** Ngày sinh nhật (truyền như ngày nghỉ đã duyệt) được tính vào số ngày OFF của tuần, không cộng thêm. */
    public function test_birthday_off_counts_towards_weekly_days_off(): void
    {
        $days = $this->days();
        $problem = $this->problem(
            // 7 người × 5 ngày = 35 ca = đúng nhu cầu 5 người/ngày × 7 ngày: ai cũng phải làm đủ 5 ngày.
            $this->team(1, 7, self::GUSTO),
            $this->dailyRequirements($days, self::GUSTO, self::M6, 5, 5),
            ['hard' => [1 => [$this->day(3) => 'Sinh nhật (giả lập) – OFF theo quy định']], 'regular_off' => [1 => [$this->day(3) => true]]]
        );

        $result = $this->solve($problem);
        $row = $result->employeeWorkload[array_search(1, array_column($result->employeeWorkload, 'employee_id'), true)];

        $this->assertNull($this->shiftOf($result, 1, $this->day(3)));
        $this->assertSame(5, $row['worked_days'], 'Sinh nhật là 1 trong 2 ngày OFF – vẫn làm đủ 5 ngày');
    }

    /** Ca cứng A2: xếp tự động không bao giờ đưa sang M6, kể cả khi M6 thiếu người. */
    public function test_fixed_shift_employee_is_only_auto_scheduled_on_fixed_shift(): void
    {
        $days = $this->days();
        $g = fn (int $shift) => self::GUSTO . ':' . $shift;
        $problem = $this->problem(
            [$this->employee(1, self::GUSTO, [$g(self::M6) => 'primary', $g(self::A2) => 'primary'], fixed: [$g(self::A2)])],
            [...$this->dailyRequirements($days, self::GUSTO, self::M6, 1, 1), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 0, 1)]
        );

        $result = $this->solve($problem);

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift') {
                $this->assertSame(self::A2, $row['shift_id'], 'Ca cứng A2 bị xếp sang ca khác');
            }
        }
    }

    /** Quản lý xếp tay (ô khóa) khác ca cứng: không phải vi phạm, các quy tắc nghỉ vẫn được kiểm tra. */
    public function test_manager_override_of_fixed_shift_is_not_a_violation(): void
    {
        $days = $this->days(2);
        $g = fn (int $shift) => self::GUSTO . ':' . $shift;
        $employee = $this->employee(1, self::GUSTO, [$g(self::A2) => 'primary'], fixed: [$g(self::A2)]);

        $override = $this->solve($this->problem([$employee], [], ['days' => $days, 'fixed' => [$this->locked(1, $days[0], self::GUSTO, self::M6)]]));
        $this->assertSame([], $override->hardConstraintViolations);

        // Nhưng ca đêm rồi ca sáng hôm sau vẫn bị báo, dù cả hai ô do quản lý khóa.
        $tooShortRest = $this->solve($this->problem([$employee], [], ['days' => $days, 'fixed' => [
            $this->locked(1, $days[0], self::NIGHT, self::N),
            $this->locked(1, $days[1], self::GUSTO, self::M6),
        ]]));
        $this->assertSame('rest', $tooShortRest->hardConstraintViolations[0]['code'] ?? null);
    }

    /** Không có ca cứng: mỗi tuần xen kẽ ca sáng / chiều (không toàn M6, không toàn A2), không tạo ca nặng. */
    public function test_employees_without_fixed_shift_alternate_morning_and_afternoon(): void
    {
        $days = $this->days(14);
        $g = fn (int $shift) => self::GUSTO . ':' . $shift;
        $team = [
            ...$this->team(1, 8, self::GUSTO),
            // #9 ca cứng A2: được làm toàn A2.
            $this->employee(9, self::GUSTO, [$g(self::M6) => 'primary', $g(self::A2) => 'primary'], fixed: [$g(self::A2)]),
        ];

        $result = $this->solve($this->problem(
            $team,
            [...$this->dailyRequirements($days, self::GUSTO, self::M6, 3, 3), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 3, 3)],
            ['days' => $days, 'time_limit' => 60, 'max_iterations' => 60000]
        ));

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        // Chia đều sáng/chiều (lệch ≤ 1) xếp trên ca nặng: ca A2 ở đây vừa đủ 2 ca/người nên đôi khi phải có ca nặng.
        $this->assertSame(0, $result->softConstraintScores['objective']['shift_mix'], 'Mỗi người mỗi tuần lệch sáng/chiều tối đa 1 ca');
        $this->assertLessThanOrEqual(6, $result->softConstraintScores['objective']['heavy_transitions'], 'Hạn chế ca nặng A2 → M6');
        $this->assertSame(0, $result->softConstraintScores['objective']['off_split'], 'OFF 2 ngày phải liền nhau: ' . json_encode(array_map(fn ($w) => [$w['employee_id'], $w['off_split'], $w['off_blocks'], $w['off_days']], $result->employeeWorkload)));

        $byWeek = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift') {
                $week = intdiv((int) ((strtotime($row['date']) - strtotime(self::MONDAY)) / 86400), 7);
                $byWeek[$row['employee_id']][$week][$row['shift_id']] = ($byWeek[$row['employee_id']][$week][$row['shift_id']] ?? 0) + 1;
            }
        }

        foreach ($byWeek as $employeeId => $weeks) {
            foreach ($weeks as $week => $counts) {
                $m6 = $counts[self::M6] ?? 0;
                $a2 = $counts[self::A2] ?? 0;

                if ($employeeId === 9) {
                    $this->assertSame(0, $m6, 'Ca cứng A2 không bị ép xen kẽ');

                    continue;
                }

                $this->assertGreaterThan(0, $m6, "#{$employeeId} tuần {$week}: toàn A2");
                $this->assertGreaterThan(0, $a2, "#{$employeeId} tuần {$week}: toàn M6");
                $this->assertLessThanOrEqual(1, abs($m6 - $a2), "#{$employeeId} tuần {$week}: M6 {$m6} / A2 {$a2} lệch quá nhiều");
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function findUnfilled(SolveResult $result, string $date, int $outlet, int $shift): ?array
    {
        foreach ($result->unfilledRequirements as $row) {
            if ($row['date'] === $date && $row['outlet_id'] === $outlet && $row['shift_id'] === $shift) {
                return $row;
            }
        }

        return null;
    }

    private function baselineFrom(SolveResult $result): array
    {
        $baseline = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift') {
                $baseline[$row['employee_id']][$row['date']] = "{$row['outlet_id']}|{$row['shift_id']}";
            }
        }

        return $baseline;
    }
}
