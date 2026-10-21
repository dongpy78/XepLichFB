<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;

class Period extends BaseModel
{
    protected $table = 'roster_periods';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'version',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'published_by',
        'published_at',
        'off_confirmed_by',
        'off_confirmed_at',
        'note',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'confirmed_at' => 'datetime',
        'published_at' => 'datetime',
        'off_confirmed_at' => 'datetime',
        'version' => 'int',
    ];
}
