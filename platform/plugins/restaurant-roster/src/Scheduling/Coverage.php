<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Định biên phủ trạm hỗ trợ (Lobby) trong một khung giờ của một ngày.
 * Người được tính là phủ nếu có mặt ở trạm >= tỉ lệ cấu hình của khung
 * (ca làm trọn ở trạm như OH, M9, hoặc ca có ký hiệu (L) trong khung giờ (L) của ca).
 */
final class Coverage
{
    public function __construct(
        public readonly string $date,
        public readonly int $start,
        public readonly int $end,
        public readonly int $min,
        public readonly int $target,
        public readonly string $label = 'Lobby',
        /** @var int[]|null Chỉ các ca này được tính phủ khung (vd sáng: OH, M9; chiều: A2). null = mọi ca. */
        public readonly ?array $shiftIds = null,
    ) {
    }
}
