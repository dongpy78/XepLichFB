<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;

/**
 * Một phiên bản lịch tuần đã công bố: bản chụp bảng xếp ca tại thời điểm công bố (không đổi theo dữ liệu chỉnh sửa sau đó).
 */
class ScheduleVersion extends BaseModel
{
    protected $table = 'roster_schedule_versions';

    protected $fillable = [
        'week_start',
        'scope_outlet_id',
        'version',
        'hash',
        'snapshot',
        'employee_count',
        'published_by',
        'published_by_name',
        'published_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'snapshot' => 'array',
        'published_at' => 'datetime',
        'version' => 'int',
        'scope_outlet_id' => 'int',
        'employee_count' => 'int',
    ];

    public static function latestFor(string $weekStart, int $scope): ?self
    {
        return static::query()->whereDate('week_start', $weekStart)->where('scope_outlet_id', $scope)->orderByDesc('version')->first();
    }
}
