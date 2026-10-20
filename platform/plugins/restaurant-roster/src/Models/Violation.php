<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Violation extends BaseModel
{
    protected $table = 'roster_violations';

    protected $fillable = [
        'run_id',
        'date',
        'outlet_id',
        'shift_template_id',
        'employee_id',
        'category',
        'severity',
        'code',
        'message',
        'suggestions',
    ];

    protected $casts = [
        'date' => 'date',
        'suggestions' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
