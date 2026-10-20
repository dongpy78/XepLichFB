<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Kiểm tra xung đột lịch làm việc theo các ngưỡng trong config general.rules:
 * nghỉ giữa hai ca, giờ làm trong ngày, số ngày làm liên tục, giờ tăng ca trong tuần.
 */
class RosterConflictChecker
{
    protected array $rules;

    public function __construct()
    {
        $this->rules = config('plugins.restaurant-roster.general.rules', []) + [
            'min_rest_hours' => 12,
            'max_daily_hours' => 12,
            'max_weekly_overtime' => 12,
            'max_consecutive_days' => 6,
        ];
    }

    /**
     * @param Collection<int, Shift> $shifts Ca của một nhân viên, nên bao gồm cả 7 ngày trước tuần đang xem
     *                                       để tính đúng số ngày làm liên tục và thời gian nghỉ đầu tuần.
     * @return array{cells: array<string, string[]>, hours: float, overtime: float, level: string, messages: string[]}
     */
    public function check(Employee $employee, Collection $shifts, CarbonInterface $weekStart): array
    {
        $weekEnd = $weekStart->copy()->addDays(6);
        $inWeek = fn (Shift $shift) => $shift->date->between($weekStart, $weekEnd);

        $working = $shifts
            ->filter(fn (Shift $shift) => $shift->isWorking())
            ->sortBy('start_at')
            ->values();

        $cells = [];
        $flag = function (Shift $shift, string $message) use (&$cells, $inWeek): void {
            if ($inWeek($shift)) {
                $cells[$shift->date->toDateString()][] = $message;
            }
        };

        // 1. Thời gian nghỉ giữa hai ca liên tiếp.
        foreach ($working as $index => $shift) {
            $previous = $working[$index - 1] ?? null;

            if (! $previous) {
                continue;
            }

            $restHours = $previous->finishAt()->diffInMinutes($shift->start_at, false) / 60;

            if ($restHours < 0) {
                $flag($shift, 'Trùng giờ với ca trước đó');
            } elseif ($restHours < $this->rules['min_rest_hours']) {
                $flag($shift, sprintf('Chỉ nghỉ %sh giữa 2 ca (tối thiểu %sh)', $this->num($restHours), $this->rules['min_rest_hours']));
            }
        }

        // 2. Giờ làm trong một ngày.
        foreach ($working as $shift) {
            if ($shift->hours > $this->rules['max_daily_hours']) {
                $flag($shift, sprintf('Làm %sh/ngày (tối đa %sh)', $this->num($shift->hours), $this->rules['max_daily_hours']));
            }
        }

        // 3. Số ngày làm liên tục.
        $run = 0;
        $lastDate = null;

        foreach ($working->sortBy('date') as $shift) {
            $run = ($lastDate && $lastDate->copy()->addDay()->isSameDay($shift->date)) ? $run + 1 : 1;
            $lastDate = $shift->date;

            if ($run > $this->rules['max_consecutive_days']) {
                $flag($shift, sprintf('Làm liên tục %d ngày (tối đa %d)', $run, $this->rules['max_consecutive_days']));
            }
        }

        // 4. Tổng giờ và tăng ca trong tuần.
        $hours = round($working->filter($inWeek)->sum('hours'), 2);
        $overtime = round(max(0, $hours - (float) $employee->weekly_hours), 2);

        $messages = [];
        $level = 'ok';

        if ($overtime > $this->rules['max_weekly_overtime']) {
            $level = 'danger';
            $messages[] = sprintf('Tăng ca %sh/tuần, vượt mức %sh', $this->num($overtime), $this->rules['max_weekly_overtime']);
        } elseif ($overtime > 0) {
            $level = 'warning';
            $messages[] = sprintf('Tăng ca %sh', $this->num($overtime));
        }

        if ($cells && $level === 'ok') {
            $level = 'warning';
        }

        return [
            'cells' => $cells,
            'hours' => $hours,
            'overtime' => $overtime,
            'level' => $level,
            'messages' => $messages,
        ];
    }

    protected function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
