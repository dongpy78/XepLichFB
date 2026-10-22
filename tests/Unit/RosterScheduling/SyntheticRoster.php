<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Scheduling\Problem;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\Rules;
use Botble\RestaurantRoster\Scheduling\ShiftSpec;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use Carbon\CarbonImmutable;

// Plugin Botble được nạp động lúc chạy web; test engine nạp namespace trực tiếp, không cần database.
spl_autoload_register(function (string $class): void {
    $prefix = 'Botble\\RestaurantRoster\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

/**
 * DỮ LIỆU GIẢ LẬP cho test – không phải nhân viên, ca hay định biên thật của resort.
 * Ngày dùng năm 2030 và tên "Synthetic ..." để không nhầm với dữ liệu vận hành.
 */
trait SyntheticRoster
{
    protected const GUSTO = 1;
    protected const VILLA = 2;
    protected const NIGHT = 3;
    protected const ROOM = 4;
    protected const LOBBY = 5;
    protected const KID = 6;

    protected const M6 = 10;
    protected const A2 = 11;
    protected const N = 12;
    protected const OH = 13;
    protected const M9 = 14;
    protected const WAITER = 100;

    /** Thứ Hai 07/01/2030 – tuần ISO trọn vẹn. */
    protected const MONDAY = '2030-01-07';

    protected function shifts(): array
    {
        return [
            // M6(L): Lobby 09:00–14:00 (06–09h ở nhà hàng); A2(L): Lobby 14:00–17:00 (quy tắc Lobby chốt 22/10/2026).
            new ShiftSpec(self::M6, 'M6', [[6 * 60, 14 * 60]], 8, [9 * 60, 14 * 60]),
            new ShiftSpec(self::A2, 'A2', [[14 * 60, 22 * 60]], 8, [14 * 60, 17 * 60]),
            new ShiftSpec(self::N, 'N', [[22 * 60, 30 * 60]], 8),
            // OH 08:45–16:45 làm ở nhà hàng, chỉ cho nhân viên ở Nha Trang; OH(L) = hỗ trợ Lobby cả ca.
            new ShiftSpec(self::OH, 'OH', [[8 * 60 + 45, 16 * 60 + 45]], 8, [8 * 60 + 45, 16 * 60 + 45], 'nha_trang'),
            // M9 09:00–17:00 làm trọn ở Lobby: chỉ nữ, Waiter, ở Cam Ranh.
            new ShiftSpec(self::M9, 'M9', [[9 * 60, 17 * 60]], 8, null, 'cam_ranh', 'female', self::WAITER),
        ];
    }

    protected function days(int $count = 7, string $from = self::MONDAY): array
    {
        $start = CarbonImmutable::parse($from);

        return array_map(fn ($i) => $start->addDays($i)->toDateString(), range(0, $count - 1));
    }

    protected function day(int $offset): string
    {
        return CarbonImmutable::parse(self::MONDAY)->addDays($offset)->toDateString();
    }

    /**
     * @param array<string, string> $skills "outlet:shift" => primary|eligible
     */
    protected function employee(int $id, int $home, array $skills, ?string $gender = 'female', int $daysOff = 2, float $weekly = 48, array $fixed = [], ?string $residence = null, ?int $position = null): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", $gender, $home, $weekly, $daysOff, $skills, $fixed, $residence, $position);
    }

    /**
     * Nhóm nhân viên đồng nhất làm M6/A2 tại một outlet.
     *
     * @return EmployeeSpec[]
     */
    protected function team(int $firstId, int $count, int $outlet, ?string $gender = 'female', int $daysOff = 2): array
    {
        return array_map(
            fn ($k) => $this->employee($firstId + $k, $outlet, ["{$outlet}:" . self::M6 => 'primary', "{$outlet}:" . self::A2 => 'primary'], $gender, $daysOff),
            range(0, $count - 1)
        );
    }

    /**
     * @return Requirement[]
     */
    protected function dailyRequirements(array $days, int $outlet, int $shift, int $min, int $target, int $minMale = 0, int $targetMale = 0, bool $allowReduce = true): array
    {
        return array_map(fn ($d) => new Requirement($d, $outlet, $shift, $min, $target, null, $minMale, $targetMale, $allowReduce), $days);
    }

    /**
     * Định biên như bản chạy thật: mỗi ca nhận thêm tối đa 2 người trên mục tiêu (config scheduling.flex_capacity)
     * để ai cũng đủ ngày làm theo số ngày OFF cấu hình.
     *
     * @return Requirement[]
     */
    protected function flexRequirements(array $days, int $outlet, int $shift, int $min, int $target, int $flex = 2): array
    {
        return array_map(fn ($d) => new Requirement($d, $outlet, $shift, $min, $target, $target + $flex), $days);
    }

    protected function problem(array $employees, array $requirements, array $options = []): Problem
    {
        return new Problem(
            days: $options['days'] ?? $this->days(),
            employees: $employees,
            shifts: $this->shifts(),
            requirements: $requirements,
            rules: new Rules(
                hardMinRestHours: $options['hard_rest'] ?? 8,
                softMinRestHours: $options['soft_rest'] ?? 12,
                maxDailyHours: 12,
                maxConsecutiveDays: $options['max_consecutive'] ?? 6,
                transitions: $options['transitions'] ?? [],
                timeLimitSeconds: $options['time_limit'] ?? 1.5,
                maxIterations: $options['max_iterations'] ?? 200000,
                stabilityFirst: $options['stability_first'] ?? false,
                daysOffMode: $options['days_off_mode'] ?? 'weekly',
                minOffPerWeek: $options['min_off_per_week'] ?? 1,
                supportGender: $options['support_gender'] ?? 'female',
                seed: $options['seed'] ?? 7,
                weightOverrides: $options['weights'] ?? [],
                maxSupportPerOutlet: $options['max_support'] ?? 2,
            ),
            hardUnavailable: $options['hard'] ?? [],
            softUnavailable: $options['soft'] ?? [],
            fixed: $options['fixed'] ?? [],
            baseline: $options['baseline'] ?? [],
            outletNames: [self::GUSTO => 'Gusto', self::VILLA => 'Villa', self::NIGHT => 'Night', self::ROOM => 'Room', self::LOBBY => 'Lobby', self::KID => 'Kid'],
            regularOff: $options['regular_off'] ?? [],
            coverage: $options['coverage'] ?? [],
            stationOutletId: self::LOBBY,
            lobbyHistory: $options['lobby_history'] ?? [],
            nightHistory: $options['night_history'] ?? [],
            outletParents: $options['outlet_parents'] ?? [],
            positionGroups: $options['position_groups'] ?? [],
            supportHistory: $options['support_history'] ?? [],
            shiftHistory: $options['shift_history'] ?? [],
            historyWeeks: $options['history_weeks'] ?? 4,
            nightPlan: $options['night_plan'] ?? [],
        );
    }

    protected function solve(Problem $problem): SolveResult
    {
        return (new HeuristicSolver())->solve($problem);
    }

    protected function locked(int $employeeId, string $date, ?int $outlet, ?int $shift, string $kind = FixedAssignment::KIND_SHIFT): FixedAssignment
    {
        return new FixedAssignment($employeeId, $date, $kind, $outlet, $shift);
    }

    /** @return array<string, array<int, int>> "date|outlet|shift" => [employeeIds] */
    protected function staffing(SolveResult $result): array
    {
        $map = [];

        foreach ($result->assignments as $row) {
            if ($row['kind'] === 'shift') {
                $map["{$row['date']}|{$row['outlet_id']}|{$row['shift_id']}"][] = $row['employee_id'];
            }
        }

        return $map;
    }

    protected function shiftOf(SolveResult $result, int $employeeId, string $date): ?array
    {
        foreach ($result->assignments as $row) {
            if ($row['employee_id'] === $employeeId && $row['date'] === $date) {
                return $row['kind'] === 'shift' ? [$row['outlet_id'], $row['shift_id']] : null;
            }
        }

        return null;
    }

    /**
     * Bộ kiểm tra ĐỘC LẬP với engine: kiểm lại lịch đầu ra theo định nghĩa ràng buộc cứng.
     */
    protected function assertHardConstraintsHold(Problem $problem, SolveResult $result): void
    {
        $shifts = [];

        foreach ($problem->shifts as $shift) {
            $shifts[$shift->id] = $shift;
        }

        $byEmployee = [];

        foreach ($result->assignments as $row) {
            $this->assertArrayNotHasKey($row['date'], $byEmployee[$row['employee_id']] ?? [], 'Một nhân viên có hai dòng trong cùng ngày');
            $byEmployee[$row['employee_id']][$row['date']] = $row;
        }

        foreach ($byEmployee as $employeeId => $rows) {
            $employee = $problem->employees[$employeeId];
            ksort($rows);
            $previous = null;
            $run = 0;
            $weekDays = [];

            foreach ($rows as $date => $row) {
                if ($row['kind'] !== 'shift') {
                    $previous = null;
                    $run = 0;

                    continue;
                }

                $this->assertArrayNotHasKey($date, $problem->hardUnavailable[$employeeId] ?? [], "#{$employeeId} bị xếp vào ngày OFF đã duyệt {$date}");

                if (! $row['locked']) {
                    $this->assertNotNull($employee->skillLevel($row['outlet_id'], $row['shift_id']), "#{$employeeId} không đủ điều kiện {$row['outlet_id']}:{$row['shift_id']}");
                }

                $dayIndex = CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($problem->days[0]));
                $start = $dayIndex * 1440 + $shifts[$row['shift_id']]->start();

                if ($previous && ! $row['locked']) {
                    $this->assertGreaterThanOrEqual($problem->rules->hardMinRestHours * 60, $start - $previous, "#{$employeeId} nghỉ không đủ trước {$date}");
                }

                $previous = $dayIndex * 1440 + $shifts[$row['shift_id']]->end();
                $run++;
                $this->assertLessThanOrEqual($problem->rules->maxConsecutiveDays, $run, "#{$employeeId} làm quá số ngày liên tục");

                $week = CarbonImmutable::parse($date)->format('o-W');
                $weekDays[$week] = ($weekDays[$week] ?? 0) + 1;
                $this->assertLessThanOrEqual($employee->maxWorkDaysPerWeek(), $weekDays[$week], "#{$employeeId} ít hơn {$employee->daysOffPerWeek} ngày OFF trong tuần {$week}");
            }
        }
    }
}
