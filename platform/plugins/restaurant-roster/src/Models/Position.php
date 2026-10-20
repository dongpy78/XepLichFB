<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends BaseModel
{
    protected $table = 'roster_positions';

    protected $fillable = [
        'name',
        'description',
        'order',
        'status',
    ];

    protected $casts = [
        'status' => BaseStatusEnum::class,
        'name' => SafeContent::class,
        'description' => SafeContent::class,
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }
}
