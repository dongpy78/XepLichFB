<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Ô lịch thuật toán không được thay đổi: ô quản lý đã khóa, hoặc ô ngoài phạm vi xếp
 * (dùng làm ngữ cảnh để kiểm tra giờ nghỉ, số ngày làm liên tục, số ngày OFF trong tuần).
 */
final class FixedAssignment
{
    public const KIND_SHIFT = 'shift';
    public const KIND_OFF = 'off';
    public const KIND_LEAVE = 'leave';

    public function __construct(
        public readonly int $employeeId,
        public readonly string $date,
        public readonly string $kind,
        public readonly ?int $outletId = null,
        public readonly ?int $shiftId = null,
        public readonly float $overtimeHours = 0,
        public readonly string $source = 'locked',
        // Ô có ký hiệu (L): đang hỗ trợ Lobby trong khung giờ của ca.
        public readonly bool $support = false,
    ) {
    }

    public function isWorking(): bool
    {
        return $this->kind === self::KIND_SHIFT && $this->shiftId !== null;
    }
}
