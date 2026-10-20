<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Run extends BaseModel
{
    protected $table = 'roster_runs';

    protected $fillable = [
        'period_id',
        'type',
        'scope_from',
        'scope_to',
        'outlet_ids',
        'status',
        'solver',
        'base_version',
        'input_hash',
        'settings',
        'metrics',
        'proposal',
        'explanation',
        'duration_ms',
        'created_by',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'scope_from' => 'date',
        'scope_to' => 'date',
        'outlet_ids' => 'array',
        'settings' => 'array',
        'metrics' => 'array',
        'proposal' => 'array',
        'applied_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }
}
