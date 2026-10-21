<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Models\Period;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Problem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tổng hợp OFF của một kỳ lịch trước khi xếp tự động: OFF theo ngày / outlet, số người còn đi làm được so với định biên,
 * ngày & outlet có nguy cơ thiếu, ca không còn người đủ năng lực (kể cả chuyên trách đều OFF), thiếu nam.
 * Dựng từ cùng dữ liệu thuật toán dùng (ProblemBuilder) nên con số khớp với lúc chạy.
 */
class OffReadinessService
{
    public function __construct(protected ProblemBuilder $builder)
    {
    }

    /**
     * Kỳ lịch đã xác nhận "nhập xong OFF" bao trọn phạm vi [from, to].
     */
    public function confirmedPeriod(CarbonInterface $from, CarbonInterface $to): ?Period
    {
        return Period::query()
            ->whereNotNull('off_confirmed_at')
            ->whereDate('start_date', '<=', $from->toDateString())
            ->whereDate('end_date', '>=', $to->toDateString())
            ->latest('off_confirmed_at')
            ->first();
    }

    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $problem = $this->builder->build($from, $to);
        $names = $problem->outletNames;
        $off = $this->offDays($problem);
        $days = [];
        $alerts = [];

        foreach ($problem->days as $date) {
            $row = ['date' => $date, 'off' => 0, 'available' => 0, 'min' => 0, 'target' => 0, 'outlets' => []];

            foreach ($problem->employees as $employee) {
                $home = $employee->homeOutletId;
                $row['outlets'][$home] ??= ['off' => 0, 'available' => 0, 'min' => 0, 'target' => 0];

                if (isset($off[$employee->id][$date])) {
                    $row['off']++;
                    $row['outlets'][$home]['off']++;
                } else {
                    $row['available']++;
                    $row['outlets'][$home]['available']++;
                }
            }

            foreach ($problem->requirements as $requirement) {
                if ($requirement->date !== $date || ($requirement->min === 0 && $requirement->target === 0)) {
                    continue;
                }

                // Khu trực thuộc (Kid-Deline) tính vào nhà hàng chủ quản.
                $outlet = $problem->outletParents[$requirement->outletId] ?? $requirement->outletId;
                $row['outlets'][$outlet] ??= ['off' => 0, 'available' => 0, 'min' => 0, 'target' => 0];
                $row['outlets'][$outlet]['min'] += $requirement->min;
                $row['outlets'][$outlet]['target'] += $requirement->target;
                $row['min'] += $requirement->min;
                $row['target'] += $requirement->target;

                $this->skillAlerts($problem, $requirement, $off, $alerts);
            }

            // Mức rủi ro trong ngày: người đi làm được (chưa trừ OFF tuần còn phải xếp) so với tối thiểu / mục tiêu.
            $row['risk'] = match (true) {
                $row['available'] < $row['min'] => 'high',
                $row['available'] < $row['target'] => 'medium',
                default => 'ok',
            };

            foreach ($row['outlets'] as $outletId => $stats) {
                if ($stats['min'] > 0 && $stats['available'] < $stats['min']) {
                    $alerts[] = ['level' => 'high', 'date' => $date, 'message' => sprintf('%s: còn %d người đi làm được, cần tối thiểu %d',
                        $names[$outletId] ?? "#{$outletId}", $stats['available'], $stats['min'])];
                }
            }

            $days[] = $row;
        }

        return [
            'days' => $days,
            'weeks' => $this->weekCapacity($problem, $off, $days),
            'alerts' => $this->uniqueAlerts($alerts),
            'outlet_names' => $names,
            'total_off' => array_sum(array_column($days, 'off')),
            'pending' => AvailabilityRequest::query()->where('status', 'pending')
                ->whereDate('date_from', '<=', $to->toDateString())->whereDate('date_to', '>=', $from->toDateString())->count(),
        ];
    }

    /**
     * Ngày nghỉ đã biết của từng người: OFF quản lý đã cho / nghỉ phép / sinh nhật, và ô OFF đã khóa.
     *
     * @return array<int, array<string, true>>
     */
    private function offDays(Problem $problem): array
    {
        $off = [];
        $scope = array_flip($problem->days);

        foreach ($problem->hardUnavailable as $employeeId => $dates) {
            foreach (array_keys($dates) as $date) {
                $off[$employeeId][$date] = true;
            }
        }

        foreach ($problem->fixed as $assignment) {
            if (isset($scope[$assignment->date]) && ! $assignment->isWorking() && $assignment->source === 'locked') {
                $off[$assignment->employeeId][$assignment->date] = true;
            }
        }

        return $off;
    }

    /**
     * Ca không còn đủ người có năng lực (đi làm được), thiếu nam, hoặc người chuyên trách đều OFF.
     */
    private function skillAlerts(Problem $problem, $requirement, array $off, array &$alerts): void
    {
        $label = ($problem->outletNames[$requirement->outletId] ?? "#{$requirement->outletId}") . ' ' . ($problem->shifts[$requirement->shiftId]->code ?? '');
        $eligible = 0;
        $primary = 0;
        $primaryOff = 0;
        $males = 0;

        foreach ($problem->employees as $employee) {
            $level = $employee->skillLevel($requirement->outletId, $requirement->shiftId);

            if ($level === null) {
                continue;
            }

            $isOff = isset($off[$employee->id][$requirement->date]);

            if ($level === EmployeeSpec::PRIMARY) {
                $primary++;
                $primaryOff += $isOff ? 1 : 0;
            }

            if (! $isOff) {
                $eligible++;
                $males += $employee->isMale() ? 1 : 0;
            }
        }

        if ($requirement->min > 0 && $eligible < $requirement->min) {
            $alerts[] = ['level' => 'high', 'date' => $requirement->date,
                'message' => "{$label}: chỉ còn {$eligible} người đủ năng lực đi làm được, cần tối thiểu {$requirement->min} – xem lại OFF hoặc bổ sung năng lực thay thế"];
        }

        if ($requirement->minMale > 0 && $males < $requirement->minMale) {
            $alerts[] = ['level' => 'high', 'date' => $requirement->date,
                'message' => "{$label}: chỉ còn {$males} nam đủ năng lực, cần tối thiểu {$requirement->minMale}"];
        }

        if ($primary > 0 && $primaryOff === $primary) {
            $alerts[] = ['level' => 'medium', 'date' => $requirement->date,
                'message' => "{$label}: mọi người chuyên trách đều OFF – thuật toán sẽ dùng người đủ điều kiện thay thế" . ($eligible ? " ({$eligible} người)" : ' (không còn ai)')];
        }
    }

    /**
     * Sức chứa từng tuần: tổng ngày làm tối đa (mỗi người tối đa 7 − số ngày OFF/tuần, trừ ngày đã nghỉ) so với tổng định biên.
     */
    private function weekCapacity(Problem $problem, array $off, array $days): array
    {
        $weeks = [];

        foreach ($days as $row) {
            $week = CarbonImmutable::parse($row['date'])->startOfWeek()->toDateString();
            $weeks[$week] ??= ['week' => $week, 'days' => [], 'min' => 0, 'target' => 0, 'capacity' => 0];
            $weeks[$week]['days'][] = $row['date'];
            $weeks[$week]['min'] += $row['min'];
            $weeks[$week]['target'] += $row['target'];
        }

        foreach ($weeks as &$week) {
            foreach ($problem->employees as $employee) {
                $free = count(array_filter($week['days'], fn ($d) => ! isset($off[$employee->id][$d])));
                // Tuần không trọn trong kỳ: định mức theo tỉ lệ số ngày.
                $norm = (int) floor($employee->maxWorkDaysPerWeek() * count($week['days']) / 7);
                $week['capacity'] += min($free, max($norm, 0));
            }

            $week['risk'] = match (true) {
                $week['capacity'] < $week['min'] => 'high',
                $week['capacity'] < $week['target'] => 'medium',
                default => 'ok',
            };
        }

        return array_values($weeks);
    }

    private function uniqueAlerts(array $alerts): array
    {
        $seen = [];

        $alerts = array_values(array_filter($alerts, function ($alert) use (&$seen) {
            $key = $alert['date'] . '|' . $alert['message'];

            return ! isset($seen[$key]) && ($seen[$key] = true);
        }));

        usort($alerts, fn ($a, $b) => [$a['level'] === 'high' ? 0 : 1, $a['date']] <=> [$b['level'] === 'high' ? 0 : 1, $b['date']]);

        return $alerts;
    }
}
