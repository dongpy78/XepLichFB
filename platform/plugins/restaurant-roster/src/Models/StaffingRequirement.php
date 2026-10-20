<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffingRequirement extends BaseModel
{
    protected $table = 'roster_staffing_requirements';

    protected $fillable = [
        'outlet_id',
        'shift_template_id',
        'weekday',
        'date',
        'upper_floor',
        'min_staff',
        'target_staff',
        'max_staff',
        'min_male',
        'target_male',
        'allow_reduce',
        'is_confirmed',
        'source',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
        'upper_floor' => 'bool',
        'allow_reduce' => 'bool',
        'is_confirmed' => 'bool',
        'weekday' => 'int',
        'min_staff' => 'int',
        'target_staff' => 'int',
        'max_staff' => 'int',
        'min_male' => 'int',
        'target_male' => 'int',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }
}
