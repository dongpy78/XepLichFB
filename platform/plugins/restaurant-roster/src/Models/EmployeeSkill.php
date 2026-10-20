<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSkill extends BaseModel
{
    protected $table = 'roster_employee_skills';

    protected $fillable = [
        'employee_id',
        'outlet_id',
        'shift_template_id',
        'level',
        'is_fixed',
        'note',
    ];

    protected $casts = [
        'is_fixed' => 'bool',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }
}
