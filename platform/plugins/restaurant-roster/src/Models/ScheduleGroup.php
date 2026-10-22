<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nhóm hiển thị trên Bảng xếp ca (GUS, Villa, Night, Trainer…). Chỉ quyết định nhân viên nằm ở khu vực nào của bảng
 * và theo thứ tự nào – không đổi outlet làm việc, không ảnh hưởng thuật toán xếp lịch.
 */
class ScheduleGroup extends BaseModel
{
    protected $table = 'roster_schedule_groups';

    protected $fillable = [
        'code',
        'name',
        'outlet_id',
        'order',
        'is_active',
        'description',
    ];

    protected $casts = [
        'order' => 'int',
        'is_active' => 'bool',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'schedule_group_id');
    }

    /** Outlet có dòng thống kê định biên hiện dưới nhóm (tùy chọn). */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
