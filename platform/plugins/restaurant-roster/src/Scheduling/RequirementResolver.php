<?php

namespace Botble\RestaurantRoster\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Chọn dòng định biên áp dụng cho một ngày × outlet × ca.
 *
 * Chỉ xét dòng trạng thái "active" (hoặc chưa có trạng thái – dữ liệu cũ) còn hiệu lực vào ngày đó (effective_from ≤ ngày ≤ effective_to).
 * Thứ tự ưu tiên: dòng cho ngày cụ thể > dòng theo thứ trong tuần > dòng chung;
 * cùng mức thì dòng có điều kiện tầng trên khớp > dòng không điều kiện.
 * Hai dòng cùng mức ưu tiên cao nhất mà giá trị khác nhau = XUNG ĐỘT: không tự chọn dòng nào (trả null, ghi vào conflicts()).
 * Outlet đóng cửa trong ngày → không có định biên.
 *
 * Dòng định biên (mảng): cột của roster_staffing_requirements + 'positions' => [['kind', 'position_id', 'min_staff', 'target_staff'], ...].
 * Nhóm vị trí: khóa = position_id (một vị trí) hoặc MANAGER_GROUP (mọi vị trí bật "Cấp quản lý").
 */
final class RequirementResolver
{
    public const MANAGER_GROUP = 'grp:managers';

    /** @var array<string, array{date: string, outlet_id: int, shift_id: int, ids: int[]}> */
    private array $conflicts = [];

    /**
     * @param array<int, array<string, mixed>> $rules Các dòng roster_staffing_requirements (dạng mảng)
     */
    public function __construct(private readonly array $rules)
    {
    }

    public function resolve(string $date, int $outletId, int $shiftId, bool $upperFloorOpen = false, bool $closed = false): ?Requirement
    {
        if ($closed) {
            return null;
        }

        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;
        $candidates = [];
        $bestRank = -1;

        foreach ($this->rules as $rule) {
            if ((int) $rule['outlet_id'] !== $outletId || (int) $rule['shift_template_id'] !== $shiftId
                || ! in_array($rule['status'] ?? 'active', ['active', null], true)) {
                continue;
            }

            $from = isset($rule['effective_from']) ? substr((string) $rule['effective_from'], 0, 10) : null;
            $to = isset($rule['effective_to']) ? substr((string) $rule['effective_to'], 0, 10) : null;

            if (($from && $date < $from) || ($to && $date > $to)) {
                continue;
            }

            $ruleDate = $rule['date'] ?? null;
            $ruleWeekday = $rule['weekday'] ?? null;
            $ruleFloor = $rule['upper_floor'] ?? null;

            if ($ruleDate !== null && substr((string) $ruleDate, 0, 10) !== $date) {
                continue;
            }

            if ($ruleDate === null && $ruleWeekday !== null && (int) $ruleWeekday !== $weekday) {
                continue;
            }

            if ($ruleFloor !== null && (bool) $ruleFloor !== $upperFloorOpen) {
                continue;
            }

            $rank = self::rank($rule);

            if ($rank > $bestRank) {
                $candidates = [$rule];
                $bestRank = $rank;
            } elseif ($rank === $bestRank) {
                $candidates[] = $rule;
            }
        }

        if (! $candidates) {
            return null;
        }

        // Nhiều dòng cùng mức ưu tiên: giống hệt nhau thì dùng dòng đầu; khác nhau là xung đột – không tự chọn.
        $distinct = array_unique(array_map(fn ($rule) => serialize(self::values($rule)), $candidates));

        if (count($distinct) > 1) {
            $this->conflicts["{$date}|{$outletId}|{$shiftId}"] = [
                'date' => $date, 'outlet_id' => $outletId, 'shift_id' => $shiftId,
                'ids' => array_values(array_map(fn ($rule) => (int) ($rule['id'] ?? 0), $candidates)),
            ];

            return null;
        }

        usort($candidates, fn ($a, $b) => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));

        return $this->toRequirement($candidates[0], $date, $outletId, $shiftId);
    }

    /**
     * Xung đột phát hiện được trong các lần resolve().
     *
     * @return array<string, array{date: string, outlet_id: int, shift_id: int, ids: int[]}>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Mức ưu tiên của một dòng: ngày cụ thể 4, theo thứ 2, chung 0; có điều kiện tầng trên +1.
     */
    public static function rank(array $rule): int
    {
        return (($rule['date'] ?? null) !== null ? 4 : (($rule['weekday'] ?? null) !== null ? 2 : 0)) + (($rule['upper_floor'] ?? null) !== null ? 1 : 0);
    }

    /**
     * Phần giá trị dùng để so hai dòng có mâu thuẫn không.
     */
    public static function values(array $rule): array
    {
        $positions = array_map(fn ($p) => [(string) ($p['kind'] ?? 'position'), (int) ($p['position_id'] ?? 0), $p['min_staff'] ?? null, $p['target_staff'] ?? null], $rule['positions'] ?? []);
        sort($positions);

        return [
            (int) ($rule['min_staff'] ?? 0), (int) ($rule['target_staff'] ?? 0), $rule['max_staff'] ?? null,
            $rule['min_male'] ?? null, $rule['target_male'] ?? null, $rule['min_female'] ?? null, $rule['target_female'] ?? null,
            substr((string) ($rule['count_start_time'] ?? ''), 0, 5), substr((string) ($rule['count_end_time'] ?? ''), 0, 5),
            (bool) ($rule['allow_reduce'] ?? true), $positions,
        ];
    }

    private function toRequirement(array $rule, string $date, int $outletId, int $shiftId): Requirement
    {
        $positionMins = [];
        $positionTargets = [];

        foreach ($rule['positions'] ?? [] as $position) {
            $key = ($position['kind'] ?? 'position') === 'managers' ? self::MANAGER_GROUP : (string) (int) ($position['position_id'] ?? 0);

            if ($key === '0' || ($position['min_staff'] === null && $position['target_staff'] === null)) {
                continue;
            }

            $positionMins[$key] = (int) ($position['min_staff'] ?? 0);
            $positionTargets[$key] = max($positionMins[$key], (int) ($position['target_staff'] ?? 0));
        }

        $minutes = fn ($time) => $time ? (int) substr((string) $time, 0, 2) * 60 + (int) substr((string) $time, 3, 2) : null;
        $start = $minutes($rule['count_start_time'] ?? null);
        $end = $minutes($rule['count_end_time'] ?? null);

        return new Requirement(
            date: $date,
            outletId: $outletId,
            shiftId: $shiftId,
            min: (int) $rule['min_staff'],
            target: max((int) $rule['min_staff'], (int) $rule['target_staff']),
            max: isset($rule['max_staff']) ? (int) $rule['max_staff'] : null,
            minMale: (int) ($rule['min_male'] ?? 0),
            targetMale: max((int) ($rule['min_male'] ?? 0), (int) ($rule['target_male'] ?? 0)),
            allowReduce: (bool) ($rule['allow_reduce'] ?? true),
            positionMins: array_filter($positionMins),
            minFemale: (int) ($rule['min_female'] ?? 0),
            targetFemale: max((int) ($rule['min_female'] ?? 0), (int) ($rule['target_female'] ?? 0)),
            positionTargets: array_filter($positionTargets),
            countWindow: $start !== null && $end !== null && $end > $start ? [$start, $end] : null,
            sourceId: isset($rule['id']) ? (int) $rule['id'] : null,
        );
    }
}
