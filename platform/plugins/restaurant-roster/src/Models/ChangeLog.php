<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeLog extends BaseModel
{
    protected $table = 'roster_change_logs';

    protected $fillable = [
        'employee_id',
        'date',
        'before',
        'after',
        'action',
        'run_id',
        'reason',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'before' => 'array',
        'after' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
