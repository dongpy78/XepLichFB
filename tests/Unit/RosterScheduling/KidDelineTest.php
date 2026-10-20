<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Kid-Deline (khu trực thuộc Gusto): sáng 1, chiều 1 nữ waiter; ưu tiên người Gusto, Gusto thiếu mới lấy người Villa – DỮ LIỆU GIẢ LẬP.
 */
class KidDelineTest extends TestCase
{
    use SyntheticRoster;

    private function waiter(int $id, int $home): \Botble\RestaurantRoster\Scheduling\EmployeeSpec
    {
        return $this->employee($id, $home, [
            "{$home}:" . self::M6 => 'primary', "{$home}:" . self::A2 => 'primary',
            self::KID . ':' . self::M6 => $home === self::GUSTO ? 'primary' : 'eligible',
            self::KID . ':' . self::A2 => $home === self::GUSTO ? 'primary' : 'eligible',
            self::LOBBY . ':0' => 'eligible',
        ]);
    }

    private function kidProblem(array $options = [])
    {
        $days = $this->days();
        $requirements = [];

        foreach ($days as $day) {
            foreach ([self::M6, self::A2] as $shift) {
                $requirements[] = new Requirement($day, self::KID, $shift, 1, 1, 1);
                $requirements[] = new Requirement($day, self::GUSTO, $shift, 2, 3);
                $requirements[] = new Requirement($day, self::VILLA, $shift, 2, 3);
            }
        }

        return $this->problem([
            ...array_map(fn ($id) => $this->waiter($id, self::GUSTO), range(1, 9)),
            ...array_map(fn ($id) => $this->waiter($id, self::VILLA), range(11, 19)),
        ], $requirements, ['time_limit' => 4, 'outlet_parents' => [self::KID => self::GUSTO], ...$options]);
    }

    /** @return array<string, int[]> "date|shift" => employeeIds đứng Kid */
    private function kid(SolveResult $result): array
    {
        $map = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift' && $row['outlet_id'] === self::KID) {
                $map["{$row['date']}|{$row['shift_id']}"][] = $row['employee_id'];
            }
        }

        return $map;
    }

    public function test_kid_deline_has_one_gusto_waiter_each_shift(): void
    {
        $result = $this->solve($this->kidProblem());
        $kid = $this->kid($result);

        $this->assertSame(SolveResult::FEASIBLE, $result->status);
        $this->assertCount(14, $kid, 'Mỗi ngày Kid-Deline có ca sáng và ca chiều');

        foreach ($kid as $key => $ids) {
            $this->assertCount(1, $ids, "{$key}: đúng 1 người");
            $this->assertLessThan(11, $ids[0], "{$key}: Gusto đủ người thì không lấy người Villa");
        }

        $this->assertSame(0, $result->softConstraintScores['objective']['child_borrow']);
    }

    public function test_villa_waiter_covers_kid_only_when_gusto_is_short(): void
    {
        // Thứ Tư: chỉ còn 4 người Gusto đi làm được (5 người nghỉ phép) – vừa đủ tối thiểu nhà hàng Gusto.
        $wednesday = $this->day(2);
        $hard = array_fill_keys(range(1, 5), [$wednesday => 'Nghỉ phép (giả lập)']);
        $result = $this->solve($this->kidProblem(['hard' => $hard]));
        $kid = $this->kid($result);

        $this->assertSame([], array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min'));
        $this->assertGreaterThanOrEqual(11, max(...$kid["{$wednesday}|" . self::M6], ...$kid["{$wednesday}|" . self::A2]), 'Gusto thiếu người → lấy nữ Villa đứng Kid');

        foreach ($kid as $key => $ids) {
            if (! str_starts_with($key, $wednesday)) {
                $this->assertLessThan(11, $ids[0], "{$key}: ngày Gusto đủ người vẫn dùng người Gusto");
            }
        }
    }

    public function test_kid_deline_staff_never_go_to_lobby(): void
    {
        $days = $this->days();
        $coverage = array_map(fn ($d) => new Coverage($d, 14 * 60, 17 * 60, 1, 2, 'Lobby chiều', [self::A2]), $days);
        $result = $this->solve($this->kidProblem(['coverage' => $coverage]));

        $this->assertEmpty(array_filter($result->assignments, fn ($r) => $r['outlet_id'] === self::KID && $r['support']), 'Người đứng Kid không đi Lobby (L)');
        $this->assertCount(14, $this->kid($result));
    }
}
