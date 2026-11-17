<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Cặp nghỉ chung (quản lý 17/11/2026) – DỮ LIỆU GIẢ LẬP: ưu tiên mềm cho hai người OFF cùng ngày, không phá ràng buộc cứng.
 */
class OffPairTest extends TestCase
{
    use SyntheticRoster;

    /** @return array<int, string[]> employeeId => các ngày OFF */
    private function offDays(SolveResult $result): array
    {
        $off = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] !== 'shift') {
                $off[$row['employee_id']][] = $row['date'];
            }
        }

        return array_map(function ($days) {
            sort($days);

            return $days;
        }, $off);
    }

    private function week(array $options): SolveResult
    {
        $days = $this->days();

        return $this->solve($this->problem([...$this->team(1, 6, self::GUSTO), ...$this->team(11, 6, self::VILLA)], [
            ...$this->flexRequirements($days, self::GUSTO, self::M6, 2, 2),
            ...$this->flexRequirements($days, self::GUSTO, self::A2, 2, 2),
            ...$this->flexRequirements($days, self::VILLA, self::M6, 2, 2),
            ...$this->flexRequirements($days, self::VILLA, self::A2, 2, 2),
        ], ['time_limit' => 30, 'max_iterations' => 60000, ...$options]));
    }

    /** Cặp khác outlet: OFF cùng ngày cả tuần; không có cảnh báo; giải thích ghi 1 / 1 cặp. */
    public function test_pair_gets_same_days_off(): void
    {
        $result = $this->week(['off_pairs' => [[2, 13]]]);
        $off = $this->offDays($result);

        $this->assertCount(2, $off[2]);
        $this->assertSame($off[2], $off[13], 'Hai người OFF cùng ngày');
        $this->assertSame(0, $result->softConstraintScores['objective']['off_pair']);
        $this->assertSame([], array_values(array_filter($result->warnings, fn ($w) => $w['code'] === 'off_pair')));
        $this->assertContains('Cặp nghỉ chung: 1 / 1 cặp OFF cùng ngày cả tuần.', $result->explanation);
    }

    /** A đã có OFF duyệt thứ Ba: B được ưu tiên OFF thứ Ba. */
    public function test_partner_follows_approved_off(): void
    {
        $tuesday = $this->day(1);
        $result = $this->week(['off_pairs' => [[2, 13]], 'hard' => [2 => [$tuesday => 'OFF đã duyệt']]]);
        $off = $this->offDays($result);

        $this->assertContains($tuesday, $off[2]);
        $this->assertContains($tuesday, $off[13], 'Người cùng cặp OFF theo ngày OFF đã duyệt');
    }

    /** Không thể OFF chung (B có ca khóa đúng ngày A OFF đã duyệt): lịch vẫn hợp lệ, có cảnh báo nêu ngày và nguyên nhân. */
    public function test_impossible_pair_keeps_hard_rules_and_warns(): void
    {
        $tuesday = $this->day(1);
        $problem = $this->problem([...$this->team(1, 6, self::GUSTO), ...$this->team(11, 6, self::VILLA)], [
            ...$this->flexRequirements($this->days(), self::GUSTO, self::M6, 2, 2),
            ...$this->flexRequirements($this->days(), self::GUSTO, self::A2, 2, 2),
            ...$this->flexRequirements($this->days(), self::VILLA, self::M6, 2, 2),
            ...$this->flexRequirements($this->days(), self::VILLA, self::A2, 2, 2),
        ], ['time_limit' => 30, 'max_iterations' => 60000, 'off_pairs' => [[2, 13]], 'hard' => [2 => [$tuesday => 'OFF đã duyệt']],
            'fixed' => [new FixedAssignment(13, $tuesday, FixedAssignment::KIND_SHIFT, self::VILLA, self::M6)]]);
        $result = $this->solve($problem);

        $this->assertNotContains($tuesday, $this->offDays($result)[13] ?? [], 'Ca khóa giữ nguyên');
        $this->assertNotContains($tuesday, array_column(array_filter($result->assignments, fn ($r) => $r['employee_id'] === 2 && $r['kind'] === 'shift'), 'date'), 'OFF đã duyệt giữ nguyên');
        $warning = array_values(array_filter($result->warnings, fn ($w) => $w['code'] === 'off_pair'));
        $this->assertCount(1, $warning);
        $this->assertStringContainsString(date('d/m', strtotime($tuesday)), $warning[0]['message']);
        $this->assertStringContainsString('ô đã khóa / OFF đã duyệt', $warning[0]['message']);
        $this->assertHardConstraintsHold($problem, $result);
    }

    /** Điểm phạt tăng dần đúng: mỗi ngày chỉ một người OFF là một đơn vị, khớp đếm lại từ đầu. */
    public function test_mismatch_count_is_incremental(): void
    {
        $days = $this->days();
        $state = new ScheduleState($this->problem([...$this->team(1, 2, self::GUSTO)], $this->flexRequirements($days, self::GUSTO, self::M6, 0, 2), ['off_pairs' => [[1, 2]]]));

        $this->assertSame(0, $state->objectiveValues()['off_pair'], 'Cả hai chưa làm ngày nào: không lệch');
        $state->place(1, 0, self::GUSTO, self::M6);
        $state->place(1, 1, self::GUSTO, self::M6);
        $this->assertSame(2, $state->objectiveValues()['off_pair']);
        $state->place(2, 0, self::GUSTO, self::M6);
        $this->assertSame(1, $state->objectiveValues()['off_pair']);
        $state->remove(1, 1);
        $this->assertSame(0, $state->objectiveValues()['off_pair']);
        $this->assertSame([], $state->offPairMismatches());
    }
}
