<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;

/**
 * Nhu cầu Night của một ngày: số nam waiter làm ca đêm (ít khách 2, đông khách 3) do quản lý chọn.
 */
class NightDemand extends BaseModel
{
    public const QUIET = 2;
    public const BUSY = 3;

    public const LABELS = [
        self::QUIET => 'Ít khách – 2 người',
        self::BUSY => 'Đông khách – 3 người',
    ];

    protected $table = 'roster_night_demands';

    protected $fillable = [
        'date',
        'staff',
        'note',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'staff' => 'int',
    ];
}
