<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Phân bổ nữ waiter hỗ trợ Lobby theo tuần (quản lý 23/10/2026) – DỮ LIỆU GIẢ LẬP:
 * Nha Trang → OH(L) 08:45–16:45, Cam Ranh → M9(L) 09:00–17:00; mỗi người một ca ưu tiên / tuần trước khi ai nhận ca thứ hai;
 * M6(L) (09–14h) / A2(L) (14–17h) chỉ bù khoảng còn thiếu; 2 nữ waiter cùng lúc 09:00–16:45; công bằng theo lịch sử.
 */
class LobbyWeeklyRoundTest extends TestCase
{
    use SyntheticRoster;

    private const NT = [1, 2, 3, 4, 5];

    private const CR = [11, 12, 13, 14, 15];

    private function waitress(int $id, int $home, array $skills, string $residence): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", 'female', $home, 48, 2, $skills + [self::LOBBY . ':0' => 'eligible'], [], $residence, self::WAITER, isFemaleStaff: true);
    }

    /**
     * Một tuần: Villa (Nha Trang – OH), Gusto (Cam Ranh – M9 ở Lobby); Villa / Gusto M6 & A2 tối thiểu 1 với người dư để M6(L) / A2(L) bù.
     *
     * @param int[] $nt
     * @param int[] $cr
     * @return array{0: SolveResult, 1: \Botble\RestaurantRoster\Scheduling\Problem}
     */
    private function week(array $nt = self::NT, array $cr = self::CR, array $options = []): array
    {
        $days = $this->days();
        $day = [self::VILLA . ':' . self::OH => 'primary', self::VILLA . ':' . self::M6 => 'primary', self::VILLA . ':' . self::A2 => 'primary'];
        $people = [
            ...array_map(fn ($id) => $this->waitress($id, self::VILLA, $day, 'nha_trang'), $nt),
            ...array_map(fn ($id) => $this->waitress($id, self::GUSTO, [self::LOBBY . ':' . self::M9 => 'primary', self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary'], 'cam_ranh'), $cr),
            ...array_map(fn ($id) => $this->waitress($id, self::GUSTO, [self::GUSTO . ':' . self::M6 => 'primary', self::GUSTO . ':' . self::A2 => 'primary'], 'nha_trang'), [21, 22, 23, 24, 25, 26]),
        ];
        $requirements = [
            // Chỗ OH(L) ở Villa như bản chạy thật (ProblemBuilder::supportSlots): 0 người, sức chứa = số người Lobby cần.
            ...array_map(fn ($d) => new \Botble\RestaurantRoster\Scheduling\Requirement($d, self::VILLA, self::OH, 0, 0, 2), $days),
            ...$this->dailyRequirements($days, self::VILLA, self::M6, 1, 4),
            ...$this->dailyRequirements($days, self::VILLA, self::A2, 1, 4),
            ...$this->dailyRequirements($days, self::GUSTO, self::M6, 1, 4),
            ...$this->dailyRequirements($days, self::GUSTO, self::A2, 1, 4),
            ...array_map(fn ($d) => new \Botble\RestaurantRoster\Scheduling\Requirement($d, self::LOBBY, self::M9, 0, 0, 2), $days),
        ];
        $preferred = array_fill_keys($nt, self::OH) + array_fill_keys($cr, self::M9);
        $problem = $this->problem($people, $requirements, ['coverage' => array_map(fn ($d) => new Coverage($d, 540, 1005, 2, 2, 'Lobby', null, true), $days),
            'lobby_preferred' => $preferred, 'max_support' => 2, 'time_limit' => 90, 'max_iterations' => 80000, ...$options]);

        return [$this->solve($problem), $problem];
    }

    /** @return array<int, int> số ca OH(L) / M9(L) của từng người trong kỳ */
    private function preferredCounts(SolveResult $result, array $ids, int $shift): array
    {
        $counts = array_fill_keys($ids, 0);

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['shift_id'] === $shift && isset($counts[$row['employee_id']])
                && ($row['support'] || $row['outlet_id'] === self::LOBBY)) {
                $counts[$row['employee_id']]++;
            }
        }

        return $counts;
    }

    private function lobbyGaps(SolveResult $result): array
    {
        return array_values(array_filter($result->unfilledRequirements, fn ($u) => isset($u['lobby'])));
    }

    /** Mọi nữ waiter đủ điều kiện có một ca OH(L) / M9(L) trước khi ai nhận ca thứ hai; Lobby đủ 2 người cùng lúc; không phá ràng buộc. */
    public function test_everyone_gets_one_preferred_shift_before_anyone_gets_a_second(): void
    {
        [$result, $problem] = $this->week();

        foreach ([[self::NT, self::OH], [self::CR, self::M9]] as [$ids, $shift]) {
            $counts = $this->preferredCounts($result, $ids, $shift);
            $this->assertGreaterThanOrEqual(1, min($counts), 'Ai cũng có ít nhất 1 ca: ' . json_encode($counts));
            $this->assertLessThanOrEqual(1, max($counts) - min($counts), 'Chênh nhau tối đa 1 ca: ' . json_encode($counts));
        }

        $this->assertSame([], $this->lobbyGaps($result), 'Lobby đủ 2 nữ waiter cùng lúc 09:00–16:45');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'), 'Outlet gốc đủ tối thiểu');
        $this->assertHardConstraintsHold($problem, $result);
        $rounds = $result->supportReport['lobby_rounds'];
        $this->assertNotEmpty($rounds);
        $this->assertSame([], array_merge(...array_column($rounds, 'missing')), 'Báo cáo: không ai chưa có ca');
    }

    /** Ít ca hơn số người: không ai nhận ca thứ hai khi người cùng nhóm còn chưa có; người không có ca được báo lý do. */
    public function test_no_second_shift_while_someone_in_group_has_none(): void
    {
        $nt = range(1, 9);
        [$result] = $this->week($nt);
        $counts = $this->preferredCounts($result, $nt, self::OH);

        $this->assertLessThanOrEqual(1, max($counts), 'Không ai 2 ca OH(L) khi còn người chưa có: ' . json_encode($counts));
        $round = collect($result->supportReport['lobby_rounds'])->first(fn ($r) => $r['type'] === 'OH(L)');
        $this->assertCount(count(array_filter($counts, fn ($c) => $c === 0)), $round['missing']);
        $this->assertStringContainsString('luân phiên tuần sau', $round['missing'][0] ?? '');
    }

    /** Người nghỉ cả tuần không bị coi là "chưa nhận ca"; ô khóa và OFF đã duyệt giữ nguyên. */
    public function test_off_week_is_not_a_missed_turn_and_locks_are_kept(): void
    {
        $days = $this->days();
        $hard = [1 => array_fill_keys($days, 'Nghỉ phép năm')];
        [$result] = $this->week(options: ['hard' => $hard, 'fixed' => [new FixedAssignment(2, $days[0], FixedAssignment::KIND_SHIFT, self::VILLA, self::M6)]]);

        $counts = $this->preferredCounts($result, self::NT, self::OH);
        $this->assertSame(0, $counts[1], 'Không xếp người nghỉ phép');
        $this->assertGreaterThanOrEqual(1, min(array_slice($counts, 1, null, true)));
        $this->assertSame([self::VILLA, self::M6], $this->shiftOf($result, 2, $days[0]), 'Ô khóa giữ nguyên');
        $round = collect($result->supportReport['lobby_rounds'])->first(fn ($r) => $r['type'] === 'OH(L)');
        $this->assertContains('Synthetic #1', $round['away']);
    }

    /** Cả nhóm Cam Ranh OFF một ngày: M9(L) không có → M6(L) phủ 09–14h, A2(L) phủ 14–17h; Lobby vẫn đủ 2 người. */
    public function test_m6_l_and_a2_l_fill_only_the_missing_hours(): void
    {
        $day = $this->day(2);
        [$result] = $this->week(options: ['hard' => array_fill_keys(self::CR, [$day => 'Xin OFF'])]);

        $support = array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['support']);
        $this->assertEqualsCanonicalizing([self::OH, self::M6, self::A2], array_values(array_unique(array_column($support, 'shift_id'))));
        $this->assertSame([], array_filter($this->lobbyGaps($result), fn ($g) => $g['date'] === $day));

        // Ngày khác: chỉ dùng M6(L) / A2(L) khi Lobby chưa có đủ 2 người phủ trọn ca (OH(L) / M9) – không xếp ca hỗ trợ thừa khi đã đủ.
        foreach (array_diff($this->days(), [$day]) as $date) {
            $full = count(array_filter($result->assignments, fn ($r) => $r['date'] === $date && (($r['shift_id'] === self::OH && $r['support']) || $r['shift_id'] === self::M9)));
            $partial = count(array_filter($result->assignments, fn ($r) => $r['date'] === $date && $r['support'] && in_array($r['shift_id'], [self::M6, self::A2], true)));
            $this->assertTrue($partial === 0 || $full < 2, "{$date}: có {$full} người OH(L)/M9 mà vẫn thêm {$partial} M6(L)/A2(L)");
        }
    }

    /** Công bằng nhiều tuần: người đã có nhiều lượt OH(L) các tuần trước là người nhường lượt khi thiếu ca. */
    public function test_history_decides_who_waits(): void
    {
        // 12 Nha Trang + 5 Cam Ranh = 17 người, tuần chỉ có 14 ca OH(L) / M9(L) → 3 người phải chờ tuần sau.
        $nt = range(1, 12);
        [$result] = $this->week($nt, options: ['support_history' => [3 => ['OH(L)' => 6]], 'history_weeks' => 8]);
        $counts = $this->preferredCounts($result, $nt, self::OH);

        $this->assertSame(0, $counts[3], 'Người nhiều lượt nhất trong 8 tuần qua nhường lượt: ' . json_encode($counts));
    }

    /** Không thể đủ 2 người (mọi nữ waiter có năng lực Lobby OFF hôm đó): báo rõ đoạn giờ thiếu, không phá ràng buộc. */
    public function test_impossible_coverage_is_reported_not_forced(): void
    {
        $day = $this->day(4);
        [$result, $problem] = $this->week(options: ['hard' => array_fill_keys([...self::NT, ...self::CR, 21, 22, 23, 24, 25, 26], [$day => 'Sự kiện tập thể'])]);

        $gaps = array_filter($this->lobbyGaps($result), fn ($g) => $g['date'] === $day);
        $this->assertNotEmpty($gaps, 'Cảnh báo thiếu Lobby');
        $this->assertNotEmpty(array_values($gaps)[0]['reasons']['approved_off']);
        $this->assertHardConstraintsHold($problem, $result);
    }
}
