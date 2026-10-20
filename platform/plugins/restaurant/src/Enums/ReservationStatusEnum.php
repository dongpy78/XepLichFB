<?php

namespace Botble\Restaurant\Enums;

use Botble\Base\Supports\Enum;

/**
 * @method static ReservationStatusEnum PENDING()
 * @method static ReservationStatusEnum CONFIRMED()
 * @method static ReservationStatusEnum CANCELLED()
 * @method static ReservationStatusEnum COMPLETED()
 */
class ReservationStatusEnum extends Enum
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const COMPLETED = 'completed';

    public static $langPath = 'plugins/restaurant::enums.reservation-status';

    public function toHtml(): string
    {
        return match ($this->value) {
            self::PENDING => '<span class="badge bg-warning text-warning-fg">' . self::PENDING()->label() . '</span>',
            self::CONFIRMED => '<span class="badge bg-info text-info-fg">' . self::CONFIRMED()->label() . '</span>',
            self::CANCELLED => '<span class="badge bg-danger text-danger-fg">' . self::CANCELLED()->label() . '</span>',
            self::COMPLETED => '<span class="badge bg-success text-success-fg">' . self::COMPLETED()->label() . '</span>',
            default => parent::toHtml(),
        };
    }
}
