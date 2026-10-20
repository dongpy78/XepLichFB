<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends BaseModel
{
    public const KIND_SHIFT = 'shift';
    public const KIND_OFF = 'off';
    public const KIND_LEAVE = 'leave';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $table = 'roster_shifts';

    protected $fillable = [
        'employee_id',
        'outlet_id',
        'date',
        'kind',
        'shift_template_id',
        'leave_type',
        'tags',
        'start_at',
        'end_at',
        'split_start_at',
        'split_end_at',
        'break_minutes',
        'overtime_hours',
        'overtime_status',
        'overtime_approved_by',
        'overtime_approved_at',
        'hours',
        'note',
        'status',
        'is_locked',
        'source',
        'run_id',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'tags' => 'array',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'split_start_at' => 'datetime',
        'split_end_at' => 'datetime',
        'overtime_hours' => 'float',
        'hours' => 'float',
        'break_minutes' => 'int',
        'is_locked' => 'bool',
        'overtime_approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id')->withDefault();
    }

    public function isWorking(): bool
    {
        return $this->kind === self::KIND_SHIFT && $this->start_at && $this->end_at;
    }

    /**
     * Thời điểm kết thúc thực tế: hết đoạn cuối của ca (kể cả ca gãy) cộng giờ tăng ca nối tiếp.
     */
    public function finishAt(): ?Carbon
    {
        $end = $this->split_end_at ?: $this->end_at;

        return $end?->copy()->addMinutes((int) round($this->overtime_hours * 60));
    }

    /**
     * Ký hiệu đầy đủ giống bảng Excel, ví dụ "M6(L)(BQ)+4".
     */
    public function getNotationAttribute(): string
    {
        return match ($this->kind) {
            self::KIND_OFF => 'OFF',
            self::KIND_LEAVE => (string) $this->leave_type,
            default => ($this->template?->code ?? 'Ca')
                . collect($this->tags ?: [])->map(fn ($tag) => "({$tag})")->implode('')
                . ($this->overtime_hours > 0 ? '+' . rtrim(rtrim(number_format($this->overtime_hours, 1, '.', ''), '0'), '.') : ''),
        };
    }
}
