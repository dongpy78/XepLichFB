<?php

namespace Botble\RestaurantRoster\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Trạng thái lịch trong quá trình giải: ô cố định (khóa/ngữ cảnh) + ô thuật toán đang xếp.
 *
 * Mọi kiểm tra ràng buộc cứng nằm ở đây để thuật toán tạo lịch, thuật toán tối ưu lại,
 * chức năng "Kiểm tra lịch" và phần giải thích thiếu người dùng chung một định nghĩa.
 *
 * Vector mục tiêu (so sánh theo thứ tự, phần tử đầu quan trọng nhất – xem objective()):
 *   [0] hard        vi phạm ràng buộc cứng (chỉ có thể đến từ ô khóa/ngữ cảnh)
 *   [1] sourceShort điều chuyển người khỏi outlet chính trong khi ca chuyên trách của họ ở đó còn thiếu tối thiểu
 *   [2] minShort    thiếu so với định biên tối thiểu (tổng người + số nam + phủ Lobby)
 *   [2b] offSplit   người OFF 2 ngày/tuần phải OFF 2 ngày liền nhau: số ngày OFF lẻ (trừ ngày nhân viên tự xin / quản lý khóa)
 *                   + số ngày OFF dồn quá 3 ngày liền
 *   [3] overNorm    ngày làm vượt định mức của tuần (chỉ dùng tới khi cần để đủ tối thiểu; chế độ OFF theo cặp 2 tuần)
 *   [2a] underNorm  ngày làm thiếu so với định mức (7 − số ngày OFF/tuần) – bắt buộc đúng số ngày OFF mỗi tuần
 *                   (xếp trước OFF liền nhau; ca nhận thêm tối đa flex_capacity người trên mục tiêu để có chỗ)
 *   [3a2] childBorrow số ca người outlet khác đứng khu trực thuộc (nữ Villa đứng Kid-Deline của Gusto) – chỉ khi Gusto thiếu người
 *   [3b] busyOff    số ngày OFF rơi vào ngày đông khách (mặc định thứ Bảy): thứ Bảy được đủ người trước các ngày khác
 *   [3c] nightRotation ca đêm luân phiên theo tuần: số tuần đêm (bình phương, có lịch sử) của nam waiter nhà hàng,
 *                   ca ngày lẫn trong tuần đêm, ca ngày của nhân viên outlet NIGHT (chỉ sang nhà hàng khi thiếu nam)
 *   [3f] lobbyMix   khung Lobby nhận nhiều loại ca (sáng OH(L) + M9): chia đều loại ca – mỗi sáng 1 OH(L) Nha Trang + 1 M9 Cam Ranh
 *   [4] targetShort thiếu so với định biên mục tiêu
 *   [3e] heavy      (xếp ngay sau shiftMix: xen kẽ ca nhưng không tạo A2 → M6 / M9) số lần chuyển ca nặng (nghỉ < mức mềm, ví dụ A2 → M6)
 *   [3d] shiftMix   người không có ca cứng phải xen kẽ ca sáng / chiều trong tuần (không toàn M6, không toàn A2,
 *                   lệch tối đa shiftMixTolerance ca) – xếp trên thiếu mục tiêu để không bị hy sinh khi định biên mục tiêu luôn thiếu
 *   [6] fairness    độ lệch công bằng (ngày làm, ca nặng, ca đêm, lượt hỗ trợ Lobby)
 *   [7] offComfort  ngày OFF nên liền nhau 2–3 ngày (khi tối ưu lại: đổi chỗ với [8] để ít thay đổi nhất)
 *   [8] preference  sở thích & ổn định (đúng outlet chính, chuyên trách, đơn chưa duyệt, ít thay đổi)
 */
final class ScheduleState
{
    /** @var string[] */
    public array $dates = [];

    /** @var array<string, int> */
    public array $index = [];

    /** @var array<int, bool> */
    public array $inScope = [];

    /** @var int[]|null */
    private ?array $scope = null;

    /** @var array<int, string> */
    private array $weekOf = [];

    /** @var array<int, int> [dayIndex] => thứ ISO (1 = thứ Hai … 7 = Chủ nhật) */
    private array $weekday = [];

    /** @var array<string, int[]> */
    private array $weekDays = [];

    /** @var array<int, array<int, FixedAssignment>> */
    public array $fixed = [];

    /** @var array<int, array<int, string>> [employeeId][dayIndex] => "outletId|shiftId" */
    public array $plan = [];

    /** @var array<int, array<string, Requirement>> [dayIndex]["o|s"] => Requirement */
    public array $requirements = [];

    /** @var array<string, int> "i|o|s" => số người */
    private array $count = [];

    /** @var array<string, int> "i|o|s" => số nam */
    private array $maleCount = [];

    /** @var array<string, int> "i|o|s" => số nữ waiter thông thường làm trực tiếp tại outlet (không tính người hỗ trợ (L) / đứng Kid-Deline) */
    private array $femaleCount = [];

    /** @var array<string, array<string, int>> "i|o|s" => [nhóm vị trí => số người] */
    private array $positionCount = [];

    private int $minShort = 0;

    private int $targetShort = 0;

    /** Tổng bình phương số người dôi ra trên định biên mục tiêu (cập nhật tăng dần trong adjustCount). */
    private int $overTarget = 0;

    /** @var array<int, array<int, array{morning: int, afternoon: int}>> [dayIndex][outletId] => số quản lý ca sáng / chiều */
    private array $managerShifts = [];

    /** Tổng độ lệch quản lý sáng / chiều (xem managerPenalty), cập nhật tăng dần. */
    private int $managerImbalance = 0;

    private int $fixedHardViolations = 0;

    /** @var array<int, array<string, mixed>> */
    private array $metrics = [];

    /** @var array<int, float> */
    private array $expectedDays = [];

    /** @var array<int, bool> */
    private array $nightPool = [];

    /**
     * Người luân phiên ca đêm theo tuần: người của nhà hàng (không thuộc outlet ca đêm) làm được ca đêm lẫn ca ngày
     * và không có ca cứng. Nhân viên outlet ca đêm (NIGHT), người ca cứng Night, người chỉ làm được ca đêm không luân phiên.
     *
     * @var array<int, bool>
     */
    public array $nightRotation = [];

    /** @var array<int, bool> Outlet có ca đêm (NIGHT): nhân viên outlet này là lực lượng ca đêm chính */
    public array $nightOutlets = [];

    /** @var array<int, array<int, int>> [dayIndex][employeeId] => outletId cho các ô xếp ngoài outlet chính */
    private array $crossCells = [];

    /** @var array<int, array<int, array<int, string>>> [employeeId][outletId][shiftId] => mức năng lực ('' = không đủ điều kiện), lưu đệm */
    private array $skillLevels = [];

    /** @var array<int, array<string, true>> [dayIndex]["o|s"] ô đang thiếu tối thiểu (tổng, nam hoặc vị trí) – cập nhật tăng dần */
    private array $minShortSlots = [];

    /**
     * @var array<int, array<int, array{0: int, 1: int, 2: int, 3: int, 4: string, 5: ?array, 6: bool, 7: int}>>
     *      [dayIndex][k] => một ĐOẠN của khung phủ Lobby: [phút bắt đầu, phút kết thúc, tối thiểu, mục tiêu, nhãn, ca được tính (null = mọi ca), chỉ nữ waiter, khung gốc g]
     */
    public array $coverage = [];

    /**
     * @var array<int, array<int, array{0: int, 1: int, 2: int, 3: int, 4: string, 5: bool, 6: int[], 7: int}>>
     *      [dayIndex][g] => khung định biên gốc: [bắt đầu, kết thúc, tối thiểu, mục tiêu, nhãn, chỉ nữ waiter, chỉ số các đoạn k, số phút ca phủ tốt nhất]
     */
    public array $coverageGroups = [];

    /** @var array<int, array<int, int>> [dayIndex][k] => số người có mặt trọn đoạn k */
    private array $coverageCount = [];

    /** @var array<int, array<int, array<int, int>>> [dayIndex][k][outlet nguồn] => số người outlet đó đưa sang phủ khung Lobby k */
    private array $supportFrom = [];

    /** @var array<int, array<int, array<int, int>>> [dayIndex][k][shiftId] => số người phủ khung Lobby k bằng ca này (vd OH(L) / M9) */
    private array $supportShift = [];

    /** @var array<int, int> [dayIndex] => số thứ tự tuần (liên tục, để tìm tuần liền trước / liền sau) */
    private array $weekSeq = [];

    /** @var array<int, int[]> */
    private array $weekDaysBySeq = [];

    public function __construct(public readonly Problem $problem)
    {
        $this->buildTimeline();

        $hasCore = (bool) array_filter($problem->employees, fn (EmployeeSpec $e) => $e->isNightCore());

        foreach ($problem->requirements as $requirement) {
            $i = $this->index[$requirement->date] ?? null;

            // Night: mỗi ngày có ít nhất 1 nhân viên Night cứng (điều phối) – tính như thiếu tối thiểu.
            if ($hasCore && $i !== null && $this->inScope[$i] && $requirement->min > 0
                && ($problem->shifts[$requirement->shiftId] ?? null)?->category() === 'night') {
                $this->coreNeed[$i][$requirement->outletId] = 0;
                $this->minShort++;
            }

            if ($i !== null && $this->inScope[$i]) {
                $this->requirements[$i]["{$requirement->outletId}|{$requirement->shiftId}"] = $requirement;

                $slot = "{$requirement->outletId}|{$requirement->shiftId}";
                $belowMin = $requirement->min > 0 || $requirement->minMale > 0 || $requirement->minFemale > 0 || array_filter($requirement->positionMins);

                if ($belowMin) {
                    $this->minShortSlots[$i][$slot] = true;
                }

                if ($belowMin || $requirement->target > 0 || $requirement->targetMale > 0 || $requirement->targetFemale > 0 || array_filter($requirement->positionTargets)) {
                    $this->shortSlots["{$i}|{$slot}"] = [$i, $requirement, (bool) $belowMin];
                }
                $this->minShort += $requirement->min + $requirement->minMale + $requirement->minFemale + array_sum($requirement->positionMins);
                $this->targetShort += $requirement->target + $requirement->targetMale + $requirement->targetFemale
                    + array_sum(array_map(fn ($group) => $requirement->positionTarget($group), array_keys($requirement->positionMins + $requirement->positionTargets)));
            }
        }

        // Khung phủ Lobby → các đoạn tại mốc vào / ra Lobby của các ca (đếm người có mặt cùng lúc, xem LobbyCoverage).
        $stationShifts = [];

        foreach ($problem->requirements as $requirement) {
            if ($problem->stationOutletId !== null && $requirement->outletId === $problem->stationOutletId) {
                $stationShifts[$requirement->date][$requirement->shiftId] = true;
            }
        }

        foreach ($problem->fixed as $assignment) {
            if ($problem->stationOutletId !== null && $assignment->outletId === $problem->stationOutletId && $assignment->shiftId !== null) {
                $stationShifts[$assignment->date][$assignment->shiftId] = true;
            }
        }

        foreach ($problem->coverage as $window) {
            $i = $this->index[$window->date] ?? null;

            if ($i === null || ! $this->inScope[$i]) {
                continue;
            }

            $intervals = [];

            foreach ($problem->shifts as $shift) {
                if ($window->shiftIds !== null && ! in_array($shift->id, $window->shiftIds, true)) {
                    continue;
                }

                if ($shift->lobbyWindow) {
                    $intervals[] = $shift->lobbyWindow;
                }

                if (isset($stationShifts[$window->date][$shift->id])) {
                    $intervals[] = [$shift->start(), $shift->end()];
                }
            }

            $group = count($this->coverageGroups[$i] ?? []);
            $target = max($window->min, $window->target);
            $segments = [];

            foreach (LobbyCoverage::segments($window->start, $window->end, $intervals) as [$start, $end]) {
                $segments[] = count($this->coverage[$i] ?? []);
                $this->coverage[$i][] = [$start, $end, $window->min, $target, $window->label, $window->shiftIds, $window->femaleOnly, $group];
                $this->coverageCount[$i][] = 0;
                $this->minShort += $window->min;
                $this->targetShort += $target;
            }

            // Thời gian có mặt dài nhất mà một ca phủ được trong khung (OH(L) / M9): ca kém hơn mức này là phương án thay thế.
            $best = max([0, ...array_map(fn ($iv) => max(0, min($window->end, $iv[1]) - max($window->start, $iv[0])), $intervals)]);
            $this->coverageGroups[$i][$group] = [$window->start, $window->end, $window->min, $target, $window->label, $window->femaleOnly, $segments, $best];
        }

        foreach ($problem->fixed as $assignment) {
            $i = $this->index[$assignment->date] ?? null;

            if ($i === null) {
                continue;
            }

            $this->fixed[$assignment->employeeId][$i] = $assignment;

            if ($assignment->isWorking() && $this->inScope[$i]) {
                $this->adjustCount($i, $assignment->outletId, $assignment->shiftId, $assignment->employeeId, 1, $assignment->support);
            }
        }

        foreach ($problem->requirements as $requirement) {
            if (($problem->shifts[$requirement->shiftId] ?? null)?->category() === 'night') {
                $this->nightOutlets[$requirement->outletId] = true;
            }
        }

        foreach ($problem->employees as $employee) {
            $available = 0;

            foreach ($problem->days as $date) {
                if (! isset($problem->hardUnavailable[$employee->id][$date]) || isset($problem->regularOff[$employee->id][$date])) {
                    $available++;
                }
            }

            $this->expectedDays[$employee->id] = $available * $employee->maxWorkDaysPerWeek() / 7;

            $dayShift = false;

            foreach ($problem->shifts as $shift) {
                if ($shift->category() === 'night' && $this->canEverWork($employee, $shift->id)) {
                    $this->nightPool[$employee->id] = true;
                } elseif ($shift->category() !== 'night' && $this->canEverWork($employee, $shift->id)) {
                    $dayShift = true;
                }
            }

            // Người luân phiên Night: đánh dấu trong hồ sơ (nam waiter Villa / Gusto / Night đủ điều kiện), hoặc dữ liệu cũ: người ngoài outlet Night có năng lực ca đêm.
            if ($employee->nightRole === EmployeeSpec::NIGHT_ROTATION
                || ($employee->nightRole === null && isset($this->nightPool[$employee->id]) && $dayShift && ! $employee->fixed && ! isset($this->nightOutlets[$employee->homeOutletId]))) {
                $this->nightRotation[$employee->id] = true;
            }
        }

        $this->fixedHardViolations = count($this->validate(fixedOnly: true));
        $this->dirty = array_fill_keys(array_keys($problem->employees), true);
    }

    // ------------------------------------------------------------------ Truy vấn

    public function scopeIndexes(): array
    {
        return $this->scope ??= array_keys(array_filter($this->inScope));
    }

    public function weekKey(int $i): string
    {
        return $this->weekOf[$i];
    }

    public function isBusyDay(int $i): bool
    {
        return in_array($this->weekday[$i], $this->problem->rules->busyWeekdays, true);
    }

    public function isFixed(int $employeeId, int $i): bool
    {
        return isset($this->fixed[$employeeId][$i]);
    }

    /**
     * Ca nhân viên đang làm ở ngày i (cố định hoặc do thuật toán xếp).
     *
     * @return array{0: ?int, 1: int, 2: float, 3: bool}|null [outletId, shiftId, overtimeHours, hỗ trợ Lobby]
     */
    public function shiftAt(int $employeeId, int $i): ?array
    {
        if (isset($this->fixed[$employeeId][$i])) {
            $fixed = $this->fixed[$employeeId][$i];

            return $fixed->isWorking() ? [$fixed->outletId, $fixed->shiftId, $fixed->overtimeHours, $fixed->support] : null;
        }

        if (isset($this->plan[$employeeId][$i])) {
            [$o, $s, $f] = self::parseSlot($this->plan[$employeeId][$i]);

            return [$o, $s, 0.0, $f];
        }

        return null;
    }

    /**
     * Ô do thuật toán xếp được lưu dạng "outletId|shiftId|cờ hỗ trợ Lobby (0/1)".
     *
     * @return array{0: int, 1: int, 2: bool}
     */
    public static function parseSlot(string $slot): array
    {
        $parts = explode('|', $slot);

        return [(int) $parts[0], (int) $parts[1], ($parts[2] ?? '0') === '1'];
    }

    public static function slotKey(int $outletId, int $shiftId, bool $support = false): string
    {
        return "{$outletId}|{$shiftId}|" . ($support ? '1' : '0');
    }

    /**
     * Khoảng thời gian (phút trong ngày) nhân viên có mặt ở trạm hỗ trợ (Lobby) với ô này, hoặc null.
     *
     * @return array{0: int, 1: int}|null
     */
    public function supportInterval(?int $outletId, int $shiftId, bool $support): ?array
    {
        $shift = $this->problem->shifts[$shiftId] ?? null;

        if (! $shift) {
            return null;
        }

        if ($outletId !== null && $outletId === $this->problem->stationOutletId) {
            return [$shift->start(), $shift->end()];
        }

        return $support ? $shift->lobbyWindow : null;
    }

    /**
     * Số người outlet nguồn đang đưa sang phủ khung Lobby k của ngày i (gồm (L) và ca làm trọn ở Lobby theo outlet chính).
     */
    public function supportShiftCount(int $i, int $k, int $shiftId): int
    {
        return $this->supportShift[$i][$k][$shiftId] ?? 0;
    }

    public function supportFrom(int $i, int $k, ?int $outletId): int
    {
        return $outletId === null ? 0 : ($this->supportFrom[$i][$k][$outletId] ?? 0);
    }

    /**
     * Outlet "cho người" của một ô phủ Lobby: ô (L) lấy từ outlet của ca; ca làm trọn ở Lobby (M9) tính vào outlet chính của nhân viên.
     */
    public function supportSource(int $employeeId, ?int $outletId): ?int
    {
        if ($outletId !== null && $outletId === $this->problem->stationOutletId) {
            return $this->problem->employees[$employeeId]?->homeOutletId;
        }

        return $outletId;
    }

    /**
     * Khung Lobby mà ô (outlet, ca, cờ L) phủ được trong ngày i.
     *
     * @return int[]
     */
    public function supportWindows(int $i, ?int $outletId, int $shiftId, bool $support): array
    {
        return $this->coveredWindows($i, $this->supportInterval($outletId, $shiftId, $support), $shiftId);
    }

    /**
     * Điểm "tải Lobby" để chọn người: lượt trong kỳ đang xếp là chính, lượt trung bình/tuần ở các tuần trước để phân xử.
     */
    public function lobbyLoad(int $employeeId): float
    {
        return $this->metricsFor($employeeId)['lobby'] + 0.5 * ($this->problem->lobbyHistory[$employeeId] ?? 0);
    }

    /**
     * Số lượt tích lũy của một loại ca hỗ trợ (vd "OH(L)", "A2(K)"): lịch sử khoảng đánh giá + đã xếp trong kỳ.
     */
    public function supportTurns(int $employeeId, string $type): int
    {
        return ($this->problem->supportHistory[$employeeId][$type] ?? 0) + ($this->metricsFor($employeeId)['support_types'][$type] ?? 0);
    }

    /**
     * Người này được tính phủ đoạn k (đoạn chỉ đếm nữ waiter thì người khác không tính).
     */
    public function countsForCoverage(int $employeeId, int $i, int $k): bool
    {
        return ! ($this->coverage[$i][$k][6] ?? false) || ($this->problem->employees[$employeeId]?->isFemaleStaff ?? false);
    }

    /**
     * Số khung phủ Lobby trong ngày mà ô này có mặt ở Lobby nhưng không trọn khung (vd M6(L) 09–14h trong khung 09:00–16:45):
     * phương án thay thế – chỉ dùng khi ca phủ trọn khung (OH(L), M9) không đủ.
     */
    public function lobbyPartial(int $i, ?int $outletId, int $shiftId, bool $support): int
    {
        $interval = $this->supportInterval($outletId, $shiftId, $support);

        if (! $interval || empty($this->coverageGroups[$i])) {
            return 0;
        }

        $partial = 0;

        // Phủ một phần = có mặt ở Lobby ngắn hơn ca phủ tốt nhất của khung quá 30 phút (vd M6(L) 5h, A2(L) 3h so với OH(L) / M9 8h).
        // Không so với cả khung: khung đặt rộng hơn giờ ca (vd 08:30–17:00) vẫn ưu tiên đúng OH(L) + M9.
        foreach ($this->coverageGroups[$i] as $group) {
            [$start, $end] = $group;
            $overlap = min($end, $interval[1]) - max($start, $interval[0]);

            if ($overlap > 0 && ! LobbyCoverage::covers($interval, $start, $end) && $overlap < ($group[7] ?? $end - $start) - 30) {
                $partial++;
            }
        }

        return $partial;
    }

    public function coverageCount(int $i, int $k): int
    {
        return $this->coverageCount[$i][$k] ?? 0;
    }

    /**
     * Đoạn phủ Lobby mà khoảng [start, end] có mặt TRỌN đoạn (đếm người cùng lúc, xem LobbyCoverage).
     *
     * @return int[] chỉ số đoạn
     */
    public function coveredWindows(int $i, ?array $interval, ?int $shiftId = null): array
    {
        if (! $interval || empty($this->coverage[$i])) {
            return [];
        }

        $covered = [];

        foreach ($this->coverage[$i] as $k => [$start, $end, , , , $shiftIds]) {
            // Khung chỉ nhận một số ca (vd sáng: OH, M9; chiều: A2).
            if ($shiftIds !== null && ($shiftId === null || ! in_array($shiftId, $shiftIds, true))) {
                continue;
            }

            if (LobbyCoverage::covers($interval, $start, $end)) {
                $covered[] = $k;
            }
        }

        return $covered;
    }

    /**
     * Ngày làm của nhân viên trong tuần chứa ngày i (kể cả ô cố định).
     */
    public function workedInWeek(int $employeeId, int $i): int
    {
        $days = 0;

        foreach ($this->weekDays[$this->weekOf[$i]] as $k) {
            if ($this->shiftAt($employeeId, $k)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Thêm một ngày làm ở ngày i có vượt định mức tuần (7 − số ngày OFF/tuần) không.
     */
    /**
     * Số ca sáng / chiều nhân viên đang làm trong tuần chứa ngày i.
     *
     * @return array{morning: int, afternoon: int}
     */
    public function weekCategoryCounts(int $employeeId, int $i): array
    {
        $counts = ['morning' => 0, 'afternoon' => 0];

        foreach ($this->weekDays[$this->weekOf[$i]] as $k) {
            if (($current = $this->shiftAt($employeeId, $k)) && isset($counts[$category = $this->problem->shifts[$current[1]]->category()])) {
                $counts[$category]++;
            }
        }

        return $counts;
    }

    public function wouldExceedWeeklyNorm(int $employeeId, int $i): bool
    {
        return $this->workedInWeek($employeeId, $i) + 1 > $this->problem->employees[$employeeId]->maxWorkDaysPerWeek();
    }

    public function isOccupied(int $employeeId, int $i): bool
    {
        return isset($this->fixed[$employeeId][$i]) || isset($this->plan[$employeeId][$i]);
    }

    public function count(int $i, int $outletId, int $shiftId): int
    {
        return $this->count["{$i}|{$outletId}|{$shiftId}"] ?? 0;
    }

    public function maleCount(int $i, int $outletId, int $shiftId): int
    {
        return $this->maleCount["{$i}|{$outletId}|{$shiftId}"] ?? 0;
    }

    /**
     * Số nữ waiter thông thường làm trực tiếp tại outlet trong ca (người hỗ trợ Lobby (L) và người đứng Kid-Deline không được tính).
     */
    public function femaleCount(int $i, int $outletId, int $shiftId): int
    {
        return $this->femaleCount["{$i}|{$outletId}|{$shiftId}"] ?? 0;
    }

    public function positionCount(int $i, int $outletId, int $shiftId, int|string|null $group): int
    {
        return $group === null ? 0 : ($this->positionCount["{$i}|{$outletId}|{$shiftId}"][(string) $group] ?? 0);
    }

    /**
     * Nhân viên có làm ô thiếu tối thiểu theo nhóm vị trí (của chính họ) không – khi bớt (delta = -1) hoặc hiện tại (0).
     */
    private function positionShort(int $employeeId, int $i, Requirement $requirement, int $delta): bool
    {
        foreach ($this->problem->groupsOfPosition($this->problem->employees[$employeeId]->positionId) as $group) {
            $need = $requirement->positionMins[$group] ?? 0;

            if ($need > 0 && $this->positionCount($i, $requirement->outletId, $requirement->shiftId, $group) + $delta < $need) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rút nhân viên khỏi ô (outlet, ca) ngày i có làm ô đó xuống dưới tối thiểu (tổng, nam, vị trí) không.
     */
    public function removalBreaksMinimum(int $employeeId, int $i, int $outletId, int $shiftId): bool
    {
        $requirement = $this->requirements[$i]["{$outletId}|{$shiftId}"] ?? null;

        if (! $requirement) {
            return false;
        }

        $employee = $this->problem->employees[$employeeId];

        return $this->count($i, $outletId, $shiftId) - 1 < $requirement->min
            || ($employee->countsAsMale() && $this->maleCount($i, $outletId, $shiftId) - 1 < $requirement->minMale)
            || ($employee->isFemaleStaff && $this->femaleCount($i, $outletId, $shiftId) - 1 < $requirement->minFemale)
            || $this->positionShort($employeeId, $i, $requirement, -1);
    }

    // ------------------------------------------------------------------ Thay đổi

    public function place(int $employeeId, int $i, int $outletId, int $shiftId, bool $support = false): void
    {
        $this->plan[$employeeId][$i] = self::slotKey($outletId, $shiftId, $support);
        $this->adjustCount($i, $outletId, $shiftId, $employeeId, 1, $support);
        unset($this->metrics[$employeeId]);
        $this->dirty[$employeeId] = true;

        if ($outletId !== $this->problem->employees[$employeeId]->homeOutletId) {
            $this->crossCells[$i][$employeeId] = $outletId;
        }
    }

    public function remove(int $employeeId, int $i): void
    {
        if (! isset($this->plan[$employeeId][$i])) {
            return;
        }

        [$o, $s, $f] = self::parseSlot($this->plan[$employeeId][$i]);
        unset($this->plan[$employeeId][$i], $this->crossCells[$i][$employeeId]);
        $this->adjustCount($i, $o, $s, $employeeId, -1, $f);
        unset($this->metrics[$employeeId]);
        $this->dirty[$employeeId] = true;
    }

    // ------------------------------------------------------------------ Ràng buộc cứng

    /**
     * Kiểm tra xếp nhân viên vào outlet/ca ở ngày i. Trả về null nếu hợp lệ,
     * ngược lại [mã, thông điệp]. Giả định ô (e, i) hiện đang trống.
     *
     * @return array{0: string, 1: string}|null
     */
    public function violation(int $employeeId, int $i, int $outletId, int $shiftId, float $overtime = 0, bool $selfCounted = false, bool $checkEligibility = true, bool $support = false): ?array
    {
        $employee = $this->problem->employees[$employeeId] ?? null;
        $shift = $this->problem->shifts[$shiftId] ?? null;
        $date = $this->dates[$i];

        if (! $employee || ! $shift) {
            return ['unknown', 'Dữ liệu nhân viên hoặc ca không tồn tại.'];
        }

        if ($reason = $this->problem->hardUnavailable[$employeeId][$date] ?? null) {
            return ['unavailable', "Đã được duyệt nghỉ: {$reason}"];
        }

        if ($checkEligibility && $employee->skillLevel($outletId, $shiftId) === null) {
            return ['not_eligible', sprintf('Không đủ điều kiện làm %s tại %s', $shift->code, $this->problem->outletName($outletId))];
        }

        if ($checkEligibility && $shift->allowedGender !== null && $employee->gender !== $shift->allowedGender) {
            return ['gender', sprintf('Ca %s chỉ dành cho nhân viên %s', $shift->code, $shift->allowedGender === 'female' ? 'nữ' : 'nam')];
        }

        if ($checkEligibility && $shift->allowedPositionId !== null && $employee->positionId !== $shift->allowedPositionId) {
            return ['position', "Ca {$shift->code} chỉ dành cho một vị trí nhất định (vd Waiter)"];
        }

        if ($checkEligibility && $shift->allowedResidence !== null && $employee->residence !== $shift->allowedResidence) {
            return ['residence', sprintf('Ca %s chỉ dành cho nhân viên ở khu vực "%s" (xe đưa đón)', $shift->code, $shift->allowedResidence)];
        }

        // Danh sách ca được tự xếp (auto_shift_codes): vd "OH(L)" được, "OH" (OH ở nhà hàng, không hỗ trợ Lobby) thì không.
        if ($checkEligibility && $this->problem->rules->autoShiftCodes !== null
            && ! in_array($code = $this->shiftKey($outletId, $shiftId, $support), $this->problem->rules->autoShiftCodes, true)) {
            return ['not_allowed', "Ca {$code} không thuộc danh sách ca được xếp tự động (chỉ quản lý xếp tay)"];
        }

        // Night luân phiên theo kế hoạch tuần: người luân phiên không có tên trong kế hoạch tuần này không được tự xếp ca đêm.
        if ($checkEligibility && isset($this->nightRotation[$employeeId]) && $shift->category() === 'night'
            && $this->problem->nightPlanned($employeeId, $date) === false) {
            return ['night_plan', 'Chưa được chọn luân phiên Night tuần này (Quản lý nhân sự Night)'];
        }

        // Người luân phiên Night: hôm sau đêm cuối bắt buộc OFF (không xếp ca ngày ngay sau ca đêm, không xếp đêm ngay trước ca ngày).
        if ($checkEligibility && $employee->nightRole === EmployeeSpec::NIGHT_ROTATION) {
            $isNight = $shift->category() === 'night';
            $prev = $i > 0 ? $this->shiftAt($employeeId, $i - 1) : null;
            $next = isset($this->dates[$i + 1]) ? $this->shiftAt($employeeId, $i + 1) : null;

            if ((! $isNight && $prev && $this->problem->shifts[$prev[1]]->category() === 'night')
                || ($isNight && $next && $this->problem->shifts[$next[1]]->category() !== 'night')) {
                return ['night_off', 'Người luân phiên Night phải OFF ngay sau đêm cuối'];
            }

            // Mỗi ngày tối đa 2 người luân phiên (người thứ 3 luôn là Night cứng điều phối): ngày không có Night cứng
            // không được lấp bằng 3 người luân phiên – báo thiếu Night cứng thay vì xếp sai.
            if ($isNight && ($this->rotationNights[$i] ?? 0) - ($this->shiftAt($employeeId, $i) && $this->problem->shifts[$this->shiftAt($employeeId, $i)[1]]->category() === 'night' ? 1 : 0) >= 2) {
                return ['night_rotation_max', 'Night ngày này đã có 2 người luân phiên (người còn lại phải là Night cứng)'];
            }

            // Tối đa 3 đêm / tuần được chọn luân phiên (mục tiêu 2, đêm thứ 3 chỉ khi cần đủ 3 người / ngày).
            if ($isNight && $this->problem->nightPlanned($employeeId, $date) === true) {
                $nights = [$i];

                foreach ($this->weekDaysBySeq[$this->weekSeq[$i]] ?? [] as $k) {
                    $cell = $k === $i ? null : $this->shiftAt($employeeId, $k);

                    if ($cell && $this->problem->shifts[$cell[1]]->category() === 'night') {
                        $nights[] = $k;
                    }
                }

                if (count($nights) > 3) {
                    return ['night_max', 'Người luân phiên Night đã đủ 3 đêm trong tuần'];
                }

                // Các đêm liền nhau là ưu tiên mạnh (night_plan_gap), không phải ràng buộc cứng: khi bắt buộc để Night đủ 3 người / ngày
                // thì được tách cụm (quản lý 24/10/2026: "đủ người" trước).
            }
        }

        if ($support) {
            if (isset($this->problem->outletParents[$outletId])) {
                return ['support', sprintf('%s không đi hỗ trợ Lobby (L)', $this->problem->outletName($outletId))];
            }

            if (! $shift->lobbyWindow) {
                return ['support', "Ca {$shift->code} không có khung hỗ trợ Lobby (L)"];
            }

            // Hỗ trợ Lobby (L) chỉ đi từ outlet chính (không mượn ca của outlet khác rồi đưa đi Lobby, vd "OH(G)(L)").
            if ($checkEligibility && $employee->homeOutletId !== null && $outletId !== $employee->homeOutletId) {
                return ['support', 'Hỗ trợ Lobby (L) chỉ từ outlet chính của nhân viên'];
            }

            if ($checkEligibility && $this->problem->rules->supportGender !== null && $employee->gender !== $this->problem->rules->supportGender) {
                return ['gender', 'Hỗ trợ Lobby (L) chỉ dành cho nhân viên nữ'];
            }

            if ($checkEligibility && ! $employee->canSupport($this->problem->stationOutletId, $shiftId)) {
                return ['not_eligible', 'Chưa được cấu hình năng lực hỗ trợ Lobby'];
            }

        }

        // Mỗi outlet chỉ góp tối đa N người cho một khung Lobby (người đi Lobby là người rút khỏi nhà hàng).
        if ($support || ($outletId === $this->problem->stationOutletId && $outletId !== null)) {
            $source = $this->supportSource($employeeId, $outletId);
            $cap = $this->problem->rules->maxSupportPerOutlet;

            foreach ($this->supportWindows($i, $outletId, $shiftId, $support) as $k) {
                if ($source !== null && $this->supportFrom($i, $k, $source) + 1 > $cap) {
                    return ['support_cap', sprintf('%s đã có %d người sang Lobby trong khung này (tối đa %d / outlet)',
                        $this->problem->outletName($source), $cap, $cap)];
                }
            }
        }

        $hours = $shift->hours + $overtime;

        if ($hours > $this->problem->rules->maxDailyHours + 1e-6) {
            return ['daily_hours', sprintf('Vượt %sh/ngày', $this->problem->rules->maxDailyHours)];
        }

        // Giờ nghỉ & quy tắc chuyển tiếp với ngày trước / ngày sau.
        $start = $i * 1440 + $shift->start();
        $end = $i * 1440 + $shift->end() + (int) round($overtime * 60);

        if ($i > 0 && ($prev = $this->shiftAt($employeeId, $i - 1))) {
            $prevShift = $this->problem->shifts[$prev[1]];
            $prevEnd = ($i - 1) * 1440 + $prevShift->end() + (int) round($prev[2] * 60);

            if ($message = $this->transitionViolation($prevShift, $shift, $start - $prevEnd)) {
                return ['rest', $message];
            }
        }

        if (isset($this->dates[$i + 1]) && ($next = $this->shiftAt($employeeId, $i + 1))) {
            $nextShift = $this->problem->shifts[$next[1]];
            $nextStart = ($i + 1) * 1440 + $nextShift->start();

            if ($message = $this->transitionViolation($shift, $nextShift, $nextStart - $end)) {
                return ['rest', $message];
            }
        }

        // Số ngày làm liên tục.
        $run = 1;

        for ($k = $i - 1; $k >= 0 && $this->shiftAt($employeeId, $k); $k--) {
            $run++;
        }

        for ($k = $i + 1; isset($this->dates[$k]) && $this->shiftAt($employeeId, $k); $k++) {
            $run++;
        }

        if ($run > $this->problem->rules->maxConsecutiveDays) {
            return ['consecutive', sprintf('Sẽ làm liên tục %d ngày (tối đa %d)', $run, $this->problem->rules->maxConsecutiveDays)];
        }

        // Số ngày làm / giờ làm trong tuần (không tự ép tăng ca).
        $days = $selfCounted ? 0 : 1;
        $weekHours = $selfCounted ? 0 : $hours;

        foreach ($this->weekDays[$this->weekOf[$i]] as $k) {
            if ($worked = $this->shiftAt($employeeId, $k)) {
                $days++;
                $weekHours += $this->problem->shifts[$worked[1]]->hours + $worked[2];
            }
        }

        $rules = $this->problem->rules;

        if ($rules->daysOffMode === 'fortnight') {
            // Mỗi tuần ít nhất min_off_per_week ngày OFF (luật: nghỉ ít nhất 24h liên tục mỗi tuần)…
            $weekCap = max(0, 7 - $rules->minOffPerWeek);

            if ($days > $weekCap) {
                return ['days_off_quota', sprintf('Đã làm %d ngày trong tuần (mỗi tuần ít nhất %d ngày OFF)', $weekCap, $rules->minOffPerWeek)];
            }

            // …và đủ 2 × số ngày OFF/tuần trong MỌI cặp 2 tuần liên tiếp (tuần trước + tuần này, tuần này + tuần sau),
            // để không thể có hai tuần 6 ngày liền nhau.
            foreach ([$this->weekSeq[$i] - 1, $this->weekSeq[$i] + 1] as $neighbour) {
                $pairDays = $days;

                foreach ($this->weekDaysBySeq[$neighbour] ?? [] as $k) {
                    if ($this->shiftAt($employeeId, $k)) {
                        $pairDays++;
                    }
                }

                if ($pairDays > 2 * $employee->maxWorkDaysPerWeek()) {
                    return ['days_off_quota', sprintf('Đã đủ %d ngày làm trong 2 tuần liền (cần %d ngày OFF / 2 tuần)', 2 * $employee->maxWorkDaysPerWeek(), 2 * $employee->daysOffPerWeek)];
                }
            }
        } elseif ($days > $employee->maxWorkDaysPerWeek()) {
            return ['days_off_quota', sprintf('Đã đủ %d ngày làm trong tuần (cần %d ngày OFF)', $employee->maxWorkDaysPerWeek(), $employee->daysOffPerWeek)];
        }

        if ($weekHours > $employee->weeklyHours + 1e-6) {
            return ['weekly_hours', sprintf('Vượt %sh/tuần', rtrim(rtrim(number_format($employee->weeklyHours, 1), '0'), '.'))];
        }

        return null;
    }

    /**
     * Kiểm tra toàn bộ lịch hiện tại (ô khóa + ô đã xếp) với ràng buộc cứng.
     *
     * @return array<int, array{employee_id: int, date: string, outlet_id: ?int, shift_id: ?int, code: string, message: string, fixed: bool}>
     */
    public function validate(bool $fixedOnly = false): array
    {
        $violations = [];

        foreach ($this->problem->employees as $employeeId => $employee) {
            foreach ($this->scopeIndexes() as $i) {
                $isFixed = $this->isFixed($employeeId, $i);

                if ($fixedOnly && ! $isFixed) {
                    continue;
                }

                $current = $this->shiftAt($employeeId, $i);

                if (! $current) {
                    continue;
                }

                [$o, $s, $ot, $f] = $current;

                // Đánh giá ô như thể nó chưa được đếm (tạm bỏ ra rồi trả lại) để dùng chung một bộ kiểm tra.
                $backup = $this->fixed[$employeeId][$i] ?? null;
                unset($this->fixed[$employeeId][$i]);
                $planned = $this->plan[$employeeId][$i] ?? null;
                unset($this->plan[$employeeId][$i]);

                // Ô cố định (quản lý xếp tay / khóa) ngoài năng lực hoặc khác ca cứng là quyết định của quản lý:
                // không kiểm tra năng lực, nhưng giờ nghỉ, ngày làm liên tục, ngày OFF vẫn được kiểm tra.
                // Ô (L) đang được đếm trong số người đi hỗ trợ của outlet: trừ tạm để không tự tính chính nó vào trần.
                $source = $this->supportSource($employeeId, $o);
                $windows = $source === null ? [] : array_filter($this->supportWindows($i, $o, $s, $f), fn ($k) => $this->countsForCoverage($employeeId, $i, $k));

                foreach ($windows as $k) {
                    $this->supportFrom[$i][$k][$source]--;
                }

                // Ô đêm của người luân phiên cũng đang được đếm trong số người luân phiên của ngày: trừ tạm.
                $rotationNight = $employee->nightRole === EmployeeSpec::NIGHT_ROTATION && $this->problem->shifts[$s]->category() === 'night';
                $rotationNight && $this->rotationNights[$i]--;

                $result = $this->violation($employeeId, $i, (int) $o, $s, $ot, checkEligibility: ! $isFixed, support: $f);

                $rotationNight && $this->rotationNights[$i]++;

                foreach ($windows as $k) {
                    $this->supportFrom[$i][$k][$source]++;
                }

                if ($backup) {
                    $this->fixed[$employeeId][$i] = $backup;
                }

                if ($planned) {
                    $this->plan[$employeeId][$i] = $planned;
                }

                if ($result) {
                    $violations[] = [
                        'employee_id' => $employeeId,
                        'date' => $this->dates[$i],
                        'outlet_id' => $o,
                        'shift_id' => $s,
                        'code' => $result[0],
                        'message' => $result[1],
                        'fixed' => $isFixed,
                    ];
                }
            }
        }

        // Một vi phạm giữa hai ô liền kề được báo ở cả hai ô; giữ một bản cho mỗi cặp mã + nhân viên + ngày.
        return array_values(array_unique($violations, SORT_REGULAR));
    }

    // ------------------------------------------------------------------ Mục tiêu

    /**
     * Vector mục tiêu theo thứ tự objectiveLabels() – dùng để báo cáo và so sánh; bộ giải tối ưu cost().
     *
     * @return int[]
     */
    public function objective(): array
    {
        $values = $this->objectiveValues();

        return array_map(fn ($label) => $values[$label], self::objectiveLabels($this->problem->rules));
    }

    /**
     * Điểm phạt tổng của lịch (càng nhỏ càng tốt): tổng có trọng số của các mục tiêu, chia 3 nhóm
     * Bắt buộc (trọng số rất lớn, không bao giờ đổi lấy nhóm dưới) / Quan trọng / Nên có – xem Rules::weights().
     */
    public function cost(): float
    {
        $this->weights ??= $this->problem->rules->weights();
        $cost = 0.0;

        foreach ($this->objectiveValues() as $label => $value) {
            $cost += $value * $this->weights[$label];
        }

        return $cost;
    }

    /**
     * Điểm phạt theo trọng số thật và theo một bộ trọng số khác (pha dựng khung của bộ giải), tính mục tiêu một lần.
     *
     * @param array<string, float> $weights
     * @return array{0: float, 1: float} [điểm thật, điểm theo $weights]
     */
    public function costs(array $weights): array
    {
        $this->weights ??= $this->problem->rules->weights();
        $real = 0.0;
        $other = 0.0;

        foreach ($this->objectiveValues() as $label => $value) {
            $real += $value * $this->weights[$label];
            $other += $value * ($weights[$label] ?? $this->weights[$label]);
        }

        return [$real, $other];
    }

    /** @var array<string, float>|null */
    private ?array $weights = null;

    /** @var array<int, true> Người có ô thay đổi từ lần cộng dồn gần nhất */
    private array $dirty = [];

    /** @var array<int, array<string, float|int>> [employeeId] => phần đóng góp vào tổng mục tiêu (lần cộng dồn gần nhất) */
    private array $terms = [];

    /** @var array<string, float|int> Tổng các phần đóng góp của mọi người */
    private array $totals = [];

    /**
     * Phần đóng góp của một người vào các mục tiêu.
     *
     * @return array<string, float|int>
     */
    private function employeeTerms(int $employeeId, array $m): array
    {
        $terms = [
            // OFF lẻ và OFF dồn quá 3 ngày cùng một mức: OFF phải thành cụm 2–3 ngày.
            'off_split' => $m['off_split'] + $m['off_overlong'],
            'busy_off' => $m['busy_off'],
            'off_comfort' => $m['off_blocks'] + $m['off_overlong'] + $m['off_single'],
            'shift_mix' => $m['shift_mix'],
            'shift_history' => $m['shift_history'],
            'over_norm' => $m['over_norm'],
            'under_norm' => $m['under_norm'],
            'child_borrow' => $m['child_borrow'],
            'lobby_partial' => $m['lobby_partial'],
            'night_extra' => 0,
            'borrow_order' => $m['borrow_nonflex'],
            // Cờ (L) không cần thiết bị phạt nhẹ để thuật toán chỉ giao hỗ trợ Lobby khi khung giờ cần người.
            'preference' => $m['support_flags'] + $m['non_home'] * 3 + $m['non_primary'] * 2 + $m['soft_conflicts'] * 6 + $m['changes'] * 4,
            'heavy' => $m['heavy'],
            'heavy_sq' => $m['heavy'] ** 2,
            'lobby_sq' => 0,
            'night_rotation' => 0,
            'night_sq' => 0,
            'ratio' => 0.0,
            'ratio_sq' => 0.0,
            'ratio_n' => 0,
        ];

        // Chia đều lượt Lobby giữa các nhân viên (tính cả các tuần trước): không để một người đi Lobby suốt.
        // Trong kỳ chia đều là chính; lịch sử (lượt TB/tuần các tuần trước) để luân phiên giữa các tuần.
        if ($m['lobby'] > 0) {
            $history = $this->problem->lobbyHistory[$employeeId] ?? 0;
            $terms['lobby_sq'] = 4 * $m['lobby'] ** 2 + ($m['lobby'] + $history) ** 2 - $history ** 2;
        }

        // Luân phiên từng loại ca hỗ trợ (OH(L), M9(L), M6(L), A2(L), M6(K), A2(K)) có tính lịch sử các tuần trước:
        // (lượt lịch sử + lượt kỳ này)² − (lượt lịch sử)² → người ít lượt tích lũy được giao lượt tiếp theo.
        // Kid-Deline còn được chia đều trong kỳ (4 × lượt²) như Lobby.
        $typeHistory = $this->problem->supportHistory[$employeeId] ?? [];

        foreach ($m['support_types'] as $type => $count) {
            $past = $typeHistory[$type] ?? 0;
            $terms['lobby_sq'] += ($past + $count) ** 2 - $past ** 2;
        }

        $terms['lobby_sq'] += 4 * $m['kid'] ** 2;

        if ($this->expectedDays[$employeeId] > 0) {
            $ratio = $m['worked'] / $this->expectedDays[$employeeId];
            $terms['ratio'] = $ratio;
            $terms['ratio_sq'] = $ratio ** 2;
            $terms['ratio_n'] = 1;
        }

        if (isset($this->nightRotation[$employeeId])) {
            // Luân phiên ca đêm theo tuần: tuần này một nhóm làm đêm, tuần sau tới người khác
            // (ít người có tuần đêm trong kỳ, người đã làm đêm các tuần trước được nghỉ đêm), không trộn đêm với ca ngày trong tuần.
            $history = $this->problem->nightHistory[$employeeId] ?? 0;
            // + ca ngày trong tuần đã được chọn luân phiên Night (người được chọn đi Night cả tuần, không ở lại nhà hàng).
            $terms['night_rotation'] = ($m['night_weeks'] + $history) ** 2 - $history ** 2 + $m['night_mixed'] + $m['night_plan_day'] + $m['night_plan_gap'];
            $terms['night_extra'] = $m['night_plan_extra'];
        } elseif (isset($this->nightPool[$employeeId])) {
            $terms['night_sq'] = $m['night'] ** 2;
            $terms['night_rotation'] = $m['night_staff_day'] + $m['night_flex_planned'];
        }

        return $terms;
    }

    /**
     * Giá trị từng mục tiêu (chưa nhân trọng số).
     *
     * Phần theo từng người được cộng dồn tăng dần: chỉ người có ô thay đổi (dirty) mới được tính lại
     * và cập nhật vào tổng – các bước thử chỉ chạm 1–3 người trong cả bộ phận.
     *
     * @return array<string, int>
     */
    public function objectiveValues(): array
    {
        foreach ($this->dirty as $employeeId => $_) {
            $terms = $this->employeeTerms($employeeId, $this->metrics[$employeeId] ?? $this->metricsFor($employeeId));
            $old = $this->terms[$employeeId] ?? null;

            foreach ($terms as $k => $value) {
                $this->totals[$k] = ($this->totals[$k] ?? 0) + $value - ($old[$k] ?? 0);
            }

            $this->terms[$employeeId] = $terms;
        }

        $this->dirty = [];
        $t = $this->totals;
        $n = $t['ratio_n'] ?? 0;
        $variance = $n > 0 ? max(0.0, ($t['ratio_sq'] ?? 0) - ($t['ratio'] ?? 0) ** 2 / $n) : 0.0;
        $heavy = $t['heavy'] ?? 0;
        $heavySquares = $t['heavy_sq'] ?? 0;
        $nightSquares = $t['night_sq'] ?? 0;
        $lobbySquares = $t['lobby_sq'] ?? 0;
        $offSplit = $t['off_split'] ?? 0;
        $overNorm = $t['over_norm'] ?? 0;
        $busyOff = $t['busy_off'] ?? 0;
        $nightRotation = (int) round($t['night_rotation'] ?? 0);
        $underNorm = $t['under_norm'] ?? 0;
        $childBorrow = $t['child_borrow'] ?? 0;
        $shiftMix = $t['shift_mix'] ?? 0;
        $shiftHistory = $t['shift_history'] ?? 0;
        $offComfort = $t['off_comfort'] ?? 0;
        $preference = $t['preference'] ?? 0;

        // Chia đều người đi Lobby giữa các outlet: tổng bình phương số người mỗi outlet đưa sang mỗi khung Lobby.
        $supportSquares = 0;

        foreach ($this->supportFrom as $windows) {
            foreach ($windows as $sources) {
                foreach ($sources as $count) {
                    $supportSquares += $count ** 2;
                }
            }
        }

        // Lobby chia đều theo loại ca trong cùng khung (vd sáng: 1 OH(L) người Nha Trang + 1 M9 người Cam Ranh):
        // số người vượt phần chia đều của mỗi loại ca được phép tính trong khung.
        $lobbyMix = 0;

        foreach ($this->supportShift as $i => $windows) {
            foreach ($windows as $k => $shiftCounts) {
                $types = count($this->coverage[$i][$k][5] ?? []);

                if ($types < 2) {
                    continue;
                }

                $fair = (int) ceil(array_sum($shiftCounts) / $types);

                foreach ($shiftCounts as $count) {
                    $lobbyMix += max(0, $count - $fair);
                }
            }
        }

        // Người dôi ra trên định biên mục tiêu (để đủ ngày làm) được chia đều giữa các ca: $this->overTarget.
        $fairness = (int) round($this->overTarget * 50 + $variance * 10000 + $heavySquares * 20 + $nightSquares * 20 + $lobbySquares * 10 + $supportSquares * 60);

        return [
            'hard' => $this->fixedHardViolations, 'source_shortage' => count($this->sourceShortages()), 'min_shortage' => $this->minShort,
            'off_split' => $offSplit, 'over_norm' => $overNorm, 'target_shortage' => $this->targetShort, 'busy_day_off' => $busyOff, 'night_rotation' => $nightRotation, 'lobby_mix' => $lobbyMix, 'lobby_partial' => $t['lobby_partial'] ?? 0, 'night_extra' => $t['night_extra'] ?? 0, 'lobby_round' => $this->lobbyRound(), 'borrow_excess' => $this->borrowExcess, 'borrow_order' => $t['borrow_order'] ?? 0, 'under_norm' => $underNorm, 'child_borrow' => $childBorrow,
            'heavy_transitions' => $heavy, 'shift_mix' => $shiftMix, 'shift_history' => $shiftHistory, 'manager_balance' => $this->managerImbalance, 'fairness' => $fairness, 'off_comfort' => $offComfort, 'preference' => $preference,
        ];
    }

    /**
     * Thứ tự các mục tiêu từ quan trọng nhất (theo trọng số đang áp dụng) – dùng cho báo cáo.
     *
     * @return string[]
     */
    public static function objectiveLabels(Rules $rules): array
    {
        static $cache = null;
        $cache ??= new \WeakMap();

        if (isset($cache[$rules])) {
            return $cache[$rules];
        }

        $weights = $rules->weights();
        $labels = array_keys($weights);
        $position = array_flip($labels);
        usort($labels, fn ($a, $b) => [$weights[$b], $position[$a]] <=> [$weights[$a], $position[$b]]);

        return $cache[$rules] = $labels;
    }

    /**
     * Ô do thuật toán xếp ngoài outlet chính trong khi một ca mà nhân viên đó chuyên trách
     * tại outlet chính cùng ngày đang thiếu tối thiểu (vi phạm "không điều chuyển gây thiếu nơi nguồn").
     *
     * @return array<int, array{employee_id: int, date: string, outlet_id: int, home_outlet_id: ?int, home_shift_id: int}>
     */
    public function sourceShortages(): array
    {
        // Chỉ ô đang thiếu tối thiểu mới có thể là nơi nguồn bị thiếu (danh sách cập nhật tăng dần trong adjustCount).
        if (! $this->minShortSlots) {
            return [];
        }

        $result = [];

        foreach ($this->minShortSlots as $i => $slots) {
            foreach ($this->crossCells[$i] ?? [] as $employeeId => $outletId) {
                $employee = $this->problem->employees[$employeeId];

                foreach ($this->protectedSlots($employeeId) as [$homeId, $shiftId]) {
                    if ($homeId === $outletId || ! isset($slots["{$homeId}|{$shiftId}"])) {
                        continue;
                    }

                    $requirement = $this->requirements[$i]["{$homeId}|{$shiftId}"];
                    $count = $this->count($i, $requirement->outletId, $requirement->shiftId);
                    $male = $this->maleCount($i, $requirement->outletId, $requirement->shiftId);

                    $female = $this->femaleCount($i, $requirement->outletId, $requirement->shiftId);

                    if ($count < $requirement->min || ($employee->countsAsMale() && $male < $requirement->minMale)
                        || ($employee->isFemaleStaff && $female < $requirement->minFemale) || $this->positionShort($employeeId, $i, $requirement, 0)) {
                        $result[] = ['employee_id' => $employeeId, 'date' => $this->dates[$i], 'outlet_id' => $outletId, 'home_outlet_id' => $homeId, 'home_shift_id' => $requirement->shiftId];

                        break;
                    }
                }
            }
        }

        return $result;
    }

    /** @var array<int, array<int, array{0: int, 1: int}>> */
    private array $protectedSlots = [];

    /**
     * Ô "nơi nguồn" không được bỏ trống dưới tối thiểu khi điều chuyển người này: ca chuyên trách tại outlet chính;
     * Night đa năng: thêm các ca chuyên trách ở outlet khác (vd Room Service) – không rút người đa năng khỏi Night / Room Service đang thiếu.
     *
     * @return array<int, array{0: int, 1: int}> [outletId, shiftId]
     */
    private function protectedSlots(int $employeeId): array
    {
        if (isset($this->protectedSlots[$employeeId])) {
            return $this->protectedSlots[$employeeId];
        }

        $employee = $this->problem->employees[$employeeId];
        $slots = array_map(fn ($shiftId) => [(int) $employee->homeOutletId, $shiftId], $employee->homeOutletId === null ? [] : $this->homePrimaryShifts($employeeId));

        if ($employee->isNightFlex()) {
            foreach ($employee->skills as $key => $level) {
                [$o, $s] = array_map('intval', explode(':', $key));

                if ($level !== EmployeeSpec::PRIMARY || $o === $employee->homeOutletId) {
                    continue;
                }

                foreach ($s ? [$s] : array_keys($this->problem->shifts) as $shiftId) {
                    if ($employee->skillLevel($o, $shiftId) === EmployeeSpec::PRIMARY) {
                        $slots[] = [$o, $shiftId];
                    }
                }
            }
        }

        return $this->protectedSlots[$employeeId] = $slots;
    }

    /** @var array<int, int[]> */
    private array $homePrimaryShifts = [];

    /**
     * Các ca nhân viên chuyên trách (primary) tại outlet chính.
     *
     * @return int[]
     */
    private function homePrimaryShifts(int $employeeId): array
    {
        if (isset($this->homePrimaryShifts[$employeeId])) {
            return $this->homePrimaryShifts[$employeeId];
        }

        $employee = $this->problem->employees[$employeeId];
        $shifts = [];

        foreach ($this->problem->shifts as $shiftId => $shift) {
            if ($employee->homeOutletId !== null && $employee->skillLevel($employee->homeOutletId, $shiftId) === EmployeeSpec::PRIMARY) {
                $shifts[] = $shiftId;
            }
        }

        return $this->homePrimaryShifts[$employeeId] = $shifts;
    }

    /**
     * Ngày i là ngày OFF đã biết: trong phạm vi và không có ca, hoặc ngoài phạm vi nhưng có ô OFF / nghỉ cố định.
     * Ngày ngoài phạm vi chưa có lịch coi như đi làm (không ghép OFF với ngày chưa biết).
     */
    public function isOffDay(int $employeeId, int $i): bool
    {
        if (! isset($this->dates[$i])) {
            return false;
        }

        if ($fixed = $this->fixed[$employeeId][$i] ?? null) {
            return ! $fixed->isWorking();
        }

        return $this->inScope[$i] && ! isset($this->plan[$employeeId][$i]);
    }

    private function isExcusedOff(int $employeeId, int $i): bool
    {
        $date = $this->dates[$i];

        if (isset($this->problem->hardUnavailable[$employeeId][$date])) {
            return ! isset($this->problem->regularOff[$employeeId][$date]);
        }

        $fixed = $this->fixed[$employeeId][$i] ?? null;

        return $fixed !== null && $fixed->source === 'locked';
    }

    /**
     * So sánh hai vector mục tiêu theo thứ tự ưu tiên. Âm nếu $a tốt hơn $b.
     */
    public static function compare(array $a, array $b): int
    {
        foreach ($a as $level => $value) {
            if ($value !== $b[$level]) {
                return $value <=> $b[$level];
            }
        }

        return 0;
    }

    /**
     * Chỉ số của một nhân viên trên toàn kỳ (được lưu đệm, xóa khi ô của người đó thay đổi).
     * Ca từng ngày được đọc một lần vào mảng rồi mọi chỉ số tính trên mảng đó – đây là phần chạy nhiều nhất của bộ giải.
     *
     * @return array{worked: int, hours: float, heavy: int, night: int, morning: int, afternoon: int, non_home: int, non_primary: int, soft_conflicts: int, changes: int, outlets: array<int, int>}
     */
    public function metricsFor(int $employeeId): array
    {
        if (isset($this->metrics[$employeeId])) {
            return $this->metrics[$employeeId];
        }

        $employee = $this->problem->employees[$employeeId];
        $shifts = $this->problem->shifts;
        $planned = isset($this->nightRotation[$employeeId]) && $this->problem->nightPlan;
        $flexPlanned = $employee->isNightFlex() && $this->problem->nightPlan;
        $m = ['worked' => 0, 'hours' => 0.0, 'heavy' => 0, 'night' => 0, 'morning' => 0, 'afternoon' => 0, 'non_home' => 0, 'non_primary' => 0, 'soft_conflicts' => 0, 'changes' => 0, 'off_blocks' => 0, 'off_overlong' => 0, 'off_single' => 0, 'off_split' => 0, 'busy_off' => 0, 'under_norm' => 0, 'child_borrow' => 0, 'night_weeks' => 0, 'night_mixed' => 0, 'night_staff_day' => 0, 'night_plan_day' => 0, 'night_flex_planned' => 0, 'night_plan_gap' => 0, 'night_plan_extra' => 0, 'borrow_nonflex' => 0, 'lobby_pref' => [], 'over_norm' => 0, 'lobby' => 0, 'lobby_partial' => 0, 'kid' => 0, 'support_flags' => 0, 'shift_mix' => 0, 'shift_history' => 0, 'outlets' => [], 'support_types' => []];

        // Ca từng ngày trên cả dòng thời gian (kể cả ngày ngữ cảnh ngoài phạm vi).
        $work = [];
        $fixedRow = $this->fixed[$employeeId] ?? [];
        $planRow = $this->plan[$employeeId] ?? [];
        $last = count($this->dates) - 1;

        for ($i = 0; $i <= $last; $i++) {
            if (isset($fixedRow[$i])) {
                $fixed = $fixedRow[$i];
                $work[$i] = $fixed->isWorking() ? [$fixed->outletId, $fixed->shiftId, $fixed->overtimeHours, $fixed->support] : null;
            } elseif (isset($planRow[$i])) {
                [$o, $s, $f] = self::parseSlot($planRow[$i]);
                $work[$i] = [$o, $s, 0.0, $f];
            } else {
                $work[$i] = null;
            }
        }

        $scope = $this->scopeIndexes();
        $baselineRow = $this->problem->baseline[$employeeId] ?? [];
        $softRow = $this->problem->softUnavailable[$employeeId] ?? [];
        $byWeek = [];
        $plannedWeeks = [];
        $home = $employee->homeOutletId;
        $parents = $this->problem->outletParents;
        $station = $this->problem->stationOutletId;
        $levels = &$this->skillLevels[$employeeId];

        foreach ($scope as $i) {
            $date = $this->dates[$i];
            $current = $work[$i];

            if (! isset($fixedRow[$i]) && ($baselineRow[$date] ?? null) !== ($planRow[$i] ?? null)) {
                $m['changes']++;
            }

            if (! $current) {
                continue;
            }

            [$o, $s, $ot, $f] = $current;
            $shift = $shifts[$s];
            $category = $shift->category();
            $m['worked']++;

            // Mượn người không phải đa năng Night (ưu tiên 2) – xếp sau nhân viên đa năng.
            if ($o !== null && ! $employee->isNightFlex() && $this->borrowPolicy()->isBorrowed($employee, (int) $o)) {
                $m['borrow_nonflex']++;
            }

            // Tuần được chọn luân phiên Night: đếm số đêm và số cụm đêm (2 đêm liền nhau, tối đa 3 khi cần đủ 3 người / ngày).
            if ($planned && $this->problem->nightPlanned($employeeId, $date) === true) {
                $week = $this->weekOf[$i];
                $plannedWeeks[$week] ??= ['nights' => 0, 'blocks' => 0];

                if ($category === 'night') {
                    $plannedWeeks[$week]['nights']++;
                    $prev = $i > 0 ? $work[$i - 1] : null;

                    if (! $prev || $shifts[$prev[1]]->category() !== 'night') {
                        $plannedWeeks[$week]['blocks']++;
                    }
                }
            }

            // Ngày đã có người luân phiên Night được duyệt: Night đa năng nhường ca đêm cho họ (đi Room Service / hỗ trợ nhà hàng).
            if ($category === 'night' && $flexPlanned && ! empty($this->problem->nightPlan[$this->dates[$i]])) {
                $m['night_flex_planned']++;
            }

            // Có mặt ở Lobby: ca làm trọn ở trạm, hoặc ca có khung (L) và đang bật (L) (như supportInterval()).
            // Lượt hỗ trợ theo loại ca: có mặt ở Lobby → OH(L), M9(L), M6(L), A2(L); đứng khu trực thuộc → M6(K), A2(K).
            if (($o !== null && $o === $station) || ($f && $shift->lobbyWindow)) {
                $m['lobby']++;
                $m['lobby_partial'] += empty($this->coverageGroups[$i]) ? 0 : $this->lobbyPartial($i, $o, $s, (bool) $f);

                // Ca Lobby ưu tiên của người này (OH(L) Nha Trang / M9(L) Cam Ranh) – đếm theo tuần để chia lượt.
                if (($this->problem->lobbyPreferred[$employeeId] ?? null) === $s) {
                    $m['lobby_pref'][$this->weekSeq[$i]] = ($m['lobby_pref'][$this->weekSeq[$i]] ?? 0) + 1;
                }
                $type = Problem::supportTypeKey($shift->code, 'lobby');
                $m['support_types'][$type] = ($m['support_types'][$type] ?? 0) + 1;
            } elseif ($o !== null && isset($parents[$o])) {
                $m['kid']++;
                $type = Problem::supportTypeKey($shift->code, 'kid');
                $m['support_types'][$type] = ($m['support_types'][$type] ?? 0) + 1;
            }

            if ($f && ! isset($fixedRow[$i])) {
                $m['support_flags']++;
            }

            $m['hours'] += $shift->hours + $ot;
            $m[$category]++;
            $m['outlets'][$o] = ($m['outlets'][$o] ?? 0) + 1;

            if ($o !== null && $o !== $home && ($parents[$o] ?? null) !== $home) {
                $m['non_home']++;

                if (isset($parents[$o])) {
                    $m['child_borrow']++;
                }
            }

            if ($o !== null && ($levels[$o][$s] ??= $employee->skillLevel($o, $s) ?? '') !== EmployeeSpec::PRIMARY) {
                $m['non_primary']++;
            }

            if (isset($softRow[$date])) {
                $m['soft_conflicts']++;
            }

            if ($i < $last && $work[$i + 1] && $this->heavyBetween($current, $work[$i + 1])) {
                $m['heavy']++;
            }

            $byWeek[$this->weekOf[$i]][$category] = ($byWeek[$this->weekOf[$i]][$category] ?? 0) + 1;
        }

        // Cặp (ngày trước phạm vi, ngày đầu phạm vi).
        $first = $scope[0] ?? null;

        if ($first !== null && $first > 0 && $work[$first - 1] && $work[$first] && $this->heavyBetween($work[$first - 1], $work[$first])) {
            $m['heavy']++;
        }

        // Cụm OFF: mỗi chuỗi ngày không làm bắt đầu trong phạm vi là một cụm; ít cụm hơn = OFF liền nhau hơn.
        // Cụm dài quá 3 ngày bị phạt thêm từng ngày vượt (muốn nghỉ liền 2–3 ngày, không dồn quá dài).
        foreach ($scope as $i) {
            if ($work[$i] || ($i > 0 && ! $work[$i - 1])) {
                continue;
            }

            $length = 0;

            for ($k = $i; $k <= $last && ! $work[$k]; $k++) {
                $length++;
            }

            $m['off_blocks']++;
            $m['off_overlong'] += max(0, $length - 3);

            // OFF lẻ 1 ngày kẹp giữa hai ngày làm: phạt thêm (ưu tiên 2+2 hơn 3+1).
            if ($length === 1 && $i < $last && $work[$i + 1]) {
                $m['off_single']++;
            }
        }

        // Tuần có ca đêm, và số ca ngày lẫn trong tuần có ca đêm.
        // Night đa năng (Room Service / hỗ trợ nhà hàng) không bị đẩy về ca đêm như nhân viên chỉ làm Night.
        $staffDay = isset($this->nightPool[$employeeId], $this->nightOutlets[$employee->homeOutletId]) && ! $employee->fixed && ! $employee->isNightFlex()
            && $employee->nightRole !== EmployeeSpec::NIGHT_ROTATION;

        // Tuần luân phiên: mục tiêu 2 đêm (3 khi cần), một cụm liền nhau; ngày còn lại làm ca chính ở outlet (không tính "trộn đêm / ngày").
        foreach ($plannedWeeks as $week) {
            $m['night_plan_gap'] += max(0, 2 - $week['nights']) + max(0, $week['nights'] - 3) + max(0, $week['blocks'] - 1);
            $m['night_plan_extra'] += $week['nights'] === 3 ? 1 : 0;
        }

        foreach ($byWeek as $weekKey => $counts) {
            $night = $counts['night'] ?? 0;
            $day = ($counts['morning'] ?? 0) + ($counts['afternoon'] ?? 0);

            if ($staffDay) {
                $m['night_staff_day'] += $day;
            }

            if ($night > 0) {
                $m['night_weeks']++;
                $m['night_mixed'] += isset($plannedWeeks[$weekKey]) ? 0 : min($night, $day);
            }
        }

        // OFF 2 ngày liền nhau (trong cùng tuần – không có tuần OFF thứ Hai + Chủ nhật) & hạn chế OFF ngày đông khách.
        // Chỉ xét ngày OFF do thuật toán chọn:
        // ngày nhân viên tự xin OFF / nghỉ phép đã duyệt và ô quản lý đã khóa được miễn (sinh nhật thì không – OFF còn lại nên ghép cạnh).
        $isOff = fn (int $k, int $i) => $k >= 0 && $k <= $last && $this->weekOf[$k] === $this->weekOf[$i]
            && ($work[$k] === null && (isset($fixedRow[$k]) || $this->inScope[$k]));

        foreach ($scope as $i) {
            if ($work[$i] || $this->isExcusedOff($employeeId, $i)) {
                continue;
            }

            if ($this->isBusyDay($i)) {
                $m['busy_off']++;
            }

            if ($employee->daysOffPerWeek >= 2 && ! $isOff($i - 1, $i) && ! $isOff($i + 1, $i)) {
                $m['off_split']++;
            }
        }

        // Xen kẽ ca: người không có ca cứng, mỗi tuần làm >= 2 ca ngày thì phải có cả ca sáng lẫn ca chiều,
        // (phạt nặng), và số ca sáng / chiều không lệch quá ngưỡng (phạt nhẹ). Ca đêm không tính.
        if (! $employee->fixed) {
            foreach ($byWeek as $counts) {
                $morning = $counts['morning'] ?? 0;
                $afternoon = $counts['afternoon'] ?? 0;

                if ($morning + $afternoon >= 2) {
                    // Toàn một loại ca: phạt nặng; lệch sáng/chiều quá ngưỡng (mặc định 1 → 5 ngày chia 3/2): phạt từng ca lệch.
                    $m['shift_mix'] += max(0, abs($morning - $afternoon) - $this->problem->rules->shiftMixTolerance)
                        + (($morning === 0 || $afternoon === 0) ? 3 : 0);
                }
            }
        }

        // Cân bằng ca sáng / chiều qua nhiều tuần (lịch sử historyWeeks tuần + kỳ này), cho người không có ca cứng, trừ cấp quản lý
        // (quản lý có quy tắc cân bằng riêng). Lệch tới 2 ca là chấp nhận được.
        if (! $employee->fixed && ! $employee->isManager) {
            $past = $this->problem->shiftHistory[$employeeId] ?? ['morning' => 0, 'afternoon' => 0];
            $morning = $past['morning'] + $m['morning'];
            $afternoon = $past['afternoon'] + $m['afternoon'];
            $m['shift_history'] = $m['morning'] + $m['afternoon'] > 0 ? max(0, abs($morning - $afternoon) - 2) : 0;
        }

        // Ngày làm từng tuần (kể cả ô cố định ngoài phạm vi).
        $maxWork = $employee->maxWorkDaysPerWeek();
        $hardRow = $this->problem->hardUnavailable[$employeeId] ?? [];
        $expected = 0;
        $worked = 0;

        foreach ($this->weekDays as $days) {
            $inWeek = 0;
            $scopeDays = 0;
            $available = 0;

            foreach ($days as $k) {
                if ($work[$k]) {
                    $inWeek++;
                }

                if ($this->inScope[$k]) {
                    $scopeDays++;
                }

                if (! isset($hardRow[$this->dates[$k]]) && ! (isset($fixedRow[$k]) && ! $fixedRow[$k]->isWorking())) {
                    $available++;
                }
            }

            // Ngày làm vượt định mức tuần (chỉ có thể xảy ra ở chế độ OFF theo cặp 2 tuần).
            if ($scopeDays > 0) {
                $m['over_norm'] += max(0, $inWeek - $maxWork);
            }

            // Thiếu ngày làm so với định mức (tuần trọn trong kỳ): không để ai mất ngày làm khi vẫn còn chỗ
            // (vd người ca cứng M6 bị nhường chỗ cho người khác xen kẽ ca). Cộng cả kỳ để hợp chế độ OFF theo cặp 2 tuần.
            if ($scopeDays === 7) {
                $expected += min($maxWork, $available);
                $worked += $inWeek;
            }
        }

        $m['under_norm'] = max(0, $expected - $worked);

        return $this->metrics[$employeeId] = $m;
    }

    /**
     * Hai ca liền nhau (ngày i và i + 1) có là chuyển ca nặng không: theo quy tắc chuyển ca, hoặc nghỉ dưới mức mềm.
     *
     * @param array{0: ?int, 1: int, 2: float, 3: bool} $a
     * @param array{0: ?int, 1: int, 2: float, 3: bool} $b
     */
    private function heavyBetween(array $a, array $b): bool
    {
        $rule = $this->problem->rules->transitions["{$a[1]}:{$b[1]}"] ?? null;

        if ($rule !== null && $rule !== 'forbidden') {
            return $rule === 'heavy';
        }

        $rest = 1440 + $this->problem->shifts[$b[1]]->start() - $this->problem->shifts[$a[1]]->end() - (int) round($a[2] * 60);

        return $rest < $this->problem->rules->softMinRestHours * 60;
    }

    public function restMinutes(int $employeeId, int $i): ?int
    {
        $a = $this->shiftAt($employeeId, $i);
        $b = isset($this->dates[$i + 1]) ? $this->shiftAt($employeeId, $i + 1) : null;

        if (! $a || ! $b) {
            return null;
        }

        $aEnd = $i * 1440 + $this->problem->shifts[$a[1]]->end() + (int) round($a[2] * 60);

        return ($i + 1) * 1440 + $this->problem->shifts[$b[1]]->start() - $aEnd;
    }

    public function isHeavyPair(int $employeeId, int $i, int $j): bool
    {
        $a = $this->shiftAt($employeeId, $i);
        $b = isset($this->dates[$j]) ? $this->shiftAt($employeeId, $j) : null;

        if (! $a || ! $b) {
            return false;
        }

        $rule = $this->problem->rules->transitions["{$a[1]}:{$b[1]}"] ?? null;

        if ($rule === 'heavy') {
            return true;
        }

        if ($rule === 'allowed') {
            return false;
        }

        return $this->restMinutes($employeeId, $i) < $this->problem->rules->softMinRestHours * 60;
    }

    public function canEverWork(EmployeeSpec $employee, int $shiftId): bool
    {
        foreach (array_keys($employee->skills) as $key) {
            [$o, $s] = array_map('intval', explode(':', $key));

            if (($s === 0 || $s === $shiftId) && $employee->skillLevel($o, $shiftId) !== null) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ Nội bộ

    private function transitionViolation(ShiftSpec $from, ShiftSpec $to, int $restMinutes): ?string
    {
        $rule = $this->problem->rules->transitions["{$from->id}:{$to->id}"] ?? null;

        if ($rule === 'forbidden') {
            return "Không được xếp {$to->code} ngay sau {$from->code} (quy tắc chuyển ca)";
        }

        if ($restMinutes < 0) {
            return "Trùng giờ giữa {$from->code} và {$to->code}";
        }

        if ($restMinutes < $this->problem->rules->hardMinRestHours * 60) {
            return sprintf('Chỉ nghỉ %sh giữa %s và %s (tối thiểu %sh)', round($restMinutes / 60, 1), $from->code, $to->code, $this->problem->rules->hardMinRestHours);
        }

        return null;
    }

    /**
     * Lượt Lobby ưu tiên chưa chia đều trong tuần: số ca thứ hai trở đi (OH(L) / M9(L)) đang giao trong khi còn nữ waiter đủ điều kiện,
     * làm được tuần đó mà chưa có ca nào – min(số người chưa có ca, số ca thừa), xét chung mọi nhóm (Nha Trang OH(L), Cam Ranh M9(L)):
     * một người Cam Ranh làm M9 lần hai trong khi người Nha Trang chưa có OH(L) nào cũng bị tính.
     */
    public function lobbyRound(): int
    {
        if (! $this->problem->lobbyPreferred) {
            return 0;
        }

        $groups = [];

        foreach ($this->problem->lobbyPreferred as $employeeId => $shiftId) {
            $counts = $this->metricsFor($employeeId)['lobby_pref'];

            foreach ($this->lobbyAvailableWeeks($employeeId) as $week) {
                $k = $counts[$week] ?? 0;
                $group = &$groups[$week];
                $group ??= ['zeros' => 0, 'extras' => 0];

                if ($k === 0) {
                    $group['zeros']++;
                } else {
                    $group['extras'] += $k - 1;
                }

                unset($group);
            }
        }

        $penalty = 0;

        foreach ($groups as $group) {
            $penalty += min($group['zeros'], $group['extras']);
        }

        return $penalty;
    }

    /**
     * Mã ca theo cách ghi trên bảng: thêm "(L)" khi hỗ trợ Lobby hoặc làm trọn ở trạm Lobby (M9 → "M9(L)"), "(K)" khi đứng khu trực thuộc.
     */
    public function shiftKey(?int $outletId, int $shiftId, bool $support): string
    {
        $code = strtoupper($this->problem->shifts[$shiftId]->code);

        return match (true) {
            $support || ($outletId !== null && $outletId === $this->problem->stationOutletId) => $code . '(L)',
            $outletId !== null && isset($this->problem->outletParents[$outletId]) => $code . '(K)',
            default => $code,
        };
    }

    /** Số ca Lobby ưu tiên người này đã có trong tuần chứa ngày i. */
    public function lobbyPreferredInWeek(int $employeeId, int $i): int
    {
        return isset($this->problem->lobbyPreferred[$employeeId]) ? ($this->metricsFor($employeeId)['lobby_pref'][$this->weekSeq[$i]] ?? 0) : 0;
    }

    /** @var array<int, int[]> */
    private array $lobbyWeeks = [];

    /**
     * Tuần nhân viên làm được ca Lobby ưu tiên: có ít nhất một ngày trong phạm vi không nghỉ đã duyệt, không bị ô cố định khác ca.
     * Tuần nghỉ cả tuần không bị tính là "chưa nhận ca" (không bất lợi khi chia công bằng).
     *
     * @return int[]
     */
    public function lobbyAvailableWeeks(int $employeeId): array
    {
        if (isset($this->lobbyWeeks[$employeeId])) {
            return $this->lobbyWeeks[$employeeId];
        }

        $shiftId = $this->problem->lobbyPreferred[$employeeId] ?? null;
        $weeks = [];

        foreach ($this->scopeIndexes() as $i) {
            $fixed = $this->fixed[$employeeId][$i] ?? null;

            if ($shiftId === null || isset($this->problem->hardUnavailable[$employeeId][$this->dates[$i]]) || ($fixed && (! $fixed->isWorking() || $fixed->shiftId !== $shiftId))) {
                continue;
            }

            $weeks[$this->weekSeq[$i]] = $this->weekSeq[$i];
        }

        return $this->lobbyWeeks[$employeeId] = array_values($weeks);
    }

    /** @var array<string, array{0: int, 1: int, 2: int}> "i|o|s" => [tổng, nam waiter, nữ waiter] người mượn từ outlet khác */
    private array $borrowCount = [];

    /** Tổng số người mượn vượt phần thiếu tối thiểu (mượn để đạt mục tiêu / cân bằng – không được phép). */
    private int $borrowExcess = 0;

    /** @var array<string, array{0: array{0: int, 1: int, 2: int}, 1: array{0: int, 1: int, 2: int}}> "i|o" => [đang làm, trong đó người mượn] */
    private array $borrowDay = [];

    /** @var array<string, array{0: int, 1: int, 2: int}> "i|o" => tối thiểu [tổng, nam, nữ] cộng các ca có định biên */
    private array $borrowDayMin = [];

    private function dayMinimum(int $i, int $outletId): array
    {
        $sum = [0, 0, 0];

        foreach ($this->requirements[$i] ?? [] as $requirement) {
            if ($requirement->outletId === $outletId) {
                $sum = [$sum[0] + $requirement->min, $sum[1] + $requirement->minMale, $sum[2] + $requirement->minFemale];
            }
        }

        return $sum;
    }

    private ?BorrowPolicy $borrowPolicy = null;

    public function borrowPolicy(): BorrowPolicy
    {
        return $this->borrowPolicy ??= new BorrowPolicy($this->problem);
    }

    /** Người mượn đang làm ô này. */
    public function borrowedAt(int $i, int $outletId, int $shiftId): array
    {
        return $this->borrowCount["{$i}|{$outletId}|{$shiftId}"] ?? [0, 0, 0];
    }

    /** @var array<int, array<int, int>> [dayIndex][outlet Night] => số nhân viên Night cứng đang làm ca đêm */
    private array $coreNeed = [];

    /**
     * Ngày Night không có nhân viên Night cứng nào: [[dayIndex, outletId], ...].
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function coreShortDays(): array
    {
        $result = [];

        foreach ($this->coreNeed as $i => $outlets) {
            foreach ($outlets as $outletId => $count) {
                if ($count < 1) {
                    $result[] = [$i, $outletId];
                }
            }
        }

        return $result;
    }

    /** @var array<int, int> [dayIndex] => số người luân phiên đang làm ca đêm */
    private array $rotationNights = [];

    private function adjustCount(int $i, int $outletId, int $shiftId, int $employeeId, int $delta, bool $support = false): void
    {
        if (($this->problem->employees[$employeeId]?->nightRole ?? null) === EmployeeSpec::NIGHT_ROTATION && $this->problem->shifts[$shiftId]->category() === 'night') {
            $this->rotationNights[$i] = ($this->rotationNights[$i] ?? 0) + $delta;
        }

        if (isset($this->coreNeed[$i][$outletId]) && ($this->problem->employees[$employeeId]?->isNightCore() ?? false)
            && $this->problem->shifts[$shiftId]->category() === 'night') {
            $before = $this->coreNeed[$i][$outletId];
            $this->coreNeed[$i][$outletId] = $before + $delta;
            $this->minShort += max(0, 1 - $before - $delta) - max(0, 1 - $before);
        }

        if (! empty($this->coverage[$i])) {
            $source = $this->supportSource($employeeId, $outletId);

            $femaleStaff = $this->problem->employees[$employeeId]?->isFemaleStaff ?? false;

            foreach ($this->supportWindows($i, $outletId, $shiftId, $support) as $k) {
                // Khung chỉ đếm nữ waiter (Lobby): người khác vẫn rời nhà hàng nhưng không được tính phủ.
                if ($this->coverage[$i][$k][6] && ! $femaleStaff) {
                    continue;
                }

                if ($source !== null) {
                    $this->supportFrom[$i][$k][$source] = ($this->supportFrom[$i][$k][$source] ?? 0) + $delta;
                }

                $this->supportShift[$i][$k][$shiftId] = ($this->supportShift[$i][$k][$shiftId] ?? 0) + $delta;

                [, , $min, $target] = $this->coverage[$i][$k];
                $before = $this->coverageCount[$i][$k];
                $after = $before + $delta;
                $this->coverageCount[$i][$k] = $after;
                $this->minShort += max(0, $min - $after) - max(0, $min - $before);
                $this->targetShort += max(0, $target - $after) - max(0, $target - $before);
            }
        }

        $key = "{$i}|{$outletId}|{$shiftId}";

        $requirement = $this->requirements[$i]["{$outletId}|{$shiftId}"] ?? null;

        // Người đi hỗ trợ Lobby rời nhà hàng: không tính vào định biên outlet × ca (đã đếm ở supportFrom) –
        // trừ khi dòng định biên có khung đếm và giờ ở Lobby không chạm khung đó (vd M6(L) 09–12h, khung đếm 06–09h).
        if ($support && $outletId !== $this->problem->stationOutletId && ! $this->supportCountsAtOutlet($requirement, $shiftId)) {
            return;
        }
        $isMale = $this->problem->employees[$employeeId]?->countsAsMale() ?? false;
        $isFemaleStaff = $this->problem->employees[$employeeId]?->isFemaleStaff ?? false;

        // Quản lý ca sáng / chiều theo outlet × ngày (cân bằng M6 / A2 của cấp quản lý).
        if (($this->problem->employees[$employeeId] ?? null)?->isManager
            && in_array($category = $this->problem->shifts[$shiftId]->category(), ['morning', 'afternoon'], true)) {
            $counts = $this->managerShifts[$i][$outletId] ?? ['morning' => 0, 'afternoon' => 0];
            $before = self::managerPenalty($counts['morning'], $counts['afternoon']);
            $counts[$category] += $delta;
            $this->managerShifts[$i][$outletId] = $counts;
            $this->managerImbalance += self::managerPenalty($counts['morning'], $counts['afternoon']) - $before;
        }

        $before = $this->count[$key] ?? 0;
        $beforeMale = $this->maleCount[$key] ?? 0;
        $beforeFemale = $this->femaleCount[$key] ?? 0;
        $this->count[$key] = $before + $delta;
        $this->maleCount[$key] = $beforeMale + ($isMale ? $delta : 0);
        $this->femaleCount[$key] = $beforeFemale + ($isFemaleStaff ? $delta : 0);

        $groups = $this->problem->groupsOfPosition($this->problem->employees[$employeeId]?->positionId);
        $beforeGroups = [];

        foreach ($groups as $group) {
            $beforeGroups[$group] = $this->positionCount[$key][$group] ?? 0;
            $this->positionCount[$key][$group] = $beforeGroups[$group] + $delta;
        }

        if (! $requirement) {
            return;
        }

        // Mượn người (BorrowPolicy): theo dõi người mượn của ô và của outlet trong ngày, phần mượn vượt nhu cầu tối thiểu.
        $employeeSpec = $this->problem->employees[$employeeId] ?? null;
        $dayKey = "{$i}|{$outletId}";
        $dayMin = $this->borrowDayMin[$dayKey] ??= $this->dayMinimum($i, $outletId);
        $dayBefore = $this->borrowDay[$dayKey] ?? [[0, 0, 0], [0, 0, 0]];
        $dayAfter = $dayBefore;
        $dayAfter[0] = [$dayBefore[0][0] + $delta, $dayBefore[0][1] + ($isMale ? $delta : 0), $dayBefore[0][2] + ($isFemaleStaff ? $delta : 0)];

        if ($employeeSpec && $this->borrowPolicy()->isBorrowed($employeeSpec, $outletId)) {
            $borrowed = $this->borrowCount[$key] ?? [0, 0, 0];
            $this->borrowCount[$key] = [$borrowed[0] + $delta, $borrowed[1] + ($isMale ? $delta : 0), $borrowed[2] + ($isFemaleStaff ? $delta : 0)];
            $dayAfter[1] = [$dayBefore[1][0] + $delta, $dayBefore[1][1] + ($isMale ? $delta : 0), $dayBefore[1][2] + ($isFemaleStaff ? $delta : 0)];
        }

        $this->borrowDay[$dayKey] = $dayAfter;

        if ($dayBefore[1][0] > 0 || $dayAfter[1][0] > 0) {
            $this->borrowExcess += BorrowPolicy::excess($dayMin, $dayAfter[0], $dayAfter[1]) - BorrowPolicy::excess($dayMin, $dayBefore[0], $dayBefore[1]);
        }

        $short = fn (int $need, int $have) => max(0, $need - $have);
        $this->overTarget += max(0, $this->count[$key] - $requirement->target) ** 2 - max(0, $before - $requirement->target) ** 2;

        $this->minShort += $short($requirement->min, $this->count[$key]) - $short($requirement->min, $before)
            + $short($requirement->minMale, $this->maleCount[$key]) - $short($requirement->minMale, $beforeMale)
            + $short($requirement->minFemale, $this->femaleCount[$key]) - $short($requirement->minFemale, $beforeFemale);
        $this->targetShort += $short($requirement->target, $this->count[$key]) - $short($requirement->target, $before)
            + $short($requirement->targetMale, $this->maleCount[$key]) - $short($requirement->targetMale, $beforeMale)
            + $short($requirement->targetFemale, $this->femaleCount[$key]) - $short($requirement->targetFemale, $beforeFemale);

        // Tối thiểu theo nhóm vị trí (vd mỗi ca M6/A2 có ít nhất 1 Hostess, 1 quản lý): tính ở cả mức tối thiểu và mục tiêu.
        foreach ($beforeGroups as $group => $before) {
            if (($need = $requirement->positionMins[$group] ?? 0) > 0) {
                $this->minShort += $short($need, $before + $delta) - $short($need, $before);
            }

            if (($goal = $requirement->positionTarget($group)) > 0) {
                $this->targetShort += $short($goal, $before + $delta) - $short($goal, $before);
            }
        }

        $short = $this->count[$key] < $requirement->min || $this->maleCount[$key] < $requirement->minMale || $this->femaleCount[$key] < $requirement->minFemale;

        foreach ($requirement->positionMins as $group => $need) {
            if ($short) {
                break;
            }

            $short = $need > 0 && ($this->positionCount[$key][(string) $group] ?? 0) < $need;
        }

        $slot = "{$outletId}|{$shiftId}";

        if ($short) {
            $this->minShortSlots[$i][$slot] = true;
        } elseif (isset($this->minShortSlots[$i])) {
            unset($this->minShortSlots[$i][$slot]);

            if (! $this->minShortSlots[$i]) {
                unset($this->minShortSlots[$i]);
            }
        }

        $targetShort = $short || $this->count[$key] < $requirement->target || $this->maleCount[$key] < $requirement->targetMale || $this->femaleCount[$key] < $requirement->targetFemale;

        foreach ($requirement->positionTargets as $group => $goal) {
            $targetShort = $targetShort || ($this->positionCount[$key][(string) $group] ?? 0) < $goal;
        }

        if ($targetShort) {
            $this->shortSlots["{$i}|{$slot}"] = [$i, $requirement, $short];
        } else {
            unset($this->shortSlots["{$i}|{$slot}"]);
        }
    }

    /**
     * Người hỗ trợ Lobby (L) của ca này vẫn được tính cho outlet: dòng định biên có khung đếm, ca phủ trọn khung đếm
     * và thời gian ở Lobby (khung (L) của ca) không chạm khung đếm.
     */
    public function supportCountsAtOutlet(?Requirement $requirement, int $shiftId): bool
    {
        $window = $requirement?->countWindow;
        $shift = $this->problem->shifts[$shiftId] ?? null;

        if (! $window || ! $shift?->lobbyWindow) {
            return false;
        }

        [$lobbyStart, $lobbyEnd] = $shift->lobbyWindow;

        return $shift->start() <= $window[0] && $shift->end() >= $window[1] && ($lobbyEnd <= $window[0] || $lobbyStart >= $window[1]);
    }

    /** @var array<string, array{0: int, 1: Requirement, 2: bool}> "i|o|s" => [ngày, định biên, thiếu tối thiểu?] ô đang thiếu so với mục tiêu */
    private array $shortSlots = [];

    /**
     * Độ lệch quản lý giữa ca sáng và ca chiều của một outlet trong ngày: chênh lệch vượt mức không tránh được
     * (tổng lẻ thì lệch 1 là cân bằng nhất). Vd 4 sáng / 1 chiều → 2; 3 / 2 → 0; 2 / 2 → 0.
     */
    public static function managerPenalty(int $morning, int $afternoon): int
    {
        return max(0, abs($morning - $afternoon) - (($morning + $afternoon) % 2));
    }

    /**
     * Số quản lý ca sáng / chiều của một outlet trong ngày i.
     *
     * @return array{morning: int, afternoon: int}
     */
    public function managerShiftCounts(int $i, int $outletId): array
    {
        return $this->managerShifts[$i][$outletId] ?? ['morning' => 0, 'afternoon' => 0];
    }

    /**
     * Outlet × ngày đang lệch quản lý sáng / chiều.
     *
     * @return array<int, array{i: int, date: string, outlet_id: int, morning: int, afternoon: int}>
     */
    public function managerImbalances(): array
    {
        $rows = [];

        foreach ($this->managerShifts as $i => $outlets) {
            if (! ($this->inScope[$i] ?? false)) {
                continue;
            }

            foreach ($outlets as $outletId => $counts) {
                if (self::managerPenalty($counts['morning'], $counts['afternoon']) > 0) {
                    $rows[] = ['i' => $i, 'date' => $this->dates[$i], 'outlet_id' => $outletId, 'morning' => $counts['morning'], 'afternoon' => $counts['afternoon']];
                }
            }
        }

        return $rows;
    }

    /**
     * Các ô định biên đang thiếu người so với mục tiêu (kèm cờ thiếu tối thiểu).
     *
     * @return array<string, array{0: int, 1: Requirement, 2: bool}>
     */
    public function shortSlots(): array
    {
        return $this->shortSlots;
    }

    /**
     * Lưu đệm chỉ số từng người trước một bước thử; nếu bước bị hoàn tác (lịch trở lại y hệt) thì trả lại đệm cũ
     * thay vì tính lại – phần lớn bước thử bị từ chối.
     *
     * @return array<int, array<string, mixed>>
     */
    public function metricsSnapshot(): array
    {
        return [$this->metrics, $this->terms, $this->totals, $this->dirty];
    }

    public function restoreMetrics(array $snapshot): void
    {
        [$this->metrics, $this->terms, $this->totals, $this->dirty] = $snapshot;
    }

    private function buildTimeline(): void
    {
        $dates = $this->problem->days;

        foreach ($this->problem->fixed as $assignment) {
            $dates[] = $assignment->date;
        }

        sort($dates);
        $cursor = CarbonImmutable::parse(reset($dates));
        $last = CarbonImmutable::parse(end($dates));
        $scope = array_flip($this->problem->days);

        for ($i = 0; $cursor->lte($last); $i++, $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $this->dates[$i] = $date;
            $this->index[$date] = $i;
            $this->inScope[$i] = isset($scope[$date]);
            $this->weekOf[$i] = $cursor->format('o-W');
            $this->weekday[$i] = $cursor->dayOfWeekIso;
            $this->weekDays[$this->weekOf[$i]][] = $i;
            // Số thứ tự tuần tính từ thứ Hai 05/01/2026.
            $this->weekSeq[$i] = intdiv((int) CarbonImmutable::parse('2026-01-05')->diffInDays($cursor->startOfWeek(), false), 7);
            $this->weekDaysBySeq[$this->weekSeq[$i]][] = $i;
        }
    }
}
