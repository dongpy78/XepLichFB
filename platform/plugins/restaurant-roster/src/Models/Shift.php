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

    // Lý do khóa ô: thuật toán không bao giờ đổi ô đã khóa; chỉ người có quyền mới mở khóa / gỡ OFF.
    public const LOCK_OFF_REQUEST = 'off_request';
    public const LOCK_CONFIRMED = 'confirmed';
    public const LOCK_BQ = 'bq';
    public const LOCK_MANUAL = 'manual';
    // Ca nhân viên xin (M6 / A2…) – quản lý ghi trên bảng xếp ca và khóa như OFF xin.
    public const LOCK_SHIFT_REQUEST = 'shift_request';

    // Trạng thái ô (suy ra, xem cellState()).
    public const STATE_OFF_LOCKED = 'off_locked';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_REVIEW = 'review';
    public const STATE_DRAFT = 'draft';

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
        'lock_reason',
        'locked_by',
        'locked_at',
        'availability_request_id',
        'needs_review',
        'review_note',
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
        'needs_review' => 'bool',
        'locked_at' => 'datetime',
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

    public function availabilityRequest(): BelongsTo
    {
        return $this->belongsTo(AvailabilityRequest::class, 'availability_request_id');
    }

    /**
     * Trạng thái nghiệp vụ của ô: OFF đã ghi & khóa / ca đã xác nhận & khóa / cần xem xét / dự thảo.
     */
    public function cellState(): string
    {
        return match (true) {
            $this->needs_review => self::STATE_REVIEW,
            $this->is_locked && $this->kind !== self::KIND_SHIFT => self::STATE_OFF_LOCKED,
            $this->is_locked => self::STATE_CONFIRMED,
            default => self::STATE_DRAFT,
        };
    }

    /**
     * Ô OFF / nghỉ do quản lý ghi từ đơn OFF (gỡ bằng "Gỡ OFF", cần quyền roster.approve).
     */
    public function isRecordedOff(): bool
    {
        return $this->lock_reason === self::LOCK_OFF_REQUEST && $this->kind !== self::KIND_SHIFT;
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
