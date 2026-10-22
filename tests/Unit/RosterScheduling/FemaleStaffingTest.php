<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Nữ waiter trực tiếp tại outlet sau khi xếp Lobby (L) và Kid-Deline – DỮ LIỆU GIẢ LẬP:
 * đủ tối thiểu nữ waiter ở cả M6 và A2, người hỗ trợ (L) / đứng Kid không bị đếm trùng, không đổi OFF / ô khóa để cân bằng,
 * không đủ thì báo rõ số thiếu và nguyên nhân.
 */
class FemaleStaffingTest extends TestCase
{
    use SyntheticRoster;

    private function waitress(int $id, int $outlet, array $extra = []): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", 'female', $outlet, 48, 2, [
            "{$outlet}:" . self::M6 => 'primary', "{$outlet}:" . self::A2 => 'primary', self::LOBBY . ':0' => 'eligible', ...$extra,
        ], [], 'cam_ranh', self::WAITER, isFemaleStaff: true);
    }

    private function req(string $day, int $outlet, int $shift, int $min, int $target, int $minFemale = 0, int $targetFemale = 0, ?int $max = null): Requirement
    {
        return new Requirement($day, $outlet, $shift, $min, $target, $max ?? $target + 2, minFemale: $minFemale, targetFemale: max($minFemale, $targetFemale));
    }

    /** @return array<string, int> "date|shift" => số nữ waiter trực tiếp (không (L)) tại outlet */
    private function direct($result, int $outlet): array
    {
        $map = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['outlet_id'] === $outlet && ! $row['support']) {
                $map["{$row['date']}|{$row['shift_id']}"] = ($map["{$row['date']}|{$row['shift_id']}"] ?? 0) + 1;
            }
        }

        return $map;
    }

    /** 1. M6 cần 3 nữ waiter, A2 chỉ cần tổng số: thuật toán đưa đủ nữ waiter sang M6 thay vì dồn vào A2. */
    public function test_morning_gets_its_minimum_female_waiters(): void
    {
        $days = $this->days();
        $team = array_map(fn ($id) => $this->waitress($id, self::VILLA), range(1, 8));
        $requirements = [];

        foreach ($days as $day) {
            $requirements[] = $this->req($day, self::VILLA, self::M6, 3, 3, 3);
            $requirements[] = $this->req($day, self::VILLA, self::A2, 2, 3);
        }

        $result = $this->solve($this->problem($team, $requirements, ['time_limit' => 3]));
        $direct = $this->direct($result, self::VILLA);

        foreach ($days as $day) {
            $this->assertGreaterThanOrEqual(3, $direct["{$day}|" . self::M6] ?? 0, "{$day}: M6 đủ 3 nữ waiter trực tiếp");
        }

        $this->assertSame(0, $result->softConstraintScores['objective']['min_shortage']);
    }

    /** 2. Người hỗ trợ Lobby không tính vào outlet: M6 vẫn đủ nữ waiter trực tiếp và Lobby vẫn có người (luân phiên người (L)). */
    public function test_lobby_support_does_not_leave_morning_short(): void
    {
        $days = $this->days();
        $team = array_map(fn ($id) => $this->waitress($id, self::VILLA), range(1, 8));
        $requirements = [];

        foreach ($days as $day) {
            $requirements[] = $this->req($day, self::VILLA, self::M6, 2, 2, 2, 2, 4);
            $requirements[] = $this->req($day, self::VILLA, self::A2, 2, 3);
        }

        $coverage = array_map(fn ($d) => new Coverage($d, 9 * 60, 12 * 60, 1, 1, 'Lobby sáng', [self::M6]), $days);
        $result = $this->solve($this->problem($team, $requirements, ['coverage' => $coverage, 'time_limit' => 4, 'max_support' => 1]));
        $direct = $this->direct($result, self::VILLA);

        foreach ($days as $day) {
            $this->assertGreaterThanOrEqual(2, $direct["{$day}|" . self::M6] ?? 0, "{$day}: M6 còn đủ 2 nữ waiter trực tiếp sau khi 1 người lên Lobby");
            $lobby = array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['support']);
            $this->assertCount(1, $lobby, "{$day}: Lobby sáng có đúng 1 người M6(L)");
        }

        $rows = array_filter($result->supportReport['female_staffing'], fn ($r) => $r['shift_code'] === 'M6');
        $this->assertSame(7, array_sum(array_column($rows, 'lobby')), 'Báo cáo tách riêng người hỗ trợ Lobby');
    }

    /** 3, 12. Người đứng Kid-Deline không được đếm là nữ waiter trực tiếp của Gusto. */
    public function test_kid_deline_staff_are_not_double_counted(): void
    {
        $days = $this->days(1);
        $kid = [self::KID . ':' . self::M6 => 'primary'];
        $team = array_map(fn ($id) => $this->waitress($id, self::GUSTO, $kid), range(1, 4));
        $requirements = [
            $this->req($days[0], self::GUSTO, self::M6, 2, 2, 2, 2, 2),
            new Requirement($days[0], self::KID, self::M6, 1, 1, 1),
        ];
        $result = $this->solve($this->problem($team, $requirements, ['days' => $days, 'time_limit' => 2, 'outlet_parents' => [self::KID => self::GUSTO]]));
        $staffing = $this->staffing($result);
        $kidPeople = $staffing["{$days[0]}|" . self::KID . '|' . self::M6] ?? [];
        $gustoPeople = $staffing["{$days[0]}|" . self::GUSTO . '|' . self::M6] ?? [];

        $this->assertCount(1, $kidPeople);
        $this->assertCount(2, $gustoPeople, 'Gusto M6 có 2 nữ waiter trực tiếp ngoài người đứng Kid');
        $this->assertSame([], array_intersect($kidPeople, $gustoPeople));
        $row = array_values(array_filter($result->supportReport['female_staffing'], fn ($r) => $r['outlet_id'] === self::GUSTO))[0];
        $this->assertSame([2, 1], [$row['direct'], $row['kid']]);
    }

    /** 4, 5, 6. Ca đã khóa và OFF đã cho không bị đổi để cân bằng; không đủ thì báo thiếu với số liệu và nguyên nhân. */
    public function test_locked_and_off_are_kept_and_shortage_is_explained(): void
    {
        $days = $this->days(1);
        $team = array_map(fn ($id) => $this->waitress($id, self::VILLA), range(1, 5));
        $requirements = [
            $this->req($days[0], self::VILLA, self::M6, 1, 2, 2, 2),
            // A2: mục tiêu 2 nữ waiter – 3 người đã khóa là vượt mục tiêu.
            $this->req($days[0], self::VILLA, self::A2, 1, 2, 0, 2),
        ];
        // #1, #2, #3 đã khóa ca A2; #4 OFF đã cho → M6 chỉ còn #5.
        $fixed = array_map(fn ($id) => $this->locked($id, $days[0], self::VILLA, self::A2), [1, 2, 3]);
        $result = $this->solve($this->problem($team, $requirements, ['days' => $days, 'time_limit' => 2, 'fixed' => $fixed, 'hard' => [4 => [$days[0] => 'OFF quản lý đã cho']]]));

        foreach ([1, 2, 3] as $id) {
            $this->assertSame([self::VILLA, self::A2], $this->shiftOf($result, $id, $days[0]), "#{$id}: ca đã khóa giữ nguyên");
        }

        $this->assertNull($this->shiftOf($result, 4, $days[0]), 'Không dùng người OFF để bù');

        $short = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['shift_id'] === self::M6))[0] ?? null;
        $this->assertNotNull($short);
        $this->assertSame(['min', 1, 2], [$short['level'], $short['assigned_female'], $short['min_female']]);
        $this->assertNotEmpty($short['reasons']['approved_off'], 'Nêu người OFF');
        $this->assertNotEmpty($short['reasons']['locked'], 'Nêu ca đã khóa');

        $row = array_values(array_filter($result->supportReport['female_staffing'], fn ($r) => $r['shift_code'] === 'M6'))[0];
        $this->assertSame('short_min', $row['status']);
        $this->assertNotEmpty(array_filter($result->warnings, fn ($w) => $w['code'] === 'female_unbalanced'), 'A2 dư (3 người khóa) trong khi M6 thiếu → cảnh báo');
    }

    /** Không cấu hình nữ waiter (0): không ép, không cảnh báo – lịch như trước. */
    public function test_no_female_requirement_means_no_constraint(): void
    {
        $days = $this->days(1);
        $team = array_map(fn ($id) => $this->waitress($id, self::VILLA), range(1, 4));
        $result = $this->solve($this->problem($team, [$this->req($days[0], self::VILLA, self::M6, 1, 1), $this->req($days[0], self::VILLA, self::A2, 1, 3)], ['days' => $days, 'time_limit' => 1]));

        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['min_female'] ?? 0));
        $this->assertSame([], array_filter($result->warnings, fn ($w) => $w['code'] === 'female_unbalanced'));
    }
}
