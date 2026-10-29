<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends BaseModel
{
    protected $table = 'roster_employees';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'birthday',
        'outlet_id',
        'schedule_group_id',
        'schedule_order',
        'position_id',
        'fixed_shift_template_id',
        'night_role',
        'can_room_service',
        'is_trainee',
        'mentor_id',
        'training_from',
        'training_to',
        'training_note',
        'employment_type',
        'weekly_hours',
        'gender',
        'residence',
        'hire_date',
        'days_off_per_week',
        'auto_schedule',
        'status',
    ];

    protected $casts = [
        'status' => BaseStatusEnum::class,
        'name' => SafeContent::class,
        'weekly_hours' => 'float',
        'days_off_per_week' => 'int',
        'auto_schedule' => 'bool',
        'is_trainee' => 'bool',
        'can_room_service' => 'bool',
        'schedule_order' => 'int',
        'training_from' => 'date',
        'training_to' => 'date',
    ];

    public function fixedShift(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'fixed_shift_template_id');
    }

    public const NIGHT_FLEX = 'flex';

    /** Night cố định không chọn ở đây: là người có ca cứng là ca đêm (quản lý chọn ở mục Ca cứng). */
    public const NIGHT_CORE = 'core';

    public const NIGHT_ROLES = [
        self::NIGHT_CORE => 'Night cố định – thuật toán tự xếp ca đêm',
        self::NIGHT_FLEX => 'Night đa năng (Night / Room Service / hỗ trợ nhà hàng)',
    ];

    /**
     * Night cố định = ca cứng là ca đêm (bắt đầu từ 20:00 đến trước 04:00), ví dụ N.
     */
    public function isNightCore(): bool
    {
        // Bật "Night cố định" trong hồ sơ (quản lý 29/10/2026), hoặc dữ liệu cũ: ca cứng là ca đêm.
        if ($this->night_role === self::NIGHT_CORE) {
            return true;
        }

        $start = $this->fixedShift?->start_time;

        if (! $start) {
            return false;
        }

        $hour = (int) substr((string) $start, 0, 2);

        return $hour >= 20 || $hour < 4;
    }

    /** Nhóm hiển thị trên Bảng xếp ca (không phải outlet làm việc). */
    public function scheduleGroup(): BelongsTo
    {
        return $this->belongsTo(ScheduleGroup::class, 'schedule_group_id');
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mentor_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_id');
    }

    public function availabilityRequests(): HasMany
    {
        return $this->hasMany(AvailabilityRequest::class, 'employee_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id')->withDefault();
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id')->withDefault();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'employee_id');
    }

    public function getInitialsAttribute(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->name)) ?: [];

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[count($words) - 2], 0, 1) . mb_substr(end($words), 0, 1));
        }

        return mb_strtoupper(mb_substr((string) $this->name, 0, 2));
    }

    /**
     * Sinh nhật chuẩn hóa dạng "j/n" (bỏ số 0 đầu) để so với ngày trong tuần.
     */
    /**
     * Sinh nhật trong tháng hiện tại và chưa qua (tính cả đúng ngày sinh nhật) – để tô nổi bật trên Bảng xếp ca.
     */
    public function hasUpcomingBirthdayThisMonth(?\Carbon\CarbonInterface $today = null): bool
    {
        if (! preg_match('/^(\d{1,2})\/(\d{1,2})$/', (string) $this->birthday, $match)) {
            return false;
        }

        $today ??= \Carbon\Carbon::today();

        return (int) $match[2] === $today->month && (int) $match[1] >= $today->day;
    }

    public function getBirthdayKeyAttribute(): ?string
    {
        if (! preg_match('/^(\d{1,2})\/(\d{1,2})$/', (string) $this->birthday, $match)) {
            return null;
        }

        return (int) $match[1] . '/' . (int) $match[2];
    }

    public function getEmploymentTypeLabelAttribute(): string
    {
        return config('plugins.restaurant-roster.general.employment_types.' . $this->employment_type, (string) $this->employment_type);
    }
}
