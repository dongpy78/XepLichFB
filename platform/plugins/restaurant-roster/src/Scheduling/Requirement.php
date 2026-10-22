<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Định biên của một outlet × ca trong một ngày.
 */
final class Requirement
{
    public function __construct(
        public readonly string $date,
        public readonly int $outletId,
        public readonly int $shiftId,
        public readonly int $min,
        public readonly int $target,
        public readonly ?int $max = null,
        public readonly int $minMale = 0,
        public readonly int $targetMale = 0,
        public readonly bool $allowReduce = true,
        /** @var array<int|string, int> [nhóm vị trí] => số người tối thiểu (vd "Hostess" ≥ 1, "Quản lý" ≥ 1); khóa số = một positionId */
        public readonly array $positionMins = [],
        // Nữ waiter làm trực tiếp tại outlet (không tính người hỗ trợ Lobby (L) / đứng Kid-Deline trong ca).
        public readonly int $minFemale = 0,
        public readonly int $targetFemale = 0,
        /** @var array<int|string, int> [nhóm vị trí] => số người mục tiêu (>= tối thiểu) */
        public readonly array $positionTargets = [],
        /** @var array{0: int, 1: int}|null Khung đếm (phút trong ngày): người hỗ trợ (L) có mặt ở outlet cả khung này vẫn được tính */
        public readonly ?array $countWindow = null,
        // Dòng định biên (roster_staffing_requirements.id) sinh ra định biên này – để giải thích và lưu kèm lần chạy.
        public readonly ?int $sourceId = null,
    ) {
    }

    /**
     * Bản sao với vài giá trị thay đổi (sức chứa ngày đông khách, tối thiểu nam, tối thiểu theo vị trí theo cấu hình).
     */
    public function adjusted(?int $max = null, ?int $minMale = null, ?array $positionMins = null): self
    {
        $minMale ??= $this->minMale;

        return new self($this->date, $this->outletId, $this->shiftId, $this->min, $this->target, $max ?? $this->max,
            $minMale, max($minMale, $this->targetMale), $this->allowReduce, $positionMins ?? $this->positionMins, $this->minFemale, $this->targetFemale,
            $this->positionTargets, $this->countWindow, $this->sourceId);
    }

    /**
     * Số người mục tiêu của một nhóm vị trí (không thấp hơn tối thiểu).
     */
    public function positionTarget(int|string $group): int
    {
        return max($this->positionMins[$group] ?? 0, $this->positionTargets[$group] ?? 0);
    }

    public function key(): string
    {
        return "{$this->date}|{$this->outletId}|{$this->shiftId}";
    }

    /**
     * Số người tối đa thuật toán được xếp vào ô này.
     */
    public function capacity(): int
    {
        return max($this->min, $this->target, $this->max ?? 0, $this->minMale, $this->targetMale, $this->minFemale, $this->targetFemale);
    }
}
