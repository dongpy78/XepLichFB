<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\LobbyCoverage;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Quy tắc định biên Lobby Bar (chốt 22/10/2026): tối thiểu 2 nữ waiter CÓ MẶT CÙNG LÚC trong suốt 09:00–16:45.
 * Ưu tiên OH(L) 08:45–16:45 (Nha Trang) + M9(L) 09:00–17:00 (Cam Ranh); thay thế M6(L) 09–14h, A2(L) 14–17h
 * chỉ được chấp nhận khi đủ 2 người ở mọi đoạn giờ – DỮ LIỆU GIẢ LẬP.
 */
class LobbyConcurrencyTest extends TestCase
{
    use SyntheticRoster;

    private const CORE_START = 9 * 60;

    private const CORE_END = 16 * 60 + 45;

    private function waitress(int $id, int $home, array $skills, ?string $residence = null, bool $femaleStaff = true): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", 'female', $home, 48, 2, $skills + [self::LOBBY . ':0' => 'eligible'], [], $residence, self::WAITER,
            isFemaleStaff: $femaleStaff);
    }

    /** @return Coverage[] */
    private function lobbyRule(string $day): array
    {
        return [new Coverage($day, self::CORE_START, self::CORE_END, 2, 2, 'Lobby', null, true)];
    }

    /** Các đoạn giờ Lobby báo thiếu: [[bắt đầu, kết thúc], …]. */
    private function lobbyGaps(SolveResult $result): array
    {
        $gaps = array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::LOBBY && isset($u['lobby']));

        return array_values(array_map(fn ($u) => [$u['lobby']['segment_start'], $u['lobby']['segment_end']], $gaps));
    }

    /**
     * Lịch đã khóa sẵn (quản lý xếp) cho một ngày → báo cáo thiếu người Lobby.
     *
     * @param array<int, array{0: EmployeeSpec, 1: int, 2: int, 3: bool}> $cells [nhân viên, outlet, ca, (L)]
     */
    private function evaluateLocked(array $cells): SolveResult
    {
        $day = $this->day(0);
        $employees = array_map(fn ($cell) => $cell[0], $cells);
        $fixed = array_map(fn ($cell) => new FixedAssignment($cell[0]->id, $day, FixedAssignment::KIND_SHIFT, $cell[1], $cell[2], support: $cell[3]), $cells);

        return $this->solve($this->problem($employees, [new Requirement($day, self::LOBBY, self::M9, 0, 0, 2)],
            ['days' => [$day], 'fixed' => $fixed, 'coverage' => $this->lobbyRule($day), 'max_support' => 1]));
    }

    private function oh(int $id = 1): EmployeeSpec
    {
        return $this->waitress($id, self::VILLA, [self::VILLA . ':' . self::OH => 'primary'], 'nha_trang');
    }

    private function m9(int $id = 2): EmployeeSpec
    {
        return $this->waitress($id, self::GUSTO, [self::LOBBY . ':' . self::M9 => 'primary'], 'cam_ranh');
    }

    private function restaurant(int $id, int $outlet, int $shift): EmployeeSpec
    {
        // Ở Nha Trang: không làm được M9 (chỉ Cam Ranh), không có năng lực OH → chỉ hỗ trợ bằng M6(L) / A2(L).
        return $this->waitress($id, $outlet, [$outlet . ':' . $shift => 'primary'], 'nha_trang');
    }

    public function test_segments_split_at_lobby_arrival_and_departure_times(): void
    {
        $day = $this->day(0);
        $state = new ScheduleState($this->problem([$this->oh(), $this->m9()], [new Requirement($day, self::LOBBY, self::M9, 0, 0, 2)],
            ['days' => [$day], 'coverage' => $this->lobbyRule($day)]));

        // OH(L) 08:45–16:45, M9 09:00–17:00, M6(L) 09:00–14:00, A2(L) 14:00–17:00 → 09:00–14:00 và 14:00–16:45.
        $this->assertSame([[540, 840], [840, 1005]], array_map(fn ($k) => array_slice($k, 0, 2), $state->coverage[0]));
        $this->assertSame([[540, 840], [840, 1005]], LobbyCoverage::segments(540, 1005, [[525, 1005], [540, 1020], [540, 840], [840, 1020]]));
    }

    /** Khung đặt rộng hơn giờ ca (08:30–17:00): OH(L) và M9 vẫn là ca ưu tiên, M6(L) / A2(L) vẫn là phương án thay thế. */
    public function test_oh_l_and_m9_stay_preferred_when_window_is_wider_than_shifts(): void
    {
        $day = $this->day(0);
        $state = new ScheduleState($this->problem([$this->oh(), $this->m9()], [new Requirement($day, self::LOBBY, self::M9, 0, 0, 2)],
            ['days' => [$day], 'coverage' => [new Coverage($day, 8 * 60 + 30, 17 * 60, 2, 2, 'Lobby', null, true)]]));

        $this->assertSame([0, 0, 1, 1], [
            $state->lobbyPartial(0, self::VILLA, self::OH, true),
            $state->lobbyPartial(0, self::LOBBY, self::M9, false),
            $state->lobbyPartial(0, self::GUSTO, self::M6, true),
            $state->lobbyPartial(0, self::GUSTO, self::A2, true),
        ]);
    }

    /** 6.1: OH(L) + M9(L) đạt định biên – 2 người cùng lúc suốt 09:00–16:45. */
    public function test_oh_l_plus_m9_meets_the_rule(): void
    {
        $result = $this->evaluateLocked([[$this->oh(), self::VILLA, self::OH, true], [$this->m9(), self::LOBBY, self::M9, false]]);

        $this->assertSame([], $this->lobbyGaps($result));
        $this->assertSame(['segments' => [['start' => 540, 'end' => 1005, 'count' => 2]], 'short' => [], 'min_count' => 2],
            LobbyCoverage::evaluate(self::CORE_START, self::CORE_END, 2, [[525, 1005], [540, 1020]]));
    }

    /** 6.2: OH(L) + M6(L) thiếu người 14:00–16:45 (M6(L) rời Lobby lúc 14:00). */
    public function test_oh_l_plus_m6_l_is_short_in_the_afternoon(): void
    {
        $result = $this->evaluateLocked([[$this->oh(), self::VILLA, self::OH, true], [$this->restaurant(3, self::GUSTO, self::M6), self::GUSTO, self::M6, true]]);

        $this->assertSame([[840, 1005]], $this->lobbyGaps($result));
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => isset($u['lobby'])))[0];
        $this->assertSame(1, $gap['assigned'], 'Chỉ 1 người cùng lúc 14:00–16:45');
        $this->assertStringContainsString('14:00–16:45', $gap['shift_code']);
        $this->assertSame(['Synthetic #1 (OH(L))'], $gap['reasons']['present']);
    }

    /** 6.3: M9(L) + A2(L) thiếu người 09:00–14:00 (A2(L) lên Lobby lúc 14:00). */
    public function test_m9_plus_a2_l_is_short_in_the_morning(): void
    {
        $result = $this->evaluateLocked([[$this->m9(), self::LOBBY, self::M9, false], [$this->restaurant(3, self::VILLA, self::A2), self::VILLA, self::A2, true]]);

        $this->assertSame([[540, 840]], $this->lobbyGaps($result));
    }

    /** 6.4: M6(L) + A2(L) không bao giờ là 2 người cùng lúc – mỗi đoạn chỉ có 1. */
    public function test_m6_l_plus_a2_l_is_never_two_at_the_same_time(): void
    {
        $result = $this->evaluateLocked([
            [$this->restaurant(3, self::GUSTO, self::M6), self::GUSTO, self::M6, true],
            [$this->restaurant(4, self::VILLA, self::A2), self::VILLA, self::A2, true],
        ]);

        $this->assertSame([[540, 840], [840, 1005]], $this->lobbyGaps($result));
        $this->assertSame(1, LobbyCoverage::evaluate(self::CORE_START, self::CORE_END, 2, [[540, 840], [840, 1020]])['min_count']);
    }

    /** 6.5: thay thế OH(L) + M6(L) + A2(L) được chấp nhận vì đoạn nào cũng đủ 2 người cùng lúc. */
    public function test_alternative_accepted_only_when_every_segment_has_two(): void
    {
        $result = $this->evaluateLocked([
            [$this->oh(), self::VILLA, self::OH, true],
            [$this->restaurant(3, self::GUSTO, self::M6), self::GUSTO, self::M6, true],
            [$this->restaurant(4, self::GUSTO, self::A2), self::GUSTO, self::A2, true],
        ]);

        $this->assertSame([], $this->lobbyGaps($result));
    }

    /** Chỉ nữ waiter được tính: người không phải nữ waiter (vd cấp quản lý) có mặt ở Lobby không làm đủ định biên. */
    public function test_only_female_waiters_are_counted(): void
    {
        $captain = $this->waitress(2, self::GUSTO, [self::LOBBY . ':' . self::M9 => 'primary'], 'cam_ranh', femaleStaff: false);
        $result = $this->evaluateLocked([[$this->oh(), self::VILLA, self::OH, true], [$captain, self::LOBBY, self::M9, false]]);

        $this->assertSame([[540, 840], [840, 1005]], $this->lobbyGaps($result));
    }

    /** Quản lý xếp tay M9 cho người ngoài nhóm Cam Ranh: giữ nguyên, tính đủ định biên Lobby, không báo vi phạm cứng. */
    public function test_manager_can_assign_m9_to_someone_outside_cam_ranh(): void
    {
        $nhaTrang = $this->waitress(2, self::GUSTO, [self::LOBBY . ':' . self::M9 => 'primary'], 'nha_trang');
        $result = $this->evaluateLocked([[$this->oh(), self::VILLA, self::OH, true], [$nhaTrang, self::LOBBY, self::M9, false]]);

        $this->assertSame([self::LOBBY, self::M9], $this->shiftOf($result, 2, $this->day(0)));
        $this->assertSame([], $result->hardConstraintViolations);
        $this->assertSame([], $this->lobbyGaps($result));
    }

    /** Bộ giải ưu tiên OH(L) (Nha Trang) + M9 (Cam Ranh), không dùng M6(L) / A2(L) và không thêm người khi đã đạt. */
    public function test_solver_prefers_oh_l_and_m9_and_adds_nobody_extra(): void
    {
        [$result] = $this->solveDay();

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertSame([], $this->lobbyGaps($result));
        $this->assertSame([self::VILLA, self::OH], $this->shiftOf($result, 1, $this->day(0)));
        $this->assertSame([self::LOBBY, self::M9], $this->shiftOf($result, 2, $this->day(0)));
        $lobby = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && ($r['support'] || $r['outlet_id'] === self::LOBBY));
        $this->assertEqualsCanonicalizing([1, 2], array_column($lobby, 'employee_id'), 'Đúng 2 người ở Lobby: OH(L) + M9, không M6(L) / A2(L) thừa');
    }

    /** Người Cam Ranh có OFF đã duyệt → bù bằng M6(L) + A2(L) cùng OH(L); nhà hàng vẫn đủ tối thiểu, OFF không bị phá. */
    public function test_solver_falls_back_to_m6_l_and_a2_l_without_breaking_off_or_outlet_minimum(): void
    {
        $day = $this->day(0);
        [$result, $problem] = $this->solveDay(hard: [2 => [$day => 'OFF đã duyệt']]);

        $this->assertNull($this->shiftOf($result, 2, $day), 'Không phá OFF đã duyệt');
        $this->assertSame([], $this->lobbyGaps($result), 'OH(L) + M6(L) 09–14h + A2(L) 14–17h: đủ 2 người ở mọi đoạn');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] !== self::LOBBY && $u['level'] === 'min'));

        $support = array_filter($result->assignments, fn ($r) => $r['kind'] === 'shift' && $r['support']);
        $this->assertEqualsCanonicalizing([self::OH, self::M6, self::A2], array_column($support, 'shift_id'));
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Không điều chuyển nếu outlet gốc xuống dưới tối thiểu: báo rõ đoạn thiếu 14:00–16:45 và nguyên nhân. */
    public function test_no_transfer_below_home_minimum_reports_gap_and_cause(): void
    {
        $day = $this->day(0);
        [$result] = $this->solveDay(hard: [2 => [$day => 'OFF đã duyệt']], gustoA2Spare: false);

        $this->assertSame([[840, 1005]], $this->lobbyGaps($result));
        $this->assertCount(2, array_filter($result->assignments, fn ($r) => $r['shift_id'] === self::A2 && ! $r['support']), 'Gusto A2 giữ đủ 2 người');
        $gap = array_values(array_filter($result->unfilledRequirements, fn ($u) => isset($u['lobby'])))[0];
        $this->assertNotEmpty($gap['reasons']['source_minimum'], 'Nguyên nhân: rút A2 sẽ làm Gusto dưới tối thiểu');
        $this->assertNotEmpty($gap['reasons']['approved_off'], 'Nguyên nhân: người M9 đang OFF đã duyệt');
    }

    /**
     * Một ngày: Nha Trang (OH), Cam Ranh (M9), Gusto M6 (2 tối thiểu) và A2 (2 tối thiểu) có hoặc không có người dư.
     *
     * @return array{0: SolveResult, 1: \Botble\RestaurantRoster\Scheduling\Problem}
     */
    private function solveDay(array $hard = [], bool $gustoA2Spare = true): array
    {
        $day = $this->day(0);
        $employees = [
            $this->oh(1),
            $this->m9(2),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, self::M6), [11, 12, 13]),
            ...array_map(fn ($id) => $this->restaurant($id, self::GUSTO, self::A2), $gustoA2Spare ? [21, 22, 23] : [21, 22]),
        ];

        $problem = $this->problem($employees, [
            new Requirement($day, self::VILLA, self::OH, 0, 0, 1),
            new Requirement($day, self::LOBBY, self::M9, 0, 0, 2),
            new Requirement($day, self::GUSTO, self::M6, 2, 2, 3),
            new Requirement($day, self::GUSTO, self::A2, 2, 2, 3),
        ], ['days' => [$day], 'hard' => $hard, 'coverage' => $this->lobbyRule($day), 'max_support' => 1, 'time_limit' => 3, 'max_iterations' => 30000]);

        return [$this->solve($problem), $problem];
    }
}
