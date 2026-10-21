<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Nhân viên nữ, hỗ trợ Lobby và Kid-Deline – DỮ LIỆU GIẢ LẬP:
 * luân phiên từng loại ca hỗ trợ có tính lịch sử, cân bằng sáng / chiều qua nhiều tuần, mượn Villa đứng Kid chỉ khi hợp lệ,
 * báo cáo công bằng và lý do phân công.
 */
class SupportFairnessTest extends TestCase
{
    use SyntheticRoster;

    private function waiter(int $id, int $outlet, ?string $residence = 'cam_ranh', array $extra = []): EmployeeSpec
    {
        return $this->employee($id, $outlet, [
            "{$outlet}:" . self::M6 => 'primary', "{$outlet}:" . self::A2 => 'primary', self::LOBBY . ':0' => 'eligible', ...$extra,
        ], 'female', residence: $residence, position: self::WAITER);
    }

    /** @return array<int, int> lượt có mặt ở Lobby bằng ca $shift của từng người */
    private function turns($result, int $shift, array $ids): array
    {
        $turns = array_fill_keys($ids, 0);

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['shift_id'] === $shift && ($row['support'] || $row['outlet_id'] === self::LOBBY) && isset($turns[$row['employee_id']])) {
                $turns[$row['employee_id']]++;
            }
        }

        return $turns;
    }

    /** Tình huống 3, 4: người đã làm A2(L) nhiều lần các tuần trước bị giảm ưu tiên; người chưa có lượt được ưu tiên. */
    public function test_a2_l_rotation_uses_history_per_type(): void
    {
        $days = $this->days();
        $team = array_map(fn ($id) => $this->waiter($id, self::VILLA), range(1, 6));
        $requirements = [...$this->flexRequirements($days, self::VILLA, self::M6, 1, 2), ...$this->flexRequirements($days, self::VILLA, self::A2, 1, 2)];
        $coverage = array_map(fn ($d) => new Coverage($d, 14 * 60, 17 * 60, 1, 1, 'Lobby chiều', [self::A2]), $days);
        // #1 đã làm A2(L) 8 lần trong 4 tuần trước, những người khác chưa lần nào.
        $result = $this->solve($this->problem($team, $requirements, ['coverage' => $coverage, 'time_limit' => 4, 'support_history' => [1 => ['A2(L)' => 8]]]));
        $turns = $this->turns($result, self::A2, range(1, 6));

        $this->assertSame(0, $turns[1], 'Người đã làm nhiều A2(L) các tuần trước không được giao thêm khi người khác còn lượt: ' . json_encode($turns));
        $this->assertSame(7, array_sum($turns), 'Mỗi chiều đúng 1 người hỗ trợ (không vượt định biên)');
        $this->assertLessThanOrEqual(2, max(array_slice($turns, 1, null, true)), 'Chia đều giữa những người còn lại');
    }

    /** Tình huống 5: Nha Trang → OH(L), Cam Ranh → M9(L); luân phiên M9(L) có tính lịch sử. */
    public function test_morning_lobby_by_residence_and_m9_history(): void
    {
        $days = $this->days();
        $team = [
            ...array_map(fn ($id) => $this->waiter($id, self::VILLA, 'nha_trang', [self::VILLA . ':' . self::OH => 'primary']), [1, 2]),
            ...array_map(fn ($id) => $this->waiter($id, self::GUSTO, 'cam_ranh'), [11, 12, 13]),
        ];
        $requirements = [...$this->flexRequirements($days, self::VILLA, self::M6, 0, 1), ...$this->flexRequirements($days, self::VILLA, self::A2, 0, 1),
            ...$this->flexRequirements($days, self::GUSTO, self::M6, 0, 1), ...$this->flexRequirements($days, self::GUSTO, self::A2, 0, 1)];

        foreach ($days as $day) {
            $requirements[] = new Requirement($day, self::LOBBY, self::M9, 0, 0, 2);
            $requirements[] = new Requirement($day, self::VILLA, self::OH, 0, 0, 0);
        }

        $coverage = array_map(fn ($d) => new Coverage($d, 9 * 60, 14 * 60, 1, 2, 'Lobby sáng', [self::OH, self::M9]), $days);
        $result = $this->solve($this->problem($team, $requirements, ['coverage' => $coverage, 'time_limit' => 4, 'support_history' => [11 => ['M9(L)' => 6]]]));

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['shift_id'] === self::OH) {
                $this->assertContains($row['employee_id'], [1, 2], 'OH chỉ cho người Nha Trang');
            }

            if ($row['kind'] === 'shift' && $row['shift_id'] === self::M9) {
                $this->assertContains($row['employee_id'], [11, 12, 13], 'M9 chỉ cho người Cam Ranh');
            }
        }

        $m9 = $this->turns($result, self::M9, [11, 12, 13]);
        $this->assertLessThanOrEqual(min($m9[12], $m9[13]), $m9[11], 'Người đã làm nhiều M9(L) trước đó không nhận nhiều hơn: ' . json_encode($m9));
    }

    /** Cân bằng sáng / chiều qua nhiều tuần: người tuần trước toàn ca sáng được ưu tiên ca chiều tuần này. */
    public function test_shift_balance_uses_previous_weeks(): void
    {
        $days = $this->days();
        $team = array_map(fn ($id) => $this->waiter($id, self::GUSTO), range(1, 6));
        $requirements = [...$this->dailyRequirements($days, self::GUSTO, self::M6, 2, 2), ...$this->dailyRequirements($days, self::GUSTO, self::A2, 2, 2)];
        $result = $this->solve($this->problem($team, $requirements, ['time_limit' => 4, 'shift_history' => [1 => ['morning' => 12, 'afternoon' => 4]]]));
        $row = array_values(array_filter($result->employeeWorkload, fn ($w) => $w['employee_id'] === 1))[0];

        $this->assertGreaterThan($row['morning'], $row['afternoon'], 'Người làm nhiều ca sáng các tuần trước được xếp nhiều ca chiều hơn: ' . json_encode($row));
    }

    /** Tình huống 10: Gusto thiếu người đứng Kid nhưng Villa vừa đủ tối thiểu → không tự mượn; báo thiếu kèm phân tích. */
    public function test_no_villa_borrow_when_villa_would_drop_below_minimum(): void
    {
        $days = $this->days(1);
        $kidSkill = [self::KID . ':' . self::M6 => 'eligible'];
        $villa = array_map(fn ($id) => $this->waiter($id, self::VILLA, 'cam_ranh', $kidSkill), [11, 12]);
        $requirements = [
            new Requirement($days[0], self::KID, self::M6, 1, 1, 1),
            new Requirement($days[0], self::VILLA, self::M6, 2, 2),
        ];
        $result = $this->solve($this->problem($villa, $requirements, ['days' => $days, 'time_limit' => 2, 'outlet_parents' => [self::KID => self::GUSTO]]));

        $this->assertArrayNotHasKey("{$days[0]}|" . self::KID . '|' . self::M6, $this->staffing($result), 'Không mượn khi Villa sẽ dưới tối thiểu');
        $this->assertCount(2, $this->staffing($result)["{$days[0]}|" . self::VILLA . '|' . self::M6]);
        $kid = array_values(array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::KID))[0] ?? null;
        $this->assertNotNull($kid, 'Kid-Deline thiếu người được báo kèm lý do');
        $this->assertSame('min', $kid['level']);
    }

    /** Tình huống 9, 11: Gusto thiếu, Villa còn dư → mượn đúng người hợp lệ; người Villa OFF hoặc đã khóa ca khác thì không. */
    public function test_villa_borrow_picks_only_available_staff(): void
    {
        $days = $this->days(1);
        $kidSkill = [self::KID . ':' . self::A2 => 'eligible'];
        $villa = array_map(fn ($id) => $this->waiter($id, self::VILLA, 'cam_ranh', $kidSkill), [11, 12, 13, 14]);
        $requirements = [
            new Requirement($days[0], self::KID, self::A2, 1, 1, 1),
            new Requirement($days[0], self::VILLA, self::A2, 1, 1),
        ];
        $result = $this->solve($this->problem($villa, $requirements, [
            'days' => $days, 'time_limit' => 2, 'outlet_parents' => [self::KID => self::GUSTO],
            'hard' => [11 => [$days[0] => 'OFF quản lý đã cho']],
            'fixed' => [$this->locked(12, $days[0], self::VILLA, self::M6)],
        ]));
        $kid = $this->staffing($result)["{$days[0]}|" . self::KID . '|' . self::A2] ?? [];

        $this->assertCount(1, $kid, 'Kid-Deline có người (mượn Villa)');
        $this->assertContains($kid[0], [13, 14], 'Không mượn người OFF (#11) hay người đã khóa ca khác (#12)');
        $this->assertSame([self::VILLA, self::M6], $this->shiftOf($result, 12, $days[0]), 'Ca đã khóa giữ nguyên');
        $this->assertNotEmpty(array_filter($result->warnings, fn ($w) => $w['code'] === 'kid_borrow'), 'Có cảnh báo mượn người đứng Kid');

        // Tình huống 12: người đứng Kid không được tính vào định biên Villa.
        $this->assertNotContains($kid[0], $this->staffing($result)["{$days[0]}|" . self::VILLA . '|' . self::A2] ?? []);
    }

    /** Tình huống 6: khung Lobby đã đủ (ô khóa) → không thêm người; ô khóa dư → cảnh báo vượt định biên. */
    public function test_lobby_not_overfilled_and_over_target_is_reported(): void
    {
        $days = $this->days(1);
        $team = array_map(fn ($id) => $this->waiter($id, self::GUSTO), range(1, 5));
        $requirements = [...$this->flexRequirements($days, self::GUSTO, self::M6, 1, 2), ...$this->flexRequirements($days, self::GUSTO, self::A2, 1, 2)];
        $coverage = [new Coverage($days[0], 14 * 60, 17 * 60, 1, 1, 'Lobby chiều', [self::A2])];
        // 2 ô A2(L) đã khóa trong khi khung chỉ cần 1 người.
        $fixed = array_map(fn ($id) => new FixedAssignment($id, $days[0], FixedAssignment::KIND_SHIFT, self::GUSTO, self::A2, support: true), [1, 2]);
        $result = $this->solve($this->problem($team, $requirements, ['days' => $days, 'coverage' => $coverage, 'fixed' => $fixed, 'time_limit' => 2]));

        $flags = array_filter($result->assignments, fn ($r) => $r['support'] && ! $r['locked']);
        $this->assertSame([], $flags, 'Không giao thêm (L) khi khung đã đủ');
        $this->assertNotEmpty(array_filter($result->warnings, fn ($w) => $w['code'] === 'lobby_over'), 'Cảnh báo khung Lobby vượt định biên');
    }

    /** Mỗi outlet góp tối đa 1 người cho một khung Lobby (Gusto + Villa = 2), dù khung cần nhiều hơn (quản lý 22/10/2026). */
    public function test_each_outlet_sends_at_most_one_person_per_lobby_window(): void
    {
        $days = $this->days(2);
        $team = [...array_map(fn ($id) => $this->waiter($id, self::GUSTO), range(1, 4)), ...array_map(fn ($id) => $this->waiter($id, self::VILLA), range(11, 14))];
        $requirements = [];

        foreach ([self::GUSTO, self::VILLA] as $outlet) {
            $requirements = [...$requirements, ...$this->flexRequirements($days, $outlet, self::M6, 0, 1), ...$this->flexRequirements($days, $outlet, self::A2, 0, 1)];
        }

        $coverage = array_map(fn ($d) => new Coverage($d, 14 * 60, 17 * 60, 3, 3, 'Lobby chiều', [self::A2]), $days);
        $result = $this->solve($this->problem($team, $requirements, ['days' => $days, 'coverage' => $coverage, 'max_support' => 1, 'time_limit' => 2]));

        foreach ($days as $day) {
            $from = ['gusto' => 0, 'villa' => 0];

            foreach ($result->assignments as $row) {
                if ($row['date'] === $day && $row['support']) {
                    $from[$row['employee_id'] < 10 ? 'gusto' : 'villa']++;
                }
            }

            $this->assertSame(['gusto' => 1, 'villa' => 1], $from, "{$day}: mỗi outlet đúng 1 người lên Lobby");
        }

        $this->assertNotEmpty(array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::LOBBY), 'Khung cần 3 nhưng chỉ có 2 → báo thiếu');
    }

    /** M6(L) chỉ ở Lobby 09:00–12:00: không được tính phủ khung chiều 14:00–17:00. */
    public function test_m6_l_covers_only_its_configured_window(): void
    {
        $days = $this->days(1);
        $team = array_map(fn ($id) => $this->waiter($id, self::GUSTO), range(1, 4));
        $requirements = [...$this->flexRequirements($days, self::GUSTO, self::M6, 1, 2), ...$this->flexRequirements($days, self::GUSTO, self::A2, 1, 2)];
        $coverage = [new Coverage($days[0], 9 * 60, 12 * 60, 1, 1, 'Lobby sáng', [self::M6]), new Coverage($days[0], 14 * 60, 17 * 60, 1, 1, 'Lobby chiều', [self::A2])];
        $result = $this->solve($this->problem($team, $requirements, ['days' => $days, 'coverage' => $coverage, 'max_support' => 1, 'time_limit' => 2]));
        $flags = array_values(array_filter($result->assignments, fn ($r) => $r['support']));

        $this->assertEqualsCanonicalizing([self::M6, self::A2], array_column($flags, 'shift_id'), 'Sáng 1 M6(L), chiều 1 A2(L)');
        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['outlet_id'] === self::LOBBY));
    }

    /** Báo cáo: lượt theo loại ca, người đủ điều kiện chưa có lượt, lý do phân công dễ hiểu. */
    public function test_support_report_explains_assignments(): void
    {
        $days = $this->days();
        $team = array_map(fn ($id) => $this->waiter($id, self::VILLA), range(1, 6));
        $requirements = [...$this->flexRequirements($days, self::VILLA, self::M6, 1, 2), ...$this->flexRequirements($days, self::VILLA, self::A2, 1, 2)];
        $coverage = array_map(fn ($d) => new Coverage($d, 14 * 60, 17 * 60, 1, 1, 'Lobby chiều', [self::A2]), $days);
        $result = $this->solve($this->problem($team, $requirements, ['coverage' => $coverage, 'time_limit' => 3, 'support_history' => [1 => ['A2(L)' => 8]]]));
        $report = $result->supportReport;

        $this->assertSame(['A2(L)'], $report['types']);
        $this->assertCount(6, $report['rows']);
        $this->assertSame(8, array_values(array_filter($report['rows'], fn ($r) => $r['employee_id'] === 1))[0]['history']['A2(L)']);
        $this->assertStringContainsString('Synthetic #1 (8 lượt / 4 tuần trước)', implode(', ', $report['idle']['A2(L)'] ?? []), 'Người chưa có lượt trong kỳ được liệt kê');
        $this->assertNotEmpty($report['reasons']);
        $this->assertMatchesRegularExpression('/được xếp A2\(L\): 0 lượt A2\(L\) trong 4 tuần gần nhất \(trung bình 5 người đủ điều kiện khác: 1\.6\)/', $report['reasons'][0]['text']);
    }
}
