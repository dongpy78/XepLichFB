<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một dòng định biên: outlet × ca × (ngày cụ thể | thứ | mọi ngày) × (tầng trên) trong khoảng hiệu lực.
 * Chỉ dòng trạng thái "active" được thuật toán xếp lịch dùng; "draft" là nháp, "inactive" là đã ngừng hiệu lực (giữ lịch sử).
 * Nam / nữ waiter: null = không áp dụng. Nhóm theo vị trí nằm ở bảng con positions().
 */
class StaffingRequirement extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Đang áp dụng',
        self::STATUS_DRAFT => 'Nháp (chưa dùng)',
        self::STATUS_INACTIVE => 'Ngừng hiệu lực',
    ];

    public const WEEKDAYS = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ nhật'];

    protected $table = 'roster_staffing_requirements';

    protected $fillable = [
        'outlet_id',
        'shift_template_id',
        'status',
        'weekday',
        'date',
        'effective_from',
        'effective_to',
        'upper_floor',
        'count_start_time',
        'count_end_time',
        'min_staff',
        'target_staff',
        'max_staff',
        'min_male',
        'target_male',
        'min_female',
        'target_female',
        'allow_reduce',
        'is_confirmed',
        'source',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'upper_floor' => 'bool',
        'allow_reduce' => 'bool',
        'is_confirmed' => 'bool',
        'weekday' => 'int',
        'min_staff' => 'int',
        'target_staff' => 'int',
        'max_staff' => 'int',
        'min_male' => 'int',
        'target_male' => 'int',
        'min_female' => 'int',
        'target_female' => 'int',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(StaffingRequirementPosition::class, 'requirement_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(StaffingRequirementRevision::class, 'requirement_id')->latest('id');
    }

    /**
     * Nhãn "áp dụng khi nào", vd "Thứ Bảy", "Ngày 24/12/2026", "Mọi ngày · mở tầng trên".
     */
    public function scheduleLabel(): string
    {
        $label = $this->date ? 'Ngày ' . $this->date->format('d/m/Y') : (self::WEEKDAYS[$this->weekday] ?? 'Mọi ngày');

        return $label . match (true) {
            $this->upper_floor === null => '',
            (bool) $this->upper_floor => ' · mở tầng trên',
            default => ' · không mở tầng trên',
        };
    }

    public function effectiveLabel(): string
    {
        return match (true) {
            ! $this->effective_from && ! $this->effective_to => 'Không giới hạn',
            ! $this->effective_to => 'Từ ' . $this->effective_from->format('d/m/Y'),
            ! $this->effective_from => 'Đến ' . $this->effective_to->format('d/m/Y'),
            default => $this->effective_from->format('d/m/Y') . ' – ' . $this->effective_to->format('d/m/Y'),
        };
    }

    /**
     * Ảnh chụp giá trị (để ghi lịch sử và lưu kèm lần chạy thuật toán).
     */
    public function snapshot(): array
    {
        $this->loadMissing('positions');

        return [
            ...array_intersect_key($this->getAttributes(), array_flip([
                'outlet_id', 'shift_template_id', 'status', 'weekday', 'upper_floor', 'min_staff', 'target_staff', 'max_staff',
                'min_male', 'target_male', 'min_female', 'target_female', 'count_start_time', 'count_end_time', 'allow_reduce', 'is_confirmed', 'note',
            ])),
            'date' => $this->date?->toDateString(),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'positions' => $this->positions->map(fn (StaffingRequirementPosition $p) => [
                'kind' => $p->kind, 'position_id' => $p->position_id, 'min_staff' => $p->min_staff, 'target_staff' => $p->target_staff, 'note' => $p->note,
            ])->values()->all(),
        ];
    }
}
