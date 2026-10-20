<?php

namespace Botble\RestaurantRoster\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Chọn dòng định biên áp dụng cho một ngày × outlet × ca.
 *
 * Thứ tự ưu tiên: dòng cho ngày cụ thể > dòng theo thứ trong tuần > dòng chung;
 * cùng mức thì dòng có điều kiện tầng trên khớp > dòng không điều kiện.
 * Outlet đóng cửa trong ngày → không có định biên.
 */
final class RequirementResolver
{
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
        $best = null;
        $bestRank = -1;

        foreach ($this->rules as $rule) {
            if ((int) $rule['outlet_id'] !== $outletId || (int) $rule['shift_template_id'] !== $shiftId) {
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

            $rank = ($ruleDate !== null ? 4 : ($ruleWeekday !== null ? 2 : 0)) + ($ruleFloor !== null ? 1 : 0);

            if ($rank > $bestRank) {
                $best = $rule;
                $bestRank = $rank;
            }
        }

        if (! $best) {
            return null;
        }

        return new Requirement(
            date: $date,
            outletId: $outletId,
            shiftId: $shiftId,
            min: (int) $best['min_staff'],
            target: max((int) $best['min_staff'], (int) $best['target_staff']),
            max: isset($best['max_staff']) ? (int) $best['max_staff'] : null,
            minMale: (int) ($best['min_male'] ?? 0),
            targetMale: max((int) ($best['min_male'] ?? 0), (int) ($best['target_male'] ?? 0)),
            allowReduce: (bool) ($best['allow_reduce'] ?? true),
        );
    }
}
