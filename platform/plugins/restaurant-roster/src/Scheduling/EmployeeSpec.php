<?php

namespace Botble\RestaurantRoster\Scheduling;

final class EmployeeSpec
{
    public const PRIMARY = 'primary';
    public const ELIGIBLE = 'eligible';

    /**
     * @param array<string, string> $skills "outletId:shiftId" => primary|eligible; shiftId 0 = mọi ca của outlet.
     *                                      Không có khóa nào khớp = không đủ điều kiện.
     * @param string[] $fixed Danh sách "outletId:shiftId" là ca cứng; nếu không rỗng, nhân viên chỉ được làm các ca này.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $gender,
        public readonly ?int $homeOutletId,
        public readonly float $weeklyHours,
        public readonly int $daysOffPerWeek,
        public readonly array $skills,
        public readonly array $fixed = [],
        public readonly ?string $residence = null,
        public readonly ?int $positionId = null,
        // Cấp quản lý (Junior Restaurant Captain trở lên): chỉ tự xếp các ca $managerShiftIds tại outlet chính, không đi Lobby.
        public readonly bool $isManager = false,
        /** @var int[]|null Ca quản lý được tự xếp (M6, A2); null = không giới hạn ca */
        public readonly ?array $managerShiftIds = null,
    ) {
    }

    public function isMale(): bool
    {
        return $this->gender === 'male';
    }

    /**
     * Mức năng lực với outlet/ca; null = không đủ điều kiện (ràng buộc cứng).
     */
    public function skillLevel(int $outletId, int $shiftId): ?string
    {
        $level = $this->skills["{$outletId}:{$shiftId}"] ?? $this->skills["{$outletId}:0"] ?? null;

        if ($level === null) {
            return null;
        }

        // Quản lý: thuật toán không tự điều chuyển sang outlet khác và chỉ tự xếp M6 / A2 (OH, M9 do quản lý xếp tay).
        if ($this->isManager && ($outletId !== $this->homeOutletId
            || ($this->managerShiftIds !== null && ! in_array($shiftId, $this->managerShiftIds, true)))) {
            return null;
        }

        if ($this->fixed && ! in_array("{$outletId}:{$shiftId}", $this->fixed, true) && ! in_array("{$outletId}:0", $this->fixed, true)) {
            return null;
        }

        return $level;
    }

    /**
     * Được cấu hình năng lực hỗ trợ trạm (Lobby): dòng năng lực tại outlet trạm, mọi ca hoặc đúng ca.
     */
    public function canSupport(?int $stationOutletId, int $shiftId): bool
    {
        return $stationOutletId !== null && ! $this->isManager
            && (isset($this->skills["{$stationOutletId}:0"]) || isset($this->skills["{$stationOutletId}:{$shiftId}"]));
    }

    public function maxWorkDaysPerWeek(): int
    {
        return max(0, 7 - $this->daysOffPerWeek);
    }
}
