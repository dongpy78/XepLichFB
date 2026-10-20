<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftTransition extends BaseModel
{
    protected $table = 'roster_shift_transitions';

    protected $fillable = [
        'from_template_id',
        'to_template_id',
        'rule',
        'note',
    ];

    public function fromTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'from_template_id');
    }

    public function toTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'to_template_id');
    }
}
