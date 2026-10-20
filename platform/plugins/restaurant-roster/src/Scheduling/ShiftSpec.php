<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Một loại ca trong bài toán. Thời gian tính bằng phút kể từ 00:00 của ngày làm;
 * ca qua đêm có phút kết thúc > 1440.
 */
final class ShiftSpec
{
    /**
     * @param array<int, array{0: int, 1: int}> $segments Các đoạn làm việc [bắt đầu, kết thúc]
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly array $segments,
        public readonly float $hours,
        // Khung giờ hỗ trợ Lobby khi ca có ký hiệu (L), phút trong ngày; null = ca không có (L).
        public readonly ?array $lobbyWindow = null,
        // Ca chỉ dành cho nhân viên ở khu vực này (vd OH → nha_trang vì xe đưa đón).
        public readonly ?string $allowedResidence = null,
        // Ca chỉ dành cho một giới tính / một vị trí (vd M9: nữ, Waiter).
        public readonly ?string $allowedGender = null,
        public readonly ?int $allowedPositionId = null,
    ) {
    }

    public function start(): int
    {
        return $this->segments[0][0];
    }

    public function end(): int
    {
        return max(array_column($this->segments, 1));
    }

    /**
     * Phân loại theo giờ bắt đầu thực tế của ca (dùng để cân bằng số ca sáng/chiều/đêm).
     */
    public function category(): string
    {
        $hour = intdiv($this->start(), 60);

        return match (true) {
            $hour >= 20 || $hour < 4 => 'night',
            $hour < 12 => 'morning',
            default => 'afternoon',
        };
    }
}
