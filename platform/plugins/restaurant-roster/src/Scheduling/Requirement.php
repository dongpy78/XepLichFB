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
    ) {
    }

    /**
     * Bản sao với vài giá trị thay đổi (sức chứa ngày đông khách, tối thiểu nam, tối thiểu theo vị trí theo cấu hình).
     */
    public function adjusted(?int $max = null, ?int $minMale = null, ?array $positionMins = null): self
    {
        $minMale ??= $this->minMale;

        return new self($this->date, $this->outletId, $this->shiftId, $this->min, $this->target, $max ?? $this->max,
            $minMale, max($minMale, $this->targetMale), $this->allowReduce, $positionMins ?? $this->positionMins);
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
        return max($this->min, $this->target, $this->max ?? 0, $this->minMale, $this->targetMale);
    }
}
