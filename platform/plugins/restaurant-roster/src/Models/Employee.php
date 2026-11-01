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
        'room_service_fixed',
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
        'room_service_fixed' => 'bool',
        'schedule_order' => 'int',
        'training_from' => 'date',
        'training_to' => 'date',
    ];

    protected static function booted(): void
    {
        // Thứ tự trên Bảng xếp ca (quản lý 01/11/2026): nhân viên mới, hoặc đổi vị trí / giới tính / khu hiển thị mà không tự đặt thứ tự,
        // được đưa xuống cuối nhóm của mình (vd Waiter nữ mới: sau Waiter nữ cuối cùng, trước Waiter nam đầu tiên). Người cũ không đổi.
        static::saving(function (Employee $employee): void {
            $moved = $employee->exists && $employee->isDirty(['position_id', 'gender', 'schedule_group_id', 'outlet_id']) && ! $employee->isDirty('schedule_order');

            if ($employee->schedule_order === null || $moved) {
                $employee->schedule_order = $employee->nextScheduleOrder();
            }
        });
    }

    /**
     * Mã nhân viên kế tiếp (quản lý 01/11/2026): tiền tố employee_code_prefix (FB-) + số lớn nhất đang dùng với tiền tố đó + 1,
     * 4 chữ số (FB-0001, FB-0002…). Mã kiểu khác (dữ liệu cũ) không ảnh hưởng. Bỏ qua mã đã tồn tại (cột code là unique).
     */
    public static function nextCode(): string
    {
        $prefix = (string) config('plugins.restaurant-roster.general.scheduling.employee_code_prefix', 'FB-');
        $number = 0;

        foreach (self::query()->where('code', 'like', $prefix . '%')->pluck('code') as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', trim((string) $code), $m)) {
                $number = max($number, (int) $m[1]);
            }
        }

        do {
            $code = $prefix . str_pad((string) ++$number, 4, '0', STR_PAD_LEFT);
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    /** Vị trí sắp nữ trước nam (gender_order_positions, mặc định Waiter). */
    public static function genderOrderedPositionIds(): array
    {
        $names = array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) config('plugins.restaurant-roster.general.scheduling.gender_order_positions', ['Waiter']));

        return Position::query()->get(['id', 'name'])->filter(fn (Position $p) => in_array(mb_strtolower(trim((string) $p->name)), $names, true))
            ->map(fn (Position $p) => (int) $p->getKey())->values()->all();
    }

    /** Hạng giới trong vị trí sắp theo giới: 0 = nữ, 1 = nam, 2 = chưa khai; vị trí khác luôn 0. */
    public function genderOrderRank(?array $orderedPositionIds = null): int
    {
        if (! in_array((int) $this->position_id, $orderedPositionIds ?? self::genderOrderedPositionIds(), true)) {
            return 0;
        }

        return ['female' => 0, 'male' => 1][$this->gender] ?? 2;
    }

    /** Thứ tự cuối nhóm: cùng khu hiển thị, cùng vị trí (và cùng giới nếu vị trí sắp theo giới) → lớn nhất + 10. */
    public function nextScheduleOrder(): int
    {
        $query = self::query()->whereKeyNot($this->getKey() ?? 0)
            ->when($this->schedule_group_id, fn ($q) => $q->where('schedule_group_id', $this->schedule_group_id),
                fn ($q) => $q->whereNull('schedule_group_id')->where('outlet_id', $this->outlet_id))
            ->when($this->position_id, fn ($q) => $q->where('position_id', $this->position_id), fn ($q) => $q->whereNull('position_id'));

        if (in_array((int) $this->position_id, self::genderOrderedPositionIds(), true)) {
            in_array($this->gender, ['female', 'male'], true)
                ? $query->where('gender', $this->gender)
                : $query->where(fn ($q) => $q->whereNull('gender')->orWhereNotIn('gender', ['female', 'male']));
        }

        return min(9990, (int) $query->max('schedule_order') + 10);
    }

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
