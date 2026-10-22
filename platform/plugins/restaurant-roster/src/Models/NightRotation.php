<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một nhân viên luân phiên Night trong một tuần (thứ Hai đầu tuần).
 * Đề xuất: thuật toán gợi ý; đã duyệt / đã công bố: bộ giải chỉ xếp Night luân phiên cho đúng những người này trong tuần.
 */
class NightRotation extends BaseModel
{
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_PROPOSED => 'Đề xuất',
        self::STATUS_APPROVED => 'Đã duyệt',
        self::STATUS_PUBLISHED => 'Đã công bố',
    ];

    protected $table = 'roster_night_rotations';

    protected $fillable = [
        'week_start',
        'employee_id',
        'source_outlet_id',
        'status',
        'note',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function sourceOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'source_outlet_id');
    }
}
