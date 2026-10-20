<?php

namespace Botble\Restaurant\Enums;

use Botble\Base\Supports\Enum;

/**
 * @method static MenuStatusEnum PUBLISHED()
 * @method static MenuStatusEnum DRAFT()
 * @method static MenuStatusEnum HIDDEN()
 */
class MenuStatusEnum extends Enum
{
    public const PUBLISHED = 'published';
    public const DRAFT = 'draft';
    public const HIDDEN = 'hidden';

    public static $langPath = 'plugins/restaurant::enums.menu-status';

    public function toHtml(): string
    {
        return match ($this->value) {
            self::PUBLISHED => '<span class="badge bg-success text-success-fg">' . self::PUBLISHED()->label() . '</span>',
            self::DRAFT => '<span class="badge bg-warning text-warning-fg">' . self::DRAFT()->label() . '</span>',
            self::HIDDEN => '<span class="badge bg-danger text-danger-fg">' . self::HIDDEN()->label() . '</span>',
            default => parent::toHtml(),
        };
    }
}
