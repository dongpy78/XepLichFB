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
        'position_id',
        'fixed_shift_template_id',
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
    ];

    public function fixedShift(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'fixed_shift_template_id');
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
