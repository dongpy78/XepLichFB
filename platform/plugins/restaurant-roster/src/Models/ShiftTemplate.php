<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Models\BaseModel;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ShiftTemplate extends BaseModel
{
    protected $table = 'roster_shift_templates';

    protected $fillable = [
        'code',
        'name',
        'start_time',
        'end_time',
        'split_start_time',
        'split_end_time',
        'break_minutes',
        'color',
        'support_station',
        'lobby_start_time',
        'lobby_end_time',
        'allowed_residence',
        'allowed_gender',
        'allowed_position_id',
        'order',
        'status',
    ];

    protected $casts = [
        'status' => BaseStatusEnum::class,
        'name' => SafeContent::class,
        'code' => SafeContent::class,
        'break_minutes' => 'int',
    ];

    public function isSplit(): bool
    {
        return $this->split_start_time && $this->split_end_time;
    }

    /**
     * Khung giờ hiển thị, ví dụ "06:00–14:30" hoặc "10:30–14:30 & 17:30–21:30".
     */
    public function getTimeRangeAttribute(): string
    {
        $range = $this->hm($this->start_time) . '–' . $this->hm($this->end_time);

        if ($this->isSplit()) {
            $range .= ' & ' . $this->hm($this->split_start_time) . '–' . $this->hm($this->split_end_time);
        }

        return $range;
    }

    /**
     * Tính mốc thời gian thực tế của ca khi gán vào một ngày; ca qua đêm được đẩy sang ngày hôm sau.
     *
     * @return array{start_at: Carbon, end_at: Carbon, split_start_at: ?Carbon, split_end_at: ?Carbon, hours: float}
     */
    public function resolveForDate(CarbonInterface $date): array
    {
        [$startAt, $endAt] = $this->segment($date, $this->start_time, $this->end_time);
        $minutes = $startAt->diffInMinutes($endAt);

        $splitStartAt = $splitEndAt = null;

        if ($this->isSplit()) {
            [$splitStartAt, $splitEndAt] = $this->segment($date, $this->split_start_time, $this->split_end_time);

            if ($splitStartAt->lt($endAt)) {
                $splitStartAt->addDay();
                $splitEndAt->addDay();
            }

            $minutes += $splitStartAt->diffInMinutes($splitEndAt);
        }

        $minutes = max(0, $minutes - (int) $this->break_minutes);

        return [
            'start_at' => $startAt,
            'end_at' => $endAt,
            'split_start_at' => $splitStartAt,
            'split_end_at' => $splitEndAt,
            'hours' => round($minutes / 60, 2),
        ];
    }

    public function getHoursAttribute(): float
    {
        return $this->resolveForDate(Carbon::today())['hours'];
    }

    protected function segment(CarbonInterface $date, string $start, string $end): array
    {
        $startAt = Carbon::parse($date->toDateString() . ' ' . $start);
        $endAt = Carbon::parse($date->toDateString() . ' ' . $end);

        if ($endAt->lte($startAt)) {
            $endAt->addDay();
        }

        return [$startAt, $endAt];
    }

    protected function hm(?string $time): string
    {
        return $time ? substr($time, 0, 5) : '';
    }
}
