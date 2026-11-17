<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Botble\RestaurantRoster\Models\Concerns\FastAttributes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cặp nghỉ chung (Shared Day Off): thuật toán ưu tiên hai người OFF cùng ngày. employee_a_id luôn nhỏ hơn employee_b_id.
 */
class OffPair extends BaseModel
{
    use FastAttributes;
    use SoftDeletes;

    protected $table = 'roster_off_pairs';

    protected $fillable = [
        'employee_a_id',
        'employee_b_id',
        'is_active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function employeeA(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_a_id');
    }

    public function employeeB(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_b_id');
    }

    /** Cả hai còn đang làm (trạng thái nhân viên "đang hoạt động"). */
    public function employeesActive(): bool
    {
        return $this->employeeA?->status?->getValue() === 'published' && $this->employeeB?->status?->getValue() === 'published';
    }
}
