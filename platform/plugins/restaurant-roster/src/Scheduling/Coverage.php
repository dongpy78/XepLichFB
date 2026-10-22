<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Định biên phủ trạm hỗ trợ (Lobby) trong một khung giờ của một ngày.
 * Tối thiểu / mục tiêu là số người CÓ MẶT CÙNG LÚC trong suốt khung (xem LobbyCoverage): ca làm trọn ở trạm (M9),
 * hoặc ca có ký hiệu (L) trong khung giờ (L) của ca (OH(L), M6(L), A2(L)).
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
        /** Chỉ đếm nữ waiter (EmployeeSpec::isFemaleStaff) – vd Lobby: tối thiểu 2 nữ waiter đồng thời 09:00–16:45. */
        public readonly bool $femaleOnly = false,
    ) {
    }
}
