<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletDay extends BaseModel
{
    protected $table = 'roster_outlet_days';

    protected $fillable = [
        'outlet_id',
        'date',
        'upper_floor_open',
        'is_closed',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
        'upper_floor_open' => 'bool',
        'is_closed' => 'bool',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
