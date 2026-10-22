<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cấu hình nhân sự Room Service: chuyên trách một ca (lead), dự phòng (backup), đã xác nhận đủ kỹ năng thay thế (substitute).
 * Đổi người chuyên trách = kết thúc hiệu lực dòng cũ và tạo dòng mới – lịch sử không bị xóa.
 */
class RoomServiceStaff extends BaseModel
{
    public const ROLE_LEAD = 'lead';
    public const ROLE_BACKUP = 'backup';
    public const ROLE_SUBSTITUTE = 'substitute';

    public const ROLES = [
        self::ROLE_LEAD => 'Chuyên trách',
        self::ROLE_BACKUP => 'Dự phòng',
        self::ROLE_SUBSTITUTE => 'Có thể thay thế (đã xác nhận kỹ năng)',
    ];

    protected $table = 'roster_room_service_staff';

    protected $fillable = [
        'employee_id',
        'role',
        'shift_template_id',
        'effective_from',
        'effective_to',
        'is_active',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'bool',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    /** Dòng đang hoạt động và có hiệu lực trong khoảng [from, to] (giao nhau). */
    public function scopeEffective(Builder $query, CarbonInterface $from, ?CarbonInterface $to = null): Builder
    {
        $to ??= $from;

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()));
    }

    public function coversDate(string $date): bool
    {
        return $this->is_active && (! $this->effective_from || $this->effective_from->toDateString() <= $date)
            && (! $this->effective_to || $this->effective_to->toDateString() >= $date);
    }

    public function roleLabel(): string
    {
        return (self::ROLES[$this->role] ?? $this->role) . ($this->role === self::ROLE_LEAD && $this->template ? ' ' . $this->template->code : '');
    }

    public function snapshot(): array
    {
        return [
            'employee_id' => $this->employee_id, 'role' => $this->role, 'shift_template_id' => $this->shift_template_id,
            'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active, 'note' => $this->note,
        ];
    }
}
