<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Quy tắc mượn nhân sự giữa các outlet (quản lý 23/10/2026), tách riêng để đổi thứ tự ưu tiên / mức phạt không phải sửa bộ giải:
 * - Chỉ mượn để đủ ĐỊNH MỨC TỐI THIỂU của từng ca (tổng, nam waiter, nữ waiter) – thiếu so với mục tiêu thì không mượn.
 *   Người mượn vượt phần còn thiếu tối thiểu bị phạt "borrow_excess" (nặng hơn thiếu mục tiêu, nhẹ hơn thiếu tối thiểu).
 * - Ưu tiên 1: nhân viên đa năng của outlet Night (mọi người thuộc outlet Night trừ Night cứng và Room Service chuyên trách),
 *   ưu tiên 2: người dư của outlet khác – mỗi ô mượn người không đa năng bị phạt nhẹ "borrow_order".
 * - Không làm outlet nguồn dưới tối thiểu: đã bảo đảm bởi "source_shortage" của bộ giải; OFF đã duyệt, ô khóa, trùng giờ là ràng buộc cứng.
 * Không tính là "mượn": đi Night luân phiên, hỗ trợ Lobby, đứng khu trực thuộc (Kid-Deline) – các việc này có quy tắc riêng.
 */
final class BorrowPolicy
{
    /** @var array<int, true>|null outlet có ca đêm (Night) */
    private ?array $nightOutlets = null;

    public function __construct(private readonly Problem $problem)
    {
    }

    /**
     * Ô này là mượn người: làm ở outlet khác outlet chính, không phải Night / trạm Lobby / khu trực thuộc của outlet chính.
     */
    public function isBorrowed(EmployeeSpec $employee, int $outletId): bool
    {
        if ($employee->homeOutletId === null || $outletId === $employee->homeOutletId || $outletId === $this->problem->stationOutletId
            || ($this->problem->outletParents[$outletId] ?? null) === $employee->homeOutletId) {
            return false;
        }

        if ($this->nightOutlets === null) {
            $this->nightOutlets = [];

            foreach ($this->problem->requirements as $requirement) {
                if ($this->problem->shifts[$requirement->shiftId]->category() === 'night') {
                    $this->nightOutlets[$requirement->outletId] = true;
                }
            }
        }

        return ! isset($this->nightOutlets[$outletId]);
    }

    /**
     * Số người mượn vượt phần thực sự cần để đủ tối thiểu (0 = mọi người mượn đều cần).
     * Tính cho cả outlet trong ngày (cộng các ca M6 / A2 …): người nhà chia lệch giữa hai ca thì bộ giải đổi ca người nhà,
     * không mượn người. Người mượn chỉ "cần" khi bù đúng phần thiếu: thiếu tổng, thiếu nam waiter (người mượn là nam), thiếu nữ waiter (là nữ).
     *
     * @param array{0: int, 1: int, 2: int} $minimum  [tổng, nam waiter, nữ waiter] tối thiểu
     * @param array{0: int, 1: int, 2: int} $have     [tổng, nam waiter, nữ waiter] đang làm
     * @param array{0: int, 1: int, 2: int} $borrowed [tổng, nam waiter, nữ waiter] trong đó là người mượn
     */
    public static function excess(array $minimum, array $have, array $borrowed): int
    {
        if ($borrowed[0] <= 0) {
            return 0;
        }

        $totalShort = max(0, $minimum[0] - ($have[0] - $borrowed[0]));
        $maleShort = max(0, $minimum[1] - ($have[1] - $borrowed[1]));
        $femaleShort = max(0, $minimum[2] - ($have[2] - $borrowed[2]));
        $useful = max(min($borrowed[0], $totalShort), min($borrowed[1], $maleShort) + min($borrowed[2], $femaleShort));

        return max(0, $borrowed[0] - $useful);
    }

    /**
     * Ai có thể được mượn cho ca đang thiếu tối thiểu, theo thứ tự ưu tiên, và lý do từng người không mượn được.
     *
     * @param string $need 'male' | 'female' | 'total'
     * @return array{flex: string[], others: string[], options: array<int, array{employee_id: int, name: string, from: string, flex: bool, source_after: int, source_min: int}>}
     */
    public function candidates(ScheduleState $state, int $i, Requirement $requirement, string $need): array
    {
        $result = ['flex' => [], 'others' => [], 'options' => []];
        $date = $state->dates[$i];

        foreach ($this->problem->employees as $employeeId => $employee) {
            if (! $this->isBorrowed($employee, $requirement->outletId)
                || $employee->skillLevel($requirement->outletId, $requirement->shiftId) === null
                || ($need === 'male' && ! $employee->countsAsMale()) || ($need === 'female' && ! $employee->isFemaleStaff)) {
                continue;
            }

            $bucket = $employee->isNightFlex() ? 'flex' : 'others';
            $name = $employee->name . ' (' . $this->problem->outletName($employee->homeOutletId) . ')';
            $current = $state->shiftAt($employeeId, $i);
            $fixed = $state->fixed[$employeeId][$i] ?? null;

            if ($reason = $this->problem->hardUnavailable[$employeeId][$date] ?? null) {
                $result[$bucket][] = "{$name}: OFF đã duyệt ({$reason})";
            } elseif ($fixed) {
                $result[$bucket][] = "{$name}: ô đã khóa";
            } elseif ($current && $current[0] === $requirement->outletId) {
                $result[$bucket][] = "{$name}: đang làm {$this->problem->shifts[$current[1]]->code} tại outlet này";
            } elseif ($current) {
                [$o, $s] = $current;
                $source = $state->requirements[$i]["{$o}|{$s}"] ?? null;
                $after = $state->count($i, (int) $o, $s) - 1;
                $keeps = ! $source || ($after >= $source->min
                    && $state->maleCount($i, (int) $o, $s) - ($employee->countsAsMale() ? 1 : 0) >= $source->minMale
                    && $state->femaleCount($i, (int) $o, $s) - ($employee->isFemaleStaff ? 1 : 0) >= $source->minFemale);

                if (! $keeps) {
                    $result[$bucket][] = sprintf('%s: %s %s sẽ dưới tối thiểu (còn %d / %d)', $name, $this->problem->outletName((int) $o), $this->problem->shifts[$s]->code, $after, $source->min);
                } elseif ($blocked = $this->blockedMove($state, $employeeId, $i, $requirement)) {
                    $result[$bucket][] = "{$name}: {$blocked}";
                } else {
                    $result['options'][] = ['employee_id' => $employeeId, 'name' => $employee->name, 'from' => $this->problem->outletName((int) $o) . ' ' . $this->problem->shifts[$s]->code,
                        'flex' => $bucket === 'flex', 'source_after' => $after, 'source_min' => $source?->min ?? 0];
                }
            } elseif ($blocked = $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId)) {
                $result[$bucket][] = "{$name}: {$blocked[1]}";
            } else {
                $result['options'][] = ['employee_id' => $employeeId, 'name' => $employee->name, 'from' => 'đang OFF theo lịch (đủ ngày làm?)', 'flex' => $bucket === 'flex', 'source_after' => 0, 'source_min' => 0];
            }
        }

        usort($result['options'], fn ($a, $b) => [! $a['flex'], $b['source_after'] - $b['source_min']] <=> [! $b['flex'], $a['source_after'] - $a['source_min']]);

        return $result;
    }

    private function blockedMove(ScheduleState $state, int $employeeId, int $i, Requirement $requirement): ?string
    {
        $current = $state->shiftAt($employeeId, $i);
        $state->remove($employeeId, $i);
        $blocked = $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId);
        $state->place($employeeId, $i, (int) $current[0], $current[1], (bool) $current[3]);

        return $blocked[1] ?? null;
    }
}
