<?php

namespace Botble\RestaurantRoster\Scheduling;

use Throwable;

/**
 * Bộ giải heuristic hai giai đoạn, chạy hoàn toàn trong PHP (phù hợp shared hosting):
 *
 * 1. Dựng phương án ban đầu có ràng buộc:
 *    - Tối ưu lại: giữ trước các ô của lịch hiện tại còn hợp lệ (ít thay đổi nhất).
 *    - Điền theo lượt trên toàn kỳ: nam tối thiểu → người tối thiểu → phủ Lobby tối thiểu →
 *      nam mục tiêu → người mục tiêu → phủ Lobby mục tiêu.
 *      Trong mỗi lượt, ô khan hiếm ứng viên được điền trước; ứng viên được chọn theo điểm
 *      (ít ngày làm so với định mức, chuyên trách, đúng outlet chính, tránh ca nặng, tránh đơn chưa duyệt).
 *    - Phủ Lobby: ưu tiên giao (L) cho người đã đi làm ca có khung (L) hôm đó (không tốn thêm người);
 *      thiếu nữa mới xếp thêm người: ca làm trọn ở Lobby (M9) hoặc ca có khung (L) (OH(L), A2(L)).
 *    - Ngày làm vượt định mức tuần (chế độ OFF theo cặp 2 tuần) chỉ được dùng trong lượt "tối thiểu".
 *    - Mọi lần đặt đều qua ScheduleState::violation() nên không bao giờ tạo vi phạm cứng.
 *
 * 2. Tìm kiếm lân cận trong giới hạn thời gian: điền ô thiếu, chuyển ô trong ngày, đổi ô giữa hai người,
 *    dời ngày làm, đổi ngày giữa hai người, bật/tắt (L). Chỉ nhận bước làm vector mục tiêu không xấu đi
 *    (so sánh theo thứ tự ưu tiên), nên một mức thấp hơn không bao giờ được đổi lấy bằng cách làm thiếu người hơn.
 *
 * Kết quả có thể tái lập: cùng dữ liệu + cùng seed + cùng số bước → cùng lịch.
 */
final class HeuristicSolver implements SolverInterface
{
    public function name(): string
    {
        return 'heuristic-anneal-v3';
    }

    public function solve(Problem $problem): SolveResult
    {
        $startedAt = microtime(true);
        mt_srand($problem->rules->seed);

        try {
            $state = new ScheduleState($problem);
            $deadline = $startedAt + $problem->rules->timeLimitSeconds;

            $this->warmStart($state);
            $constructed = $this->construct($state, $deadline);
            $afterConstruction = $state->objective();

            $stats = $constructed
                ? $this->localSearch($state, $deadline)
                : ['iterations' => 0, 'accepted' => 0, 'improved' => 0, 'time_limit_reached' => true];

            if ($constructed) {
                // Sửa OFF lẻ còn sót (thêm tối đa 3 giây).
                $stats['off_repaired'] = $this->repairOffSplits($state, microtime(true) + 3);
            }

            return (new ResultBuilder())->build($state, $constructed, [
                'solver' => $this->name(),
                'seed' => $problem->rules->seed,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'time_limit_seconds' => $problem->rules->timeLimitSeconds,
                'objective_after_construction' => $afterConstruction,
                'input_hash' => $problem->hash(),
                ...$stats,
            ]);
        } catch (Throwable $exception) {
            return new SolveResult(SolveResult::ERROR, explanation: ['Lỗi khi xếp lịch: ' . $exception->getMessage()], solverMetadata: [
                'solver' => $this->name(),
                'exception' => get_class($exception),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    // ------------------------------------------------------------------ Giai đoạn 1

    private function warmStart(ScheduleState $state): void
    {
        foreach ($state->problem->baseline as $employeeId => $cells) {
            if (! isset($state->problem->employees[$employeeId])) {
                continue;
            }

            foreach ($cells as $date => $slot) {
                $i = $state->index[$date] ?? null;

                if ($i === null || ! $state->inScope[$i] || $state->isOccupied($employeeId, $i)) {
                    continue;
                }

                [$o, $s, $f] = ScheduleState::parseSlot($slot);
                $requirement = $state->requirements[$i]["{$o}|{$s}"] ?? null;

                if (! $requirement || $state->count($i, $o, $s) >= $requirement->capacity()) {
                    continue;
                }

                if (! $state->violation($employeeId, $i, $o, $s, support: $f)) {
                    $state->place($employeeId, $i, $o, $s, $f);
                } elseif ($f && ! $state->violation($employeeId, $i, $o, $s)) {
                    // Giữ ca, bỏ (L) nếu người này không còn được hỗ trợ Lobby.
                    $state->place($employeeId, $i, $o, $s);
                }
            }
        }
    }

    /** @var array<int, array<string, bool>> [employeeId][tuần] => người được phân trực ca đêm tuần đó (luân phiên) */
    private array $nightDuty = [];

    /** @var array<int, array<int, bool>> [employeeId][dayIndex] => ngày OFF dự kiến (gợi ý cho bước dựng lịch) */
    private array $plannedOff = [];

    /**
     * Lên trước cặp 2 ngày OFF liền nhau cho mỗi người mỗi tuần (người OFF >= 2 ngày/tuần):
     * tránh ngày đông khách (thứ Bảy), so le giữa các người theo độ "dư người" của từng ngày,
     * ghép cạnh ngày OFF đã biết (sinh nhật, OFF đã duyệt). Chỉ là gợi ý mềm cho bước dựng lịch.
     */
    private function planOffPairs(ScheduleState $state): void
    {
        $this->plannedOff = [];
        $scope = $state->scopeIndexes();
        $slack = [];
        $load = [];

        $excess = [];

        foreach ($scope as $i) {
            $demand = 0;
            $capacity = 0;

            foreach ($state->requirements[$i] ?? [] as $requirement) {
                $demand += $requirement->min;
                $capacity += $requirement->capacity();
            }

            $available = 0;

            foreach (array_keys($state->problem->employees) as $employeeId) {
                $off = isset($state->problem->hardUnavailable[$employeeId][$state->dates[$i]]) || ($state->isFixed($employeeId, $i) && $state->isOffDay($employeeId, $i));
                $available += $off ? 0 : 1;
            }

            $slack[$i] = max(1, $available - $demand);
            // Số người chắc chắn phải OFF hôm đó (đi làm nhiều hơn số chỗ): lên OFF cho đủ những ngày này trước.
            $excess[$i] = max(0, $available - $capacity);
            $load[$i] = 0;
        }

        $weeks = [];

        foreach ($scope as $i) {
            $weeks[$state->weekKey($i)][] = $i;
        }

        $employeeIds = array_keys($state->problem->employees);
        shuffle($employeeIds);

        foreach ($employeeIds as $employeeId) {
            if ($state->problem->employees[$employeeId]->daysOffPerWeek < 2) {
                continue;
            }

            foreach ($weeks as $days) {
                $decisions = array_filter($days, fn ($i) => ! $state->isFixed($employeeId, $i) && ! isset($state->problem->hardUnavailable[$employeeId][$state->dates[$i]]));

                if (count($days) < 4 || ! $decisions) {
                    continue;
                }

                $known = array_values(array_filter($days, fn ($i) => $state->isOffDay($employeeId, $i) && ! isset($state->plan[$employeeId][$i])
                    && ($state->isFixed($employeeId, $i) || isset($state->problem->hardUnavailable[$employeeId][$state->dates[$i]]))));

                if (count($known) >= 2) {
                    continue;
                }

                $best = null;
                $bestScore = PHP_FLOAT_MAX;

                foreach ($days as $a) {
                    $b = $a + 1;

                    if (! in_array($b, $days, true)) {
                        continue;
                    }

                    $pair = [$a, $b];

                    if ($known && ! in_array($known[0], $pair, true)) {
                        continue;
                    }

                    $score = 0.0;

                    foreach ($pair as $d) {
                        if (in_array($d, $known, true)) {
                            continue;
                        }

                        if (! in_array($d, $decisions, true)) {
                            $score = PHP_FLOAT_MAX;

                            break;
                        }

                        $score += $load[$d] < $excess[$d]
                            ? -50
                            : ($load[$d] + 1) / $slack[$d] + ($state->isBusyDay($d) ? 100 : 0);
                    }

                    $score += mt_rand(0, 100) / 10000;

                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $best = $pair;
                    }
                }

                if ($best === null || $bestScore >= PHP_FLOAT_MAX) {
                    continue;
                }

                foreach ($best as $d) {
                    if (! in_array($d, $known, true)) {
                        $this->plannedOff[$employeeId][$d] = true;
                        $load[$d]++;
                    }
                }
            }
        }
    }

    /**
     * Phân trực ca đêm theo tuần: số ca đêm cần trong tuần trừ phần người chỉ làm đêm / ca cứng đêm gánh,
     * phần còn lại giao cho người luân phiên có ít tuần đêm nhất ở các tuần trước.
     */
    private function planNightDuty(ScheduleState $state): void
    {
        $this->nightDuty = [];
        $weeks = [];

        foreach ($state->scopeIndexes() as $i) {
            $weeks[$state->weekKey($i)][] = $i;
        }

        $nightShifts = array_keys(array_filter($state->problem->shifts, fn ($shift) => $shift->category() === 'night'));
        // Số tuần đêm đã làm: các tuần trước kỳ + các tuần đã phân trong kỳ (tuần sau đổi người).
        $history = $state->problem->nightHistory;

        foreach ($weeks as $week => $days) {
            $need = 0;

            foreach ($days as $i) {
                foreach ($state->requirements[$i] ?? [] as $requirement) {
                    if (in_array($requirement->shiftId, $nightShifts, true)) {
                        $need += $requirement->target;
                    }
                }
            }

            $capacity = fn (int $employeeId) => min(
                $state->problem->employees[$employeeId]->maxWorkDaysPerWeek(),
                count(array_filter($days, fn ($i) => ! isset($state->problem->hardUnavailable[$employeeId][$state->dates[$i]]) && ! $state->isFixed($employeeId, $i)))
            );

            // Người không luân phiên (chỉ làm đêm, ca cứng đêm) gánh trước.
            foreach (array_keys($state->problem->employees) as $employeeId) {
                if (isset($state->nightRotation[$employeeId])) {
                    continue;
                }

                foreach ($nightShifts as $shiftId) {
                    foreach (array_keys($state->problem->employees[$employeeId]->skills) as $key) {
                        if ($state->problem->employees[$employeeId]->skillLevel((int) explode(':', $key)[0], $shiftId) !== null) {
                            $need -= $capacity($employeeId);

                            continue 3;
                        }
                    }
                }
            }

            $pool = array_keys($state->nightRotation);
            shuffle($pool);
            usort($pool, fn ($a, $b) => ($history[$a] ?? 0) <=> ($history[$b] ?? 0));

            foreach ($pool as $employeeId) {
                if ($need <= 0) {
                    break;
                }

                if (($available = $capacity($employeeId)) > 0) {
                    $this->nightDuty[$employeeId][$week] = true;
                    $history[$employeeId] = ($history[$employeeId] ?? 0) + 1;
                    $need -= $available;
                }
            }
        }
    }

    /**
     * Điểm cộng thêm theo phân trực đêm: người luân phiên không trực tuần này tránh ca đêm, người trực tránh ca ngày.
     */
    private function nightBias(ScheduleState $state, int $employeeId, int $i, int $shiftId): int
    {
        if (! isset($state->nightRotation[$employeeId])) {
            return 0;
        }

        $onDuty = isset($this->nightDuty[$employeeId][$state->weekKey($i)]);
        $night = $state->problem->shifts[$shiftId]->category() === 'night';

        return match (true) {
            $night && ! $onDuty => 600,
            ! $night && $onDuty => 400,
            default => 0,
        };
    }

    private function construct(ScheduleState $state, float $deadline): bool
    {
        $this->planOffPairs($state);
        $this->planNightDuty($state);

        $passes = [
            [fn (Requirement $r) => $r->minMale, true, true],
            'position-min',
            [fn (Requirement $r) => $r->min, false, true],
            'coverage-min',
            [fn (Requirement $r) => $r->targetMale, true, false],
            [fn (Requirement $r) => $r->target, false, false],
            'coverage-target',
        ];

        foreach ($passes as $pass) {
            if ($pass === 'position-min') {
                if (! $this->fillPositions($state, $deadline)) {
                    return false;
                }

                continue;
            }

            if (is_string($pass)) {
                if (! $this->fillCoverage($state, $pass === 'coverage-min', $deadline)) {
                    return false;
                }

                continue;
            }

            [$need, $maleOnly, $allowOverNorm] = $pass;
            $slots = [];

            foreach ($state->requirements as $i => $requirements) {
                foreach ($requirements as $requirement) {
                    $quota = $need($requirement);

                    if ($quota > 0) {
                        $slots[] = [$i, $requirement, $quota, $this->candidateCount($state, $i, $requirement, $maleOnly)];
                    }
                }
            }

            // Ô khan hiếm (ít ứng viên so với nhu cầu) được điền trước.
            usort($slots, fn ($a, $b) => [$a[3] - $a[2], $a[0]] <=> [$b[3] - $b[2], $b[0]]);

            foreach ($slots as [$i, $requirement, $quota]) {
                if (microtime(true) > $deadline) {
                    return false;
                }

                $have = fn () => $maleOnly
                    ? $state->maleCount($i, $requirement->outletId, $requirement->shiftId)
                    : $state->count($i, $requirement->outletId, $requirement->shiftId);

                while ($have() < $quota && $state->count($i, $requirement->outletId, $requirement->shiftId) < $requirement->capacity()) {
                    $employeeId = $this->bestCandidate($state, $i, $requirement, $maleOnly, $allowOverNorm);

                    if ($employeeId === null) {
                        break;
                    }

                    $state->place($employeeId, $i, $requirement->outletId, $requirement->shiftId);
                }
            }
        }

        $this->fillWorkNorms($state, $deadline);

        return true;
    }

    /**
     * Lượt cuối: người còn thiếu ngày làm so với định mức tuần (7 − số ngày OFF) được xếp vào ô còn chỗ
     * (kể cả trên mục tiêu, trong sức chứa), tránh ngày OFF dự kiến; ưu tiên ô ít người dôi ra, đúng outlet chính, không tạo ca nặng.
     */
    private function fillWorkNorms(ScheduleState $state, float $deadline): void
    {
        foreach ($state->problem->employees as $employeeId => $employee) {
            $days = $state->scopeIndexes();
            usort($days, fn ($a, $b) => (int) isset($this->plannedOff[$employeeId][$a]) <=> (int) isset($this->plannedOff[$employeeId][$b]));

            foreach ($days as $i) {
                if (microtime(true) > $deadline) {
                    return;
                }

                if ($state->isOccupied($employeeId, $i) || $state->wouldExceedWeeklyNorm($employeeId, $i)) {
                    continue;
                }

                $best = null;
                $bestScore = PHP_FLOAT_MAX;

                foreach ($state->requirements[$i] ?? [] as $requirement) {
                    if ($employee->skillLevel($requirement->outletId, $requirement->shiftId) === null
                        || $state->count($i, $requirement->outletId, $requirement->shiftId) >= $requirement->capacity()
                        || $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId)) {
                        continue;
                    }

                    $score = max(0, $state->count($i, $requirement->outletId, $requirement->shiftId) - $requirement->target + 1) * 100
                        + ($state->problem->isHomeOutlet($employee, $requirement->outletId) ? 0 : 200)
                        + (isset($state->problem->outletParents[$requirement->outletId]) ? 500 : 0)
                        + ($this->wouldBeHeavy($state, $employeeId, $i, $requirement) ? 150 : 0)
                        + mt_rand(0, 100) / 1000;

                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $best = $requirement;
                    }
                }

                if ($best) {
                    $state->place($employeeId, $i, $best->outletId, $best->shiftId);
                }
            }
        }
    }

    private function fillPositions(ScheduleState $state, float $deadline): bool
    {
        foreach ($state->requirements as $i => $requirements) {
            foreach ($requirements as $requirement) {
                foreach ($requirement->positionMins as $group => $need) {
                    if (microtime(true) > $deadline) {
                        return false;
                    }

                    while ($state->positionCount($i, $requirement->outletId, $requirement->shiftId, $group) < $need
                        && $state->count($i, $requirement->outletId, $requirement->shiftId) < $requirement->capacity()) {
                        $employeeId = $this->bestCandidate($state, $i, $requirement, false, true, (string) $group);

                        if ($employeeId === null) {
                            break;
                        }

                        $state->place($employeeId, $i, $requirement->outletId, $requirement->shiftId);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Điền phủ Lobby cho từng khung giờ còn thiếu (tối thiểu hoặc mục tiêu).
     */
    private function fillCoverage(ScheduleState $state, bool $minimumOnly, float $deadline): bool
    {
        foreach ($state->coverage as $i => $windows) {
            foreach ($windows as $k => [$start, $end, $min, $target]) {
                $need = $minimumOnly ? $min : $target;

                while ($state->coverageCount($i, $k) < $need) {
                    if (microtime(true) > $deadline) {
                        return false;
                    }

                    if (! $this->flagForWindow($state, $i, $k) && ! $this->stationShiftForWindow($state, $i, $k, $minimumOnly)) {
                        break;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Giao (L) cho người đã đi làm hôm đó với ca có khung (L) phủ được khung giờ cần.
     * Ưu tiên người ít lượt Lobby nhất trong kỳ (chia đều việc hỗ trợ).
     */
    private function flagForWindow(ScheduleState $state, int $i, int $k): bool
    {
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($state->problem->employees as $employeeId => $employee) {
            $slot = $state->plan[$employeeId][$i] ?? null;

            if ($slot === null) {
                continue;
            }

            [$o, $s, $f] = ScheduleState::parseSlot($slot);

            if ($f || ! in_array($k, $state->supportWindows($i, $o, $s, true), true)) {
                continue;
            }

            // Người đi Lobby rời nhà hàng: chỉ lấy người DƯ so với định biên tối thiểu của outlet × ca (tổng, nam, vị trí).
            $requirement = $state->requirements[$i]["{$o}|{$s}"] ?? null;

            if ($state->removalBreaksMinimum($employeeId, $i, $o, $s)) {
                continue;
            }

            $state->remove($employeeId, $i);
            $blocked = $state->violation($employeeId, $i, $o, $s, support: true);
            $state->place($employeeId, $i, $o, $s);

            if ($blocked) {
                continue;
            }

            // Chia đều giữa các outlet; trong outlet, ưu tiên người ít lượt Lobby; tránh làm outlet hụt mục tiêu.
            $score = $state->supportFrom($i, $k, $o) * 100
                + $state->lobbyLoad($employeeId) * 20
                + $state->supportTurns($employeeId, Problem::supportTypeKey($state->problem->shifts[$s]->code, 'lobby')) * 25
                + ($requirement && $state->count($i, $o, $s) - 1 < $requirement->target ? 50 : 0)
                + mt_rand(0, 9);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = [$employeeId, $o, $s];
            }
        }

        if (! $best) {
            return false;
        }

        [$employeeId, $o, $s] = $best;
        $state->remove($employeeId, $i);
        $state->place($employeeId, $i, $o, $s, true);

        return true;
    }

    /**
     * Xếp thêm một người rảnh để phủ khung giờ Lobby: ca làm trọn ở Lobby (M9) hoặc ca có khung (L) của một outlet
     * (vd OH(L) buổi sáng, A2(L) buổi chiều) – người này đi Lobby, không tính vào định biên outlet.
     * Chọn chung một bảng điểm: outlet nguồn đang góp ít người cho khung này, người ít lượt Lobby, người ít ngày làm.
     */
    private function stationShiftForWindow(ScheduleState $state, int $i, int $k, bool $allowOverNorm): bool
    {
        $station = $state->problem->stationOutletId;

        if ($station === null) {
            return false;
        }

        $best = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($state->requirements[$i] ?? [] as $requirement) {
            $atStation = $requirement->outletId === $station;

            if (! in_array($k, $state->supportWindows($i, $requirement->outletId, $requirement->shiftId, ! $atStation), true)
                || ($atStation && $state->count($i, $station, $requirement->shiftId) >= $requirement->capacity())) {
                continue;
            }

            foreach ($state->problem->employees as $employeeId => $employee) {
                if ($state->isOccupied($employeeId, $i)
                    || $employee->skillLevel($requirement->outletId, $requirement->shiftId) === null
                    || $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId, support: ! $atStation)) {
                    continue;
                }

                $overNorm = $state->wouldExceedWeeklyNorm($employeeId, $i);

                if ($overNorm && ! $allowOverNorm) {
                    continue;
                }

                $source = $state->supportSource($employeeId, $requirement->outletId);
                $expected = max(0.5, $this->expectedDays($state, $employee));
                $score = $state->supportFrom($i, $k, $source) * 100
                    + $state->lobbyLoad($employeeId) * 20
                    + $state->supportTurns($employeeId, Problem::supportTypeKey($state->problem->shifts[$requirement->shiftId]->code, 'lobby')) * 25
                    + ($state->metricsFor($employeeId)['worked'] / $expected) * 100
                    + ($overNorm ? 1000 : 0)
                    + (isset($this->plannedOff[$employeeId][$i]) ? 300 : 0)
                    + ($atStation || $state->problem->isHomeOutlet($employee, $requirement->outletId) ? 0 : 90)
                    + ($this->wouldBeHeavy($state, $employeeId, $i, $requirement) ? 150 : 0)
                    // Chia đều loại ca trong khung Lobby: đã có OH(L) thì ưu tiên M9 và ngược lại.
                    + $state->supportShiftCount($i, $k, $requirement->shiftId) * 100
                    + mt_rand(0, 100) / 1000;

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = [$employeeId, $requirement->outletId, $requirement->shiftId, ! $atStation];
                }
            }
        }

        if (! $best) {
            return false;
        }

        [$employeeId, $o, $s, $support] = $best;
        $state->place($employeeId, $i, $o, $s, $support);

        return true;
    }

    private function candidateCount(ScheduleState $state, int $i, Requirement $requirement, bool $maleOnly): int
    {
        $count = 0;

        foreach ($state->problem->employees as $employeeId => $employee) {
            // Khu trực thuộc (Kid-Deline): độ khan hiếm tính theo người của nhà hàng chủ quản.
            if (isset($state->problem->outletParents[$requirement->outletId]) && ! $state->problem->isHomeOutlet($employee, $requirement->outletId)) {
                continue;
            }

            if ((! $maleOnly || $employee->isMale())
                && ! $state->isOccupied($employeeId, $i)
                && $employee->skillLevel($requirement->outletId, $requirement->shiftId) !== null
                && ! isset($state->problem->hardUnavailable[$employeeId][$state->dates[$i]])) {
                $count++;
            }
        }

        return $count;
    }

    private function bestCandidate(ScheduleState $state, int $i, Requirement $requirement, bool $maleOnly, bool $allowOverNorm = false, ?string $positionGroup = null): ?int
    {
        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        $date = $state->dates[$i];

        foreach ($state->problem->employees as $employeeId => $employee) {
            if (($maleOnly && ! $employee->isMale()) || $state->isOccupied($employeeId, $i)
                || ($positionGroup !== null && ! in_array($positionGroup, $state->problem->groupsOfPosition($employee->positionId), true))) {
                continue;
            }

            $level = $employee->skillLevel($requirement->outletId, $requirement->shiftId);

            if ($level === null || $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId)) {
                continue;
            }

            $overNorm = $state->wouldExceedWeeklyNorm($employeeId, $i);

            if ($overNorm && ! $allowOverNorm) {
                continue;
            }

            $metrics = $state->metricsFor($employeeId);
            $expected = max(0.5, $this->expectedDays($state, $employee));
            $baseline = isset($state->problem->baseline[$employeeId][$date]) ? ScheduleState::parseSlot($state->problem->baseline[$employeeId][$date]) : null;

            // Xen kẽ ca: người không có ca cứng ưu tiên nhận loại ca (sáng/chiều) mình đang ít hơn trong tuần.
            $mixBias = 0;
            $category = $state->problem->shifts[$requirement->shiftId]->category();

            if (! $employee->fixed && in_array($category, ['morning', 'afternoon'], true)) {
                $week = $state->weekCategoryCounts($employeeId, $i);
                $mixBias = 60 * ($week[$category] - $week[$category === 'morning' ? 'afternoon' : 'morning']);
            }

            // Quản lý: ưu tiên ca (sáng / chiều) đang ít quản lý hơn ở outlet này trong ngày.
            $managerBias = 0;

            if ($employee->isManager && in_array($category, ['morning', 'afternoon'], true)) {
                $counts = $state->managerShiftCounts($i, $requirement->outletId);
                $managerBias = 200 * ($counts[$category] - $counts[$category === 'morning' ? 'afternoon' : 'morning']);
            }

            // Khu trực thuộc (Kid-Deline): ưu tiên người ít lượt M6(K) / A2(K) tích lũy (kể cả các tuần trước).
            $kidBias = isset($state->problem->outletParents[$requirement->outletId])
                ? $state->supportTurns($employeeId, Problem::supportTypeKey($state->problem->shifts[$requirement->shiftId]->code, 'kid')) * 40
                : 0;

            $score = ($metrics['worked'] / $expected) * 100
                + $mixBias
                + $managerBias
                + $kidBias
                + $this->nightBias($state, $employeeId, $i, $requirement->shiftId)
                + (isset($this->plannedOff[$employeeId][$i]) ? 300 : 0)
                + ($overNorm ? 1000 : 0)
                + ($level === EmployeeSpec::PRIMARY ? 0 : 60)
                + ($state->problem->isHomeOutlet($employee, $requirement->outletId) ? 0 : 90)
                // Khu trực thuộc: chỉ mượn người outlet khác khi không còn người của nhà hàng chủ quản.
                + (isset($state->problem->outletParents[$requirement->outletId]) && ! $state->problem->isHomeOutlet($employee, $requirement->outletId) ? 2000 : 0)
                + (isset($state->problem->softUnavailable[$employeeId][$date]) ? 400 : 0)
                + ($this->wouldBeHeavy($state, $employeeId, $i, $requirement) ? 150 : 0)
                // Người không phải nam dành chỗ nam cho ô cần nam khác.
                + (! $maleOnly && $employee->isMale() && $requirement->minMale === 0 ? 5 : 0)
                + ($baseline && $baseline[0] === $requirement->outletId && $baseline[1] === $requirement->shiftId ? -500 : 0)
                + mt_rand(0, 100) / 1000;

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $employeeId;
            }
        }

        return $best;
    }

    private function wouldBeHeavy(ScheduleState $state, int $employeeId, int $i, Requirement $requirement): bool
    {
        $state->place($employeeId, $i, $requirement->outletId, $requirement->shiftId);
        $heavy = $state->isHeavyPair($employeeId, $i - 1, $i) || $state->isHeavyPair($employeeId, $i, $i + 1);
        $state->remove($employeeId, $i);

        return $heavy;
    }

    private function expectedDays(ScheduleState $state, EmployeeSpec $employee): float
    {
        $available = 0;

        foreach ($state->problem->days as $date) {
            if (! isset($state->problem->hardUnavailable[$employee->id][$date]) || isset($state->problem->regularOff[$employee->id][$date])) {
                $available++;
            }
        }

        return $available * $employee->maxWorkDaysPerWeek() / 7;
    }

    // ------------------------------------------------------------------ Giai đoạn 2

    /**
     * Tìm kiếm "luyện kim" (simulated annealing) trên điểm phạt có trọng số:
     * bước tốt hơn hoặc bằng luôn được nhận; bước xấu hơn được nhận với xác suất e^(−chênh lệch / nhiệt độ).
     * Nhiệt độ giảm dần theo tiến độ (số bước hoặc thời gian, cái nào tới trước) nên đầu kỳ thoát được chỗ kẹt,
     * cuối kỳ chỉ còn tinh chỉnh. Nhóm "Bắt buộc" có trọng số rất lớn nên thực tế không bao giờ bị nhận xấu đi.
     * Luôn giữ lại phương án tốt nhất đã gặp và trả lịch về phương án đó khi kết thúc.
     */
    private function localSearch(ScheduleState $state, float $deadline): array
    {
        $rules = $state->problem->rules;
        // Pha dựng khung (Rules::FRAME_PHASE_WEIGHTS) – bỏ qua khi tối ưu lại lịch đang dùng (ưu tiên ít thay đổi).
        $frame = $rules->stabilityFirst ? null : Rules::FRAME_PHASE_WEIGHTS;
        [$real, $framed] = $state->costs($frame ?? []);
        $current = $frame ? $framed : $real;
        $best = $real;
        $bestPlan = $state->plan;
        $stats = ['iterations' => 0, 'accepted' => 0, 'improved' => 0, 'time_limit_reached' => false];
        $employeeIds = array_keys($state->problem->employees);
        $scope = $state->scopeIndexes();

        if (! $employeeIds || ! $scope) {
            return $stats;
        }

        $startedAt = microtime(true);
        $span = max(1e-3, $deadline - $startedAt);
        $t0 = max(1e-6, $rules->annealStartTemperature);
        $t1 = max(1e-6, min($t0, $rules->annealEndTemperature));
        $temperature = $t0;
        $moves = [];

        while ($stats['iterations'] < $rules->maxIterations) {
            if (($stats['iterations'] & 31) === 0) {
                $now = microtime(true);

                if ($now > $deadline) {
                    $stats['time_limit_reached'] = true;

                    break;
                }

                $progress = max($stats['iterations'] / $rules->maxIterations, ($now - $startedAt) / $span);
                $temperature = $t0 * ($t1 / $t0) ** min(1, $progress);

                if ($frame && $progress >= Rules::FRAME_PHASE_SHARE) {
                    // Hết pha dựng khung: tiếp tục từ phương án tốt nhất theo trọng số thật.
                    $frame = null;

                    if ($best < $state->cost()) {
                        $this->restorePlan($state, $bestPlan);
                    }

                    $current = $state->cost();
                }
            }

            $stats['iterations']++;

            $metrics = $state->metricsSnapshot();
            $tick = microtime(true);
            $undo = $this->randomMove($state, $employeeIds, $scope);
            $name = $this->lastMove;
            $moves[$name] ??= ['tried' => 0, 'valid' => 0, 'accepted' => 0, 'improved' => 0, 'ms' => 0.0];
            $moves[$name]['tried']++;

            if ($undo === null) {
                $moves[$name]['ms'] += (microtime(true) - $tick) * 1000;

                continue;
            }

            $moves[$name]['valid']++;
            [$real, $framed] = $state->costs($frame ?? []);
            $candidate = $frame ? $framed : $real;
            $delta = $candidate - $current;

            if ($delta <= 0 || mt_rand() / mt_getrandmax() < exp(-$delta / $temperature)) {
                $stats['accepted']++;
                $moves[$name]['accepted']++;
                $current = $candidate;

                if ($real < $best) {
                    $stats['improved']++;
                    $moves[$name]['improved']++;
                    $best = $real;
                    $bestPlan = $state->plan;
                }
            } else {
                $undo();
                $state->restoreMetrics($metrics);
            }

            $moves[$name]['ms'] += (microtime(true) - $tick) * 1000;
        }

        if ($best < $state->cost()) {
            $this->restorePlan($state, $bestPlan);
        }

        $stats['best_cost'] = $best;
        $stats['moves'] = $moves;

        return $stats;
    }

    /**
     * Đưa lịch về một phương án đã lưu (ô thuật toán xếp; ô cố định không đổi).
     *
     * @param array<int, array<int, string>> $plan
     */
    private function restorePlan(ScheduleState $state, array $plan): void
    {
        foreach ($state->plan as $employeeId => $cells) {
            foreach (array_keys($cells) as $i) {
                $state->remove($employeeId, $i);
            }
        }

        foreach ($plan as $employeeId => $cells) {
            foreach ($cells as $i => $slot) {
                [$o, $s, $f] = ScheduleState::parseSlot($slot);
                $state->place($employeeId, $i, $o, $s, $f);
            }
        }
    }

    /** Tên loại bước vừa thử (thống kê hiệu quả từng loại bước). */
    private string $lastMove = '';

    /**
     * Thực hiện một bước ngẫu nhiên hợp lệ; trả về hàm hoàn tác, hoặc null nếu không tìm được bước hợp lệ.
     */
    private function randomMove(ScheduleState $state, array $employeeIds, array $scope): ?callable
    {
        $i = $scope[mt_rand(0, count($scope) - 1)];
        $slots = $state->requirements[$i] ?? [];

        if (! $slots) {
            return null;
        }

        $move = mt_rand(0, 99);
        $hasCoverage = ! empty($state->coverage[$i]);
        $this->lastMove = match (true) {
            $move < 3 => 'fill', $move < 12 => 'reassign', $move < 19 => 'swap', $move < 24 => 'cross_swap', $move < 31 => 'swap_block',
            $move < 34 => 'shift_day', $move < 39 => 'merge_off', $move < 41 => 'shift_pair', $move < 50 => 'swap_days',
            $move < 62 => 'pattern', $move < 82 => 'pattern_swap', $move < 94 || ! $hasCoverage => 'cover', default => 'toggle_support',
        };

        return match ($this->lastMove) {
            'fill' => $this->moveFill($state, $i, $slots, $employeeIds),
            'reassign' => $this->moveReassign($state, $i, $slots, $employeeIds),
            'swap' => $this->moveSwap($state, $i, $employeeIds),
            'cross_swap' => $this->moveCrossSwap($state, $i, $scope, $employeeIds),
            'swap_block' => $this->moveSwapBlock($state, $i, $employeeIds),
            'shift_day' => $this->moveShiftDay($state, $i, $scope, $employeeIds),
            'merge_off' => $this->moveMergeOff($state, $employeeIds),
            'shift_pair' => $this->moveShiftPair($state, $i, $scope, $employeeIds),
            'swap_days' => $this->moveSwapDays($state, $i, $scope, $employeeIds),
            'pattern' => $this->movePattern($state, $employeeIds),
            'pattern_swap' => $this->movePatternSwap($state, $employeeIds),
            'cover' => $this->moveCover($state),
            default => $this->moveToggleSupport($state, $i, $employeeIds),
        };
    }

    /** @var array<string, int[]>|null [tuần] => các ngày trong phạm vi */
    private ?array $scopeWeeks = null;

    /** @var array<string, int[]> "outletId|shiftId" => nhân viên có năng lực làm ô này */
    private array $eligible = [];

    /**
     * @return array<string, int[]>
     */
    private function scopeWeeks(ScheduleState $state): array
    {
        if ($this->scopeWeeks === null) {
            $this->scopeWeeks = [];

            foreach ($state->scopeIndexes() as $d) {
                $this->scopeWeeks[$state->weekKey($d)][] = $d;
            }
        }

        return $this->scopeWeeks;
    }

    /**
     * @return int[]
     */
    private function eligibleFor(ScheduleState $state, int $outletId, int $shiftId): array
    {
        return $this->eligible["{$outletId}|{$shiftId}"] ??= array_keys(array_filter(
            $state->problem->employees,
            fn (EmployeeSpec $e) => $e->skillLevel($outletId, $shiftId) !== null
        ));
    }

    /**
     * Đổi mẫu OFF của một người trong một tuần: chọn lại các ngày OFF (cùng số ngày, liền nhau / ghép cạnh ngày nghỉ đã biết
     * với người OFF >= 2 ngày/tuần). Ngày được nghỉ thêm thì bỏ ca; ngày phải đi làm thêm nhận lại ô vừa bỏ nếu được,
     * không thì ô còn thiếu người nhất mà người đó làm được. Số ngày làm trong tuần không đổi.
     */
    private function movePattern(ScheduleState $state, array $employeeIds): ?callable
    {
        $e = $employeeIds[mt_rand(0, count($employeeIds) - 1)];

        if (! $change = $this->randomOffChange($state, $e)) {
            return null;
        }

        [$toOff, $toWork] = $change;
        $freed = [];

        foreach ($toOff as $d) {
            $freed[$d] = $state->plan[$e][$d];
            $state->remove($e, $d);
        }

        $placed = [];
        $undo = function () use ($state, $e, &$placed, $freed): void {
            foreach ($placed as $d) {
                $state->remove($e, $d);
            }

            foreach ($freed as $d => $slot) {
                $this->restore($state, $e, $d, $slot);
            }
        };

        foreach ($toWork as $d) {
            $slot = $this->bestSlotFor($state, $e, $d, array_values($freed));

            if ($slot === null) {
                $undo();

                return null;
            }

            [$o, $s] = ScheduleState::parseSlot($slot);
            $state->place($e, $d, $o, $s);
            $placed[] = $d;
        }

        return $undo;
    }

    /**
     * Đổi chéo mẫu OFF giữa hai người, giữ nguyên số người mỗi ô: A đổi OFF từ ngày x sang ngày y (như movePattern);
     * với mỗi cặp (y, x), người B đang OFF ngày y và đi làm ngày x vào đúng ô của A ngày y, A nhận ô của B ngày x.
     */
    private function movePatternSwap(ScheduleState $state, array $employeeIds): ?callable
    {
        $a = $employeeIds[mt_rand(0, count($employeeIds) - 1)];

        if (! $change = $this->randomOffChange($state, $a)) {
            return null;
        }

        [$toOff, $toWork] = $change;
        shuffle($toWork);
        $journal = [];

        foreach ($toOff as $k => $y) {
            $x = $toWork[$k];
            $slotY = $state->plan[$a][$y] ?? null;

            if ($slotY === null) {
                return $this->rollback($state, $journal);
            }

            [$oy, $sy] = ScheduleState::parseSlot($slotY);
            $partners = array_values(array_filter($this->eligibleFor($state, $oy, $sy), fn ($b) => $b !== $a
                && isset($state->plan[$b][$x]) && ! $state->isOccupied($b, $y)
                && ! $state->isFixed($b, $x) && ! isset($state->problem->hardUnavailable[$b][$state->dates[$y]])));

            if (! $partners) {
                return $this->rollback($state, $journal);
            }

            $b = $partners[mt_rand(0, count($partners) - 1)];
            $slotX = $state->plan[$b][$x];

            // Gỡ cả hai ô trước rồi mới đặt, để kiểm tra giờ nghỉ / ngày liên tục trên lịch mới.
            if (! $this->journalPut($state, $journal, $a, $y, null) || ! $this->journalPut($state, $journal, $b, $x, null)
                || ! $this->journalPut($state, $journal, $b, $y, ScheduleState::slotKey($oy, $sy))) {
                return $this->rollback($state, $journal);
            }

            [$ox, $sx] = ScheduleState::parseSlot($slotX);

            if (! $this->journalPut($state, $journal, $a, $x, ScheduleState::slotKey($ox, $sx))) {
                $fallback = $this->bestSlotFor($state, $a, $x, [$slotX]);

                if ($fallback === null || ! $this->journalPut($state, $journal, $a, $x, $fallback)) {
                    return $this->rollback($state, $journal);
                }
            }
        }

        return fn () => $this->rollback($state, $journal);
    }

    /**
     * Đặt một ô và ghi lại giá trị cũ vào nhật ký để hoàn tác. Trả về false nếu ô mới vi phạm (ô đã được trả về giá trị cũ).
     *
     * @param array<int, array{0: int, 1: int, 2: ?string}> $journal
     */
    private function journalPut(ScheduleState $state, array &$journal, int $e, int $d, ?string $slot): bool
    {
        $old = $state->plan[$e][$d] ?? null;

        if (! $this->put($state, $e, $d, $slot)) {
            $this->restore($state, $e, $d, $old);

            return false;
        }

        $journal[] = [$e, $d, $old];

        return true;
    }

    /**
     * Hoàn tác nhật ký theo thứ tự ngược. Luôn trả về null (để dùng trực tiếp làm kết quả bước không hợp lệ).
     *
     * @param array<int, array{0: int, 1: int, 2: ?string}> $journal
     */
    private function rollback(ScheduleState $state, array $journal): ?callable
    {
        foreach (array_reverse($journal) as [$e, $d, $old]) {
            $this->restore($state, $e, $d, $old);
        }

        return null;
    }

    /**
     * Chọn ngẫu nhiên một cách đổi ngày OFF trong một tuần cho người e (cùng số ngày OFF; người OFF >= 2 ngày/tuần
     * thì mỗi ngày OFF phải liền một ngày OFF khác trong tuần, kể cả ngày nghỉ đã biết như sinh nhật / ô khóa).
     *
     * @return array{0: int[], 1: int[]}|null [ngày chuyển sang OFF, ngày chuyển sang đi làm]
     */
    private function randomOffChange(ScheduleState $state, int $e): ?array
    {
        $employee = $state->problem->employees[$e];
        $weeks = $this->scopeWeeks($state);
        $days = $weeks[array_keys($weeks)[mt_rand(0, count($weeks) - 1)]];
        $hard = $state->problem->hardUnavailable[$e] ?? [];
        $decision = [];
        $knownOff = [];

        foreach ($days as $d) {
            if ($state->isFixed($e, $d) || isset($hard[$state->dates[$d]])) {
                if ($state->isOffDay($e, $d)) {
                    $knownOff[$d] = true;
                }

                continue;
            }

            $decision[] = $d;
        }

        $offNow = array_values(array_filter($decision, fn ($d) => ! isset($state->plan[$e][$d])));
        $k = count($offNow);

        if ($k === 0 || $k === count($decision) || count($decision) > 7) {
            return null;
        }

        // Mọi tập k ngày OFF hợp lệ: người OFF >= 2 ngày/tuần thì mỗi ngày OFF phải có ngày OFF cạnh bên trong tuần.
        $options = [];
        $n = count($decision);
        $current = array_flip($offNow);

        for ($mask = 1; $mask < (1 << $n); $mask++) {
            if (substr_count(decbin($mask), '1') !== $k) {
                continue;
            }

            $set = [];

            for ($b = 0; $b < $n; $b++) {
                if ($mask & (1 << $b)) {
                    $set[$decision[$b]] = true;
                }
            }

            if ($set == $current) {
                continue;
            }

            if ($employee->daysOffPerWeek >= 2) {
                $all = $set + $knownOff;

                foreach (array_keys($set) as $d) {
                    if (! isset($all[$d - 1]) && ! isset($all[$d + 1])) {
                        continue 2;
                    }
                }
            }

            $options[] = $set;
        }

        if (! $options) {
            return null;
        }

        $newOff = $options[mt_rand(0, count($options) - 1)];
        $toOff = array_values(array_filter($decision, fn ($d) => isset($newOff[$d]) && ! isset($current[$d])));
        $toWork = array_values(array_filter($decision, fn ($d) => ! isset($newOff[$d]) && isset($current[$d])));

        return [$toOff, $toWork];
    }

    /**
     * Ô tốt nhất cho một người phải đi làm ngày d: ưu tiên ô còn thiếu so với tối thiểu / mục tiêu, ô vừa được nhường (giữ cân bằng),
     * đúng outlet chính, chuyên trách. Null nếu không có ô hợp lệ.
     *
     * @param string[] $preferred
     */
    private function bestSlotFor(ScheduleState $state, int $e, int $d, array $preferred = []): ?string
    {
        $employee = $state->problem->employees[$e];
        $preferred = array_map(fn ($slot) => implode('|', array_slice(explode('|', $slot), 0, 2)), $preferred);
        $ranked = [];

        // Xếp hạng bằng phép tính rẻ trước, chỉ kiểm tra ràng buộc cứng (đắt) theo thứ tự hạng.
        foreach ($state->requirements[$d] ?? [] as $key => $requirement) {
            $level = $employee->skillLevel($requirement->outletId, $requirement->shiftId);
            $count = $state->count($d, $requirement->outletId, $requirement->shiftId);

            if ($level === null || $count >= $requirement->capacity()) {
                continue;
            }

            $ranked[$key] = ($count < $requirement->min ? -3000 : 0)
                + ($count < $requirement->target ? -1000 : max(0, $count - $requirement->target + 1) * 200)
                + (in_array($key, $preferred, true) ? -300 : 0)
                + ($state->problem->isHomeOutlet($employee, $requirement->outletId) ? 0 : 400)
                + (isset($state->problem->outletParents[$requirement->outletId]) && ! $state->problem->isHomeOutlet($employee, $requirement->outletId) ? 2000 : 0)
                + ($level === EmployeeSpec::PRIMARY ? 0 : 60)
                + mt_rand(0, 250);
        }

        asort($ranked);

        foreach (array_keys($ranked) as $key) {
            $requirement = $state->requirements[$d][$key];

            if (! $state->violation($e, $d, $requirement->outletId, $requirement->shiftId)) {
                return ScheduleState::slotKey($requirement->outletId, $requirement->shiftId);
            }
        }

        return null;
    }

    /**
     * Bù ô thiếu người: chọn một ô đang thiếu (ưu tiên thiếu tối thiểu), lấy người đủ điều kiện
     * đang làm ô khác cùng ngày (chuyển ô), hoặc đang OFF hôm đó (dời một ngày làm khác trong tuần sang hôm nay).
     */
    private function moveCover(ScheduleState $state): ?callable
    {
        $short = $state->shortSlots();

        if (! $short) {
            return null;
        }

        // Ô thiếu tối thiểu được chọn trước (3/4 số lần) nếu có.
        $minShort = mt_rand(0, 3) ? array_filter($short, fn ($row) => $row[2]) : [];
        $pool = array_values($minShort ?: $short);
        [$i, $requirement] = $pool[mt_rand(0, count($pool) - 1)];
        $o = $requirement->outletId;
        $s = $requirement->shiftId;
        $candidates = $this->eligibleFor($state, $o, $s);

        if (! $candidates) {
            return null;
        }

        $e = $candidates[mt_rand(0, count($candidates) - 1)];

        if ($state->isFixed($e, $i) || isset($state->problem->hardUnavailable[$e][$state->dates[$i]])) {
            return null;
        }

        $from = $state->plan[$e][$i] ?? null;

        if ($from !== null) {
            // Đang làm ô khác cùng ngày: chuyển sang ô thiếu.
            [$fo, $fs] = ScheduleState::parseSlot($from);

            if ($fo === $o && $fs === $s) {
                return null;
            }

            $state->remove($e, $i);

            if ($state->violation($e, $i, $o, $s)) {
                $this->restore($state, $e, $i, $from);

                return null;
            }

            $state->place($e, $i, $o, $s);

            return fn () => $this->restore($state, $e, $i, $from);
        }

        // Đang OFF: dời một ngày làm khác trong tuần (không cố định) sang hôm nay.
        $week = $this->scopeWeeks($state)[$state->weekKey($i)] ?? [];
        $workDays = array_values(array_filter($week, fn ($d) => $d !== $i && isset($state->plan[$e][$d]) && ! $state->isFixed($e, $d)));

        if (! $workDays) {
            return null;
        }

        $j = $workDays[mt_rand(0, count($workDays) - 1)];
        $old = $state->plan[$e][$j];
        $state->remove($e, $j);

        if ($state->violation($e, $i, $o, $s)) {
            $this->restore($state, $e, $j, $old);

            return null;
        }

        $state->place($e, $i, $o, $s);

        return function () use ($state, $e, $i, $j, $old): void {
            $state->remove($e, $i);
            $this->restore($state, $e, $j, $old);
        };
    }

    /**
     * Đặt lại một ô: gỡ ô hiện tại (nếu có) và đặt ô mới (nếu có). Trả về false nếu ô mới vi phạm.
     */
    private function put(ScheduleState $state, int $employeeId, int $i, ?string $slot): bool
    {
        $state->remove($employeeId, $i);

        if ($slot === null) {
            return true;
        }

        [$o, $s, $f] = ScheduleState::parseSlot($slot);

        if ($state->violation($employeeId, $i, $o, $s, support: $f)) {
            return false;
        }

        $state->place($employeeId, $i, $o, $s, $f);

        return true;
    }

    /**
     * Khôi phục ô về giá trị cũ mà không kiểm tra (giá trị cũ vốn hợp lệ).
     */
    private function restore(ScheduleState $state, int $employeeId, int $i, ?string $slot): void
    {
        $state->remove($employeeId, $i);

        if ($slot !== null) {
            [$o, $s, $f] = ScheduleState::parseSlot($slot);
            $state->place($employeeId, $i, $o, $s, $f);
        }
    }

    private function moveFill(ScheduleState $state, int $i, array $slots, array $employeeIds): ?callable
    {
        $open = array_values(array_filter($slots, fn (Requirement $r) => $state->count($i, $r->outletId, $r->shiftId) < $r->capacity()));

        if (! $open) {
            return null;
        }

        $requirement = $open[mt_rand(0, count($open) - 1)];
        $employeeId = $employeeIds[mt_rand(0, count($employeeIds) - 1)];

        if ($state->isOccupied($employeeId, $i) || $state->violation($employeeId, $i, $requirement->outletId, $requirement->shiftId)) {
            return null;
        }

        $state->place($employeeId, $i, $requirement->outletId, $requirement->shiftId);

        return fn () => $state->remove($employeeId, $i);
    }

    private function moveReassign(ScheduleState $state, int $i, array $slots, array $employeeIds): ?callable
    {
        $employeeId = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $from = $state->plan[$employeeId][$i] ?? null;

        if ($from === null) {
            return null;
        }

        [$fo, $fs] = ScheduleState::parseSlot($from);

        // Bỏ trống ô (chỉ có lợi khi dư người hoặc để giảm ca nặng) hoặc chuyển sang ô khác cùng ngày.
        $targets = array_values(array_filter($slots, fn (Requirement $r) => ($r->outletId !== $fo || $r->shiftId !== $fs)
            && $state->count($i, $r->outletId, $r->shiftId) < $r->capacity()));
        $pick = mt_rand(0, count($targets));
        $to = $pick < count($targets) ? ScheduleState::slotKey($targets[$pick]->outletId, $targets[$pick]->shiftId) : null;

        if (! $this->put($state, $employeeId, $i, $to)) {
            $this->restore($state, $employeeId, $i, $from);

            return null;
        }

        return fn () => $this->restore($state, $employeeId, $i, $from);
    }

    private function moveSwap(ScheduleState $state, int $i, array $employeeIds): ?callable
    {
        $a = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $slotA = $state->plan[$a][$i] ?? null;

        // Thường (3/4) chọn đối tác đang làm ô khác cùng ngày và làm được ô của A: đổi ca sáng ↔ chiều, đổi outlet.
        if ($slotA !== null && mt_rand(0, 3)) {
            [$oa, $sa] = ScheduleState::parseSlot($slotA);
            $partners = array_values(array_filter($this->eligibleFor($state, $oa, $sa),
                fn ($e) => $e !== $a && isset($state->plan[$e][$i]) && $state->plan[$e][$i] !== $slotA));
            $b = $partners ? $partners[mt_rand(0, count($partners) - 1)] : $a;
        } else {
            $b = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        }

        if ($a === $b || $state->isFixed($a, $i) || $state->isFixed($b, $i)) {
            return null;
        }

        $slotB = $state->plan[$b][$i] ?? null;

        if ($slotA === $slotB) {
            return null;
        }

        $state->remove($a, $i);
        $state->remove($b, $i);

        $restore = function () use ($state, $a, $b, $i, $slotA, $slotB): void {
            $this->restore($state, $a, $i, $slotA);
            $this->restore($state, $b, $i, $slotB);
        };

        if (! $this->put($state, $a, $i, $slotB) || ! $this->put($state, $b, $i, $slotA)) {
            $restore();

            return null;
        }

        return $restore;
    }

    /**
     * Đổi chéo thứ tự ca giữa hai người: A làm ô X ngày i, ô Y ngày j; B làm Y ngày i, X ngày j → hoán đổi cả hai ngày.
     * Số người mỗi ô không đổi; dùng để xen kẽ sáng / chiều mà không tạo ca nặng (A2 → M6).
     */
    private function moveCrossSwap(ScheduleState $state, int $i, array $scope, array $employeeIds): ?callable
    {
        $a = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $j = $scope[mt_rand(0, count($scope) - 1)];
        $x = $state->plan[$a][$i] ?? null;
        $y = $state->plan[$a][$j] ?? null;

        if ($x === null || $y === null || $x === $y || $j === $i || abs($j - $i) > 6) {
            return null;
        }

        $candidates = array_values(array_filter($employeeIds, fn ($b) => $b !== $a
            && ($state->plan[$b][$i] ?? null) === $y && ($state->plan[$b][$j] ?? null) === $x));

        if (! $candidates) {
            return null;
        }

        $b = $candidates[mt_rand(0, count($candidates) - 1)];

        foreach ([$i, $j] as $d) {
            $state->remove($a, $d);
            $state->remove($b, $d);
        }

        $restore = function () use ($state, $a, $b, $i, $j, $x, $y): void {
            foreach ([$i, $j] as $d) {
                $state->remove($a, $d);
                $state->remove($b, $d);
            }

            $this->restore($state, $a, $i, $x);
            $this->restore($state, $a, $j, $y);
            $this->restore($state, $b, $i, $y);
            $this->restore($state, $b, $j, $x);
        };

        foreach ([[$a, $i, $y], [$b, $i, $x], [$a, $j, $x], [$b, $j, $y]] as [$employeeId, $d, $slot]) {
            if (! $this->put($state, $employeeId, $d, $slot)) {
                $restore();

                return null;
            }
        }

        return $restore;
    }

    /**
     * Đổi nguyên cụm 2–7 ngày liên tiếp giữa hai người (ca lẫn OFF). Số người mỗi ô không đổi;
     * cặp OFF liền nhau được chuyển nguyên khối nên không tạo OFF lẻ – dùng để cân ngày làm và dời OFF khỏi thứ Bảy.
     */
    private function moveSwapBlock(ScheduleState $state, int $i, array $employeeIds): ?callable
    {
        $a = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $b = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        // Cụm 2–3 ngày (dời cặp OFF) hoặc tới cả tuần (đổi người trực ca đêm theo tuần).
        $days = range($i, $i + (mt_rand(0, 9) ? mt_rand(1, 2) : mt_rand(3, 6)));

        if ($a === $b) {
            return null;
        }

        $slotsA = [];
        $slotsB = [];

        foreach ($days as $d) {
            if (! ($state->inScope[$d] ?? false) || $state->isFixed($a, $d) || $state->isFixed($b, $d)) {
                return null;
            }

            $slotsA[$d] = $state->plan[$a][$d] ?? null;
            $slotsB[$d] = $state->plan[$b][$d] ?? null;
        }

        if ($slotsA === $slotsB) {
            return null;
        }

        foreach ($days as $d) {
            $state->remove($a, $d);
            $state->remove($b, $d);
        }

        $restore = function () use ($state, $a, $b, $days, $slotsA, $slotsB): void {
            foreach ($days as $d) {
                $state->remove($a, $d);
                $state->remove($b, $d);
            }

            foreach ($days as $d) {
                $this->restore($state, $a, $d, $slotsA[$d]);
                $this->restore($state, $b, $d, $slotsB[$d]);
            }
        };

        // Đặt theo thứ tự ngày để kiểm tra giờ nghỉ / ngày liên tục với ngày đã đặt trước.
        foreach ($days as $d) {
            if (! $this->put($state, $a, $d, $slotsB[$d]) || ! $this->put($state, $b, $d, $slotsA[$d])) {
                $restore();

                return null;
            }
        }

        return $restore;
    }

    /**
     * Đổi ngày giữa hai người cùng ca: A làm ngày i / nghỉ ngày j, B nghỉ ngày i / làm ngày j (cùng ô)
     * → A sang j, B sang i. Số người mỗi ô không đổi; dùng để gom ngày OFF liền nhau khi các ô đã kín người.
     */
    private function moveSwapDays(ScheduleState $state, int $i, array $scope, array $employeeIds): ?callable
    {
        $a = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $slot = $state->plan[$a][$i] ?? null;
        $j = $scope[mt_rand(0, count($scope) - 1)];

        if ($slot === null || $j === $i || abs($j - $i) > 6 || $state->isOccupied($a, $j)) {
            return null;
        }

        // Người B đang làm đúng ô đó ở ngày j và đang trống ở ngày i.
        $candidates = array_values(array_filter($employeeIds, fn ($b) => $b !== $a
            && ($state->plan[$b][$j] ?? null) === $slot
            && ! $state->isOccupied($b, $i)));

        if (! $candidates) {
            return null;
        }

        $b = $candidates[mt_rand(0, count($candidates) - 1)];
        $state->remove($a, $i);
        $state->remove($b, $j);

        $restore = function () use ($state, $a, $b, $i, $j, $slot): void {
            $this->restore($state, $a, $j, null);
            $this->restore($state, $b, $i, null);
            $this->restore($state, $a, $i, $slot);
            $this->restore($state, $b, $j, $slot);
        };

        if (! $this->put($state, $a, $j, $slot) || ! $this->put($state, $b, $i, $slot)) {
            $restore();

            return null;
        }

        return $restore;
    }

    /**
     * Gom OFF lẻ: chọn người đang có ngày OFF lẻ, dời một ngày OFF (do thuật toán chọn) sang ngày làm
     * sát một ngày OFF khác trong tuần. Người đó làm ngày cũ bằng chính ô của ngày mới; ô đầy thì đổi ngày với người đang làm ô đó.
     */
    private function moveMergeOff(ScheduleState $state, array $employeeIds): ?callable
    {
        $split = array_values(array_filter($employeeIds, fn ($e) => $state->metricsFor($e)['off_split'] > 0));

        if (! $split) {
            return null;
        }

        $a = $split[mt_rand(0, count($split) - 1)];
        $weeks = [];

        foreach ($state->scopeIndexes() as $d) {
            $weeks[$state->weekKey($d)][] = $d;
        }

        $days = $weeks[array_rand($weeks)];
        $free = fn ($d) => ($state->inScope[$d] ?? false) && ! $state->isFixed($a, $d);
        // Ngày OFF có thể dời (do thuật toán chọn) và ngày làm nằm sát một ngày OFF khác trong tuần.
        $offs = array_values(array_filter($days, fn ($d) => $free($d) && $state->isOffDay($a, $d)
            && ! isset($state->problem->hardUnavailable[$a][$state->dates[$d]])));
        $targets = array_values(array_filter($days, fn ($d) => $free($d) && isset($state->plan[$a][$d])
            && (($state->isOffDay($a, $d - 1) && in_array($d - 1, $days, true)) || ($state->isOffDay($a, $d + 1) && in_array($d + 1, $days, true)))));

        if (! $offs || ! $targets) {
            return null;
        }

        $x = $offs[mt_rand(0, count($offs) - 1)];
        $y = $targets[mt_rand(0, count($targets) - 1)];
        $slot = $state->plan[$a][$y];
        [$o, $s] = ScheduleState::parseSlot($slot);
        $requirement = $state->requirements[$x]["{$o}|{$s}"] ?? null;
        $b = null;

        if (! $requirement || $state->count($x, $o, $s) >= $requirement->capacity()) {
            // Ô ngày x đầy: tìm người đang làm đúng ô đó ngày x và đang OFF ngày y để đổi ngày.
            $candidates = array_values(array_filter($employeeIds, fn ($e) => $e !== $a && ($state->plan[$e][$x] ?? null) === $slot
                && ! $state->isOccupied($e, $y)));

            if (! $candidates) {
                return null;
            }

            $b = $candidates[mt_rand(0, count($candidates) - 1)];
        }

        return $this->shiftOff($state, $a, $b, $x, $y, $slot);
    }

    /**
     * Người A làm ngày x bằng ô đang làm ngày y (A chuyển OFF từ x sang y); nếu có B: B làm ô đó ngày y thay cho ngày x.
     * Trả về hàm hoàn tác, hoặc null nếu vi phạm (đã tự hoàn tác).
     */
    private function shiftOff(ScheduleState $state, int $a, ?int $b, int $x, int $y, string $slot): ?callable
    {
        $state->remove($a, $y);

        if ($b !== null) {
            $state->remove($b, $x);
        }

        $restore = function () use ($state, $a, $b, $x, $y, $slot): void {
            $state->remove($a, $x);
            $this->restore($state, $a, $y, $slot);

            if ($b !== null) {
                $state->remove($b, $y);
                $this->restore($state, $b, $x, $slot);
            }
        };

        if (! $this->put($state, $a, $x, $slot) || ($b !== null && ! $this->put($state, $b, $y, $slot))) {
            $restore();

            return null;
        }

        return $restore;
    }

    /**
     * Sửa OFF lẻ có hệ thống sau tìm kiếm ngẫu nhiên: với từng người còn OFF lẻ trong tuần, thử mọi cách dời một ngày OFF
     * sang ngày làm sát một ngày OFF khác cùng tuần (tự làm nếu ô còn chỗ, hoặc đổi ngày với người đang làm ô đó);
     * chỉ giữ thay đổi làm vector mục tiêu tốt hơn.
     */
    private function repairOffSplits(ScheduleState $state, float $deadline): int
    {
        $repaired = 0;
        $current = $state->cost();
        $employeeIds = array_keys($state->problem->employees);
        $weeks = [];

        foreach ($state->scopeIndexes() as $d) {
            $weeks[$state->weekKey($d)][] = $d;
        }

        foreach ($employeeIds as $a) {
            $attempt = 0;

            retry:
            if ($state->metricsFor($a)['off_split'] === 0 || ++$attempt > 4) {
                continue;
            }

            foreach ($weeks as $days) {
                $free = fn ($d) => ($state->inScope[$d] ?? false) && ! $state->isFixed($a, $d);
                $offs = array_filter($days, fn ($d) => $free($d) && $state->isOffDay($a, $d) && ! isset($state->problem->hardUnavailable[$a][$state->dates[$d]]));
                $targets = array_filter($days, fn ($d) => $free($d) && isset($state->plan[$a][$d])
                    && (($state->isOffDay($a, $d - 1) && in_array($d - 1, $days, true)) || ($state->isOffDay($a, $d + 1) && in_array($d + 1, $days, true))));

                foreach ($offs as $x) {
                    foreach ($targets as $y) {
                        if (microtime(true) > $deadline) {
                            return $repaired;
                        }

                        $slot = $state->plan[$a][$y] ?? null;

                        if ($slot === null) {
                            continue;
                        }

                        $partners = [null, ...array_filter($employeeIds, fn ($e) => $e !== $a && ($state->plan[$e][$x] ?? null) === $slot && ! $state->isOccupied($e, $y))];

                        foreach ($partners as $b) {
                            if ($b === null) {
                                [$o, $s] = ScheduleState::parseSlot($slot);
                                $requirement = $state->requirements[$x]["{$o}|{$s}"] ?? null;

                                if (! $requirement || $state->count($x, $o, $s) >= $requirement->capacity()) {
                                    continue;
                                }
                            }

                            $undo = $this->shiftOff($state, $a, $b, $x, $y, $slot);

                            if (! $undo) {
                                continue;
                            }

                            $candidate = $state->cost();

                            if ($candidate < $current) {
                                $current = $candidate;
                                $repaired++;

                                goto retry;
                            }

                            $undo();
                        }
                    }
                }
            }
        }

        return $repaired;
    }

    /**
     * Dời nguyên cặp OFF của một người: người đang OFF ngày i, i+1 và làm ngày j, j+1 → làm i, i+1 (cùng các ô), OFF j, j+1.
     * Giữ OFF liền nhau; dùng để đưa người về làm thứ Bảy và chuyển OFF sang ngày vắng khách.
     */
    private function moveShiftPair(ScheduleState $state, int $i, array $scope, array $employeeIds): ?callable
    {
        $employeeId = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $j = $scope[mt_rand(0, count($scope) - 1)];
        $to = [$i, $i + 1];
        $from = [$j, $j + 1];

        if (abs($j - $i) < 2 || abs($j - $i) > 6) {
            return null;
        }

        foreach ([...$to, ...$from] as $d) {
            if (! ($state->inScope[$d] ?? false) || $state->isFixed($employeeId, $d)) {
                return null;
            }
        }

        $slots = [$state->plan[$employeeId][$j] ?? null, $state->plan[$employeeId][$j + 1] ?? null];

        if (in_array(null, $slots, true) || isset($state->plan[$employeeId][$i]) || isset($state->plan[$employeeId][$i + 1])) {
            return null;
        }

        foreach ($slots as $k => $slot) {
            [$o, $s] = ScheduleState::parseSlot($slot);
            $requirement = $state->requirements[$to[$k]]["{$o}|{$s}"] ?? null;

            if (! $requirement || $state->count($to[$k], $o, $s) >= $requirement->capacity()) {
                return null;
            }
        }

        $state->remove($employeeId, $j);
        $state->remove($employeeId, $j + 1);

        $restore = function () use ($state, $employeeId, $to, $from, $slots): void {
            foreach ($to as $d) {
                $state->remove($employeeId, $d);
            }

            foreach ($from as $k => $d) {
                $this->restore($state, $employeeId, $d, $slots[$k]);
            }
        };

        foreach ($to as $k => $d) {
            if (! $this->put($state, $employeeId, $d, $slots[$k])) {
                $restore();

                return null;
            }
        }

        return $restore;
    }

    private function moveShiftDay(ScheduleState $state, int $i, array $scope, array $employeeIds): ?callable
    {
        $employeeId = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $slot = $state->plan[$employeeId][$i] ?? null;
        $j = $scope[mt_rand(0, count($scope) - 1)];

        if ($slot === null || $j === $i || abs($j - $i) > 6 || $state->isOccupied($employeeId, $j)) {
            return null;
        }

        [$o, $s] = ScheduleState::parseSlot($slot);
        $requirement = $state->requirements[$j]["{$o}|{$s}"] ?? null;

        if (! $requirement || $state->count($j, $o, $s) >= $requirement->capacity()) {
            return null;
        }

        $state->remove($employeeId, $i);

        if (! $this->put($state, $employeeId, $j, $slot)) {
            $this->restore($state, $employeeId, $i, $slot);

            return null;
        }

        return function () use ($state, $employeeId, $i, $j, $slot): void {
            $this->restore($state, $employeeId, $j, null);
            $this->restore($state, $employeeId, $i, $slot);
        };
    }

    /**
     * Bật / tắt (L) của một ô có khung hỗ trợ Lobby.
     */
    private function moveToggleSupport(ScheduleState $state, int $i, array $employeeIds): ?callable
    {
        $employeeId = $employeeIds[mt_rand(0, count($employeeIds) - 1)];
        $slot = $state->plan[$employeeId][$i] ?? null;

        if ($slot === null) {
            return null;
        }

        [$o, $s, $f] = ScheduleState::parseSlot($slot);

        if (! $state->problem->shifts[$s]->lobbyWindow) {
            return null;
        }

        if (! $this->put($state, $employeeId, $i, ScheduleState::slotKey($o, $s, ! $f))) {
            $this->restore($state, $employeeId, $i, $slot);

            return null;
        }

        return fn () => $this->restore($state, $employeeId, $i, $slot);
    }
}
