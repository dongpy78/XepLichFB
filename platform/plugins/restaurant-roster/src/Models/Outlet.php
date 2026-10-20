<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Outlet extends BaseModel
{
    protected $table = 'roster_outlets';

    protected $fillable = [
        'name',
        'code',
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
        return $this->hasMany(Employee::class, 'outlet_id');
    }
}
