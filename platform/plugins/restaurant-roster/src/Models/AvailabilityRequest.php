<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AvailabilityRequest extends BaseModel
{
    protected $table = 'roster_availability_requests';

    protected $fillable = [
        'employee_id',
        'date_from',
        'date_to',
        'type',
        'group_label',
        'reason',
        'received_at',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'created_by',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'reviewed_at' => 'datetime',
        'received_at' => 'date',
    ];

    public function cells(): HasMany
    {
        return $this->hasMany(Shift::class, 'availability_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
