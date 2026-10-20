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

    /** @var array<string, array<string, int>> "i|o|s" => [nhóm vị trí => số người] */
    private array $positionCount = [];

    private int $minShort = 0;

    private int $targetShort = 0;

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

    /** @var array<string, int> "employeeId|dayIndex" => outletId cho các ô xếp ngoài outlet chính */
    private array $crossCells = [];

    /** @var array<int, array<int, array{0: int, 1: int, 2: int, 3: int, 4: string, 5: ?array}>> [dayIndex][k] => [phút bắt đầu, phút kết thúc, tối thiểu, mục tiêu, nhãn, ca được tính (null = mọi ca)] */
    public array $coverage = [];

    /** @var array<int, array<int, int>> [dayIndex][k] => số người đang phủ khung giờ */
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

        foreach ($problem->requirements as $requirement) {
            $i = $this->index[$requirement->date] ?? null;

            if ($i !== null && $this->inScope[$i]) {
                $this->requirements[$i]["{$requirement->outletId}|{$requirement->shiftId}"] = $requirement;
                $this->minShort += $requirement->min + $requirement->minMale + array_sum($requirement->positionMins);
                $this->targetShort += $requirement->target + $requirement->targetMale + array_sum($requirement->positionMins);
            }
        }

        foreach ($problem->coverage as $window) {
            $i = $this->index[$window->date] ?? null;

            if ($i !== null && $this->inScope[$i]) {
                $this->coverage[$i][] = [$window->start, $window->end, $window->min, max($window->min, $window->target), $window->label, $window->shiftIds];
                $this->coverageCount[$i][] = 0;
                $this->minShort += $window->min;
                $this->targetShort += max($window->min, $window->target);
            }
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

            if (isset($this->nightPool[$employee->id]) && $dayShift && ! $employee->fixed && ! isset($this->nightOutlets[$employee->homeOutletId])) {
                $this->nightRotation[$employee->id] = true;
            }
        }

        $this->fixedHardViolations = count($this->validate(fixedOnly: true));
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

    public function coverageCount(int $i, int $k): int
    {
        return $this->coverageCount[$i][$k] ?? 0;
    }

    /**
     * Khung giờ phủ Lobby mà khoảng [start, end] được tính là phủ (có mặt >= tỉ lệ cấu hình của khung).
     *
     * @return int[] chỉ số khung
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

            $overlap = min($end, $interval[1]) - max($start, $interval[0]);

            if ($overlap > 0 && $overlap >= ($end - $start) * $this->problem->rules->coverageOverlapRatio) {
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
            || ($employee->isMale() && $this->maleCount($i, $outletId, $shiftId) - 1 < $requirement->minMale)
            || $this->positionShort($employeeId, $i, $requirement, -1);
    }

    // ------------------------------------------------------------------ Thay đổi

    public function place(int $employeeId, int $i, int $outletId, int $shiftId, bool $support = false): void
    {
        $this->plan[$employeeId][$i] = self::slotKey($outletId, $shiftId, $support);
        $this->adjustCount($i, $outletId, $shiftId, $employeeId, 1, $support);
        unset($this->metrics[$employeeId]);

        if ($outletId !== $this->problem->employees[$employeeId]->homeOutletId) {
            $this->crossCells["{$employeeId}|{$i}"] = $outletId;
        }
    }

    public function remove(int $employeeId, int $i): void
    {
        if (! isset($this->plan[$employeeId][$i])) {
            return;
        }

        [$o, $s, $f] = self::parseSlot($this->plan[$employeeId][$i]);
        unset($this->plan[$employeeId][$i], $this->crossCells["{$employeeId}|{$i}"]);
        $this->adjustCount($i, $o, $s, $employeeId, -1, $f);
        unset($this->metrics[$employeeId]);
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

        if ($support) {
            if (isset($this->problem->outletParents[$outletId])) {
                return ['support', sprintf('%s không đi hỗ trợ Lobby (L)', $this->problem->outletName($outletId))];
            }

            if (! $shift->lobbyWindow) {
                return ['support', "Ca {$shift->code} không có khung hỗ trợ Lobby (L)"];
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
                $windows = $source === null ? [] : $this->supportWindows($i, $o, $s, $f);

                foreach ($windows as $k) {
                    $this->supportFrom[$i][$k][$source]--;
                }

                $result = $this->violation($employeeId, $i, (int) $o, $s, $ot, checkEligibility: ! $isFixed, support: $f);

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
     * @return int[] Vector mục tiêu, so sánh theo thứ tự (xem compare()).
     */
    public function objective(): array
    {
        $heavy = 0;
        $preference = 0;
        $ratios = [];
        $heavySquares = 0;
        $nightSquares = 0;
        $nightRotation = 0;
        $offComfort = 0;
        $shiftMix = 0;
        $overNorm = 0;
        $lobbySquares = 0;
        $offSplit = 0;
        $busyOff = 0;
        $underNorm = 0;
        $childBorrow = 0;

        foreach ($this->problem->employees as $employeeId => $employee) {
            $m = $this->metricsFor($employeeId);
            // OFF lẻ và OFF dồn quá 3 ngày cùng một mức: OFF phải thành cụm 2–3 ngày.
            $offSplit += $m['off_split'] + $m['off_overlong'];
            $busyOff += $m['busy_off'];
            $offComfort += $m['off_blocks'] + $m['off_overlong'] + $m['off_single'];
            $shiftMix += $m['shift_mix'];
            $overNorm += $m['over_norm'];
            $underNorm += $m['under_norm'];
            $childBorrow += $m['child_borrow'];
            // Cờ (L) không cần thiết bị phạt nhẹ để thuật toán chỉ giao hỗ trợ Lobby khi khung giờ cần người.
            $preference += $m['support_flags'];

            // Chia đều lượt Lobby giữa các nhân viên (tính cả các tuần trước): không để một người đi Lobby suốt.
            // Trong kỳ chia đều là chính; lịch sử (lượt TB/tuần các tuần trước) để luân phiên giữa các tuần.
            if ($m['lobby'] > 0) {
                $history = $this->problem->lobbyHistory[$employeeId] ?? 0;
                $lobbySquares += 4 * $m['lobby'] ** 2 + ($m['lobby'] + $history) ** 2 - $history ** 2;
            }
            $heavy += $m['heavy'];
            $heavySquares += $m['heavy'] ** 2;
            $preference += $m['non_home'] * 3 + $m['non_primary'] * 2 + $m['soft_conflicts'] * 6 + $m['changes'] * 4;

            if ($this->expectedDays[$employeeId] > 0) {
                $ratios[] = $m['worked'] / $this->expectedDays[$employeeId];
            }

            if (isset($this->nightRotation[$employeeId])) {
                // Luân phiên ca đêm theo tuần: tuần này một nhóm làm đêm, tuần sau tới người khác
                // (ít người có tuần đêm trong kỳ, người đã làm đêm các tuần trước được nghỉ đêm), không trộn đêm với ca ngày trong tuần.
                $history = $this->problem->nightHistory[$employeeId] ?? 0;
                $nightRotation += ($m['night_weeks'] + $history) ** 2 - $history ** 2 + $m['night_mixed'];
            } elseif (isset($this->nightPool[$employeeId])) {
                $nightSquares += $m['night'] ** 2;
                $nightRotation += $m['night_staff_day'];
            }
        }

        $variance = 0.0;

        if ($ratios) {
            $mean = array_sum($ratios) / count($ratios);

            foreach ($ratios as $ratio) {
                $variance += ($ratio - $mean) ** 2;
            }
        }

        // Trọng số chỉ dùng bên trong cùng một mức; các mức khác nhau không bao giờ đánh đổi nhau.
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

        // Người dôi ra trên định biên mục tiêu (để đủ ngày làm) được chia đều giữa các ca.
        $overTarget = 0;

        foreach ($this->requirements as $i => $requirements) {
            foreach ($requirements as $requirement) {
                if (($over = $this->count($i, $requirement->outletId, $requirement->shiftId) - $requirement->target) > 0) {
                    $overTarget += $over ** 2;
                }
            }
        }

        $fairness = (int) round($overTarget * 50 + $variance * 10000 + $heavySquares * 20 + $nightSquares * 20 + $lobbySquares * 10 + $supportSquares * 60);

        $values = [
            'hard' => $this->fixedHardViolations, 'source_shortage' => count($this->sourceShortages()), 'min_shortage' => $this->minShort,
            'off_split' => $offSplit, 'over_norm' => $overNorm, 'target_shortage' => $this->targetShort, 'busy_day_off' => $busyOff, 'night_rotation' => $nightRotation, 'lobby_mix' => $lobbyMix, 'under_norm' => $underNorm, 'child_borrow' => $childBorrow,
            'heavy_transitions' => $heavy, 'shift_mix' => $shiftMix, 'fairness' => $fairness, 'off_comfort' => $offComfort, 'preference' => $preference,
        ];

        return array_map(fn ($label) => $values[$label], self::objectiveLabels($this->problem->rules));
    }

    /**
     * Thứ tự các mức mục tiêu. Tối ưu lại lịch đang dùng (stabilityFirst): cùng thứ tự với xếp mới, chỉ đưa "ít thay đổi" lên ngay
     * sau các quy tắc bắt buộc (đủ tối thiểu, OFF liền nhau, ngày làm đúng định mức) – một người nghỉ đột xuất không làm xáo trộn cả tuần.
     *
     * @return string[]
     */
    public static function objectiveLabels(Rules $rules): array
    {
        return $rules->stabilityFirst
            ? ['hard', 'source_shortage', 'min_shortage', 'under_norm', 'off_split', 'over_norm', 'child_borrow', 'preference', 'busy_day_off', 'night_rotation', 'shift_mix', 'heavy_transitions', 'lobby_mix', 'target_shortage', 'fairness', 'off_comfort']
            : ['hard', 'source_shortage', 'min_shortage', 'under_norm', 'off_split', 'over_norm', 'child_borrow', 'busy_day_off', 'night_rotation', 'shift_mix', 'heavy_transitions', 'lobby_mix', 'target_shortage', 'fairness', 'off_comfort', 'preference'];
    }

    /**
     * Ô do thuật toán xếp ngoài outlet chính trong khi một ca mà nhân viên đó chuyên trách
     * tại outlet chính cùng ngày đang thiếu tối thiểu (vi phạm "không điều chuyển gây thiếu nơi nguồn").
     *
     * @return array<int, array{employee_id: int, date: string, outlet_id: int, home_outlet_id: ?int, home_shift_id: int}>
     */
    public function sourceShortages(): array
    {
        $result = [];

        foreach ($this->crossCells as $cell => $outletId) {
            [$employeeId, $i] = array_map('intval', explode('|', $cell));
            $employee = $this->problem->employees[$employeeId];

            foreach ($this->requirements[$i] ?? [] as $requirement) {
                if ($requirement->outletId !== $employee->homeOutletId
                    || $employee->skillLevel($requirement->outletId, $requirement->shiftId) !== EmployeeSpec::PRIMARY) {
                    continue;
                }

                $count = $this->count($i, $requirement->outletId, $requirement->shiftId);
                $male = $this->maleCount($i, $requirement->outletId, $requirement->shiftId);

                if ($count < $requirement->min || ($employee->isMale() && $male < $requirement->minMale) || $this->positionShort($employeeId, $i, $requirement, 0)) {
                    $result[] = ['employee_id' => $employeeId, 'date' => $this->dates[$i], 'outlet_id' => $outletId, 'home_outlet_id' => $employee->homeOutletId, 'home_shift_id' => $requirement->shiftId];

                    break;
                }
            }
        }

        return $result;
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

    /**
     * Ngày k là OFF và cùng tuần (thứ Hai → Chủ nhật) với ngày i: OFF chỉ ghép cặp trong tuần,
     * không ghép OFF thứ Hai với Chủ nhật tuần trước (tránh tuần OFF thứ Hai + Chủ nhật).
     */
    private function isOffDayInWeek(int $employeeId, int $k, int $i): bool
    {
        return isset($this->dates[$k]) && $this->weekOf[$k] === $this->weekOf[$i] && $this->isOffDay($employeeId, $k);
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
     * @return array{worked: int, hours: float, heavy: int, night: int, morning: int, afternoon: int, non_home: int, non_primary: int, soft_conflicts: int, changes: int, outlets: array<int, int>}
     */
    public function metricsFor(int $employeeId): array
    {
        if (isset($this->metrics[$employeeId])) {
            return $this->metrics[$employeeId];
        }

        $employee = $this->problem->employees[$employeeId];
        $m = ['worked' => 0, 'hours' => 0.0, 'heavy' => 0, 'night' => 0, 'morning' => 0, 'afternoon' => 0, 'non_home' => 0, 'non_primary' => 0, 'soft_conflicts' => 0, 'changes' => 0, 'off_blocks' => 0, 'off_overlong' => 0, 'off_single' => 0, 'off_split' => 0, 'busy_off' => 0, 'under_norm' => 0, 'child_borrow' => 0, 'night_weeks' => 0, 'night_mixed' => 0, 'night_staff_day' => 0, 'over_norm' => 0, 'lobby' => 0, 'support_flags' => 0, 'shift_mix' => 0, 'outlets' => []];

        foreach ($this->scopeIndexes() as $i) {
            $date = $this->dates[$i];
            $current = $this->shiftAt($employeeId, $i);

            if (! $this->isFixed($employeeId, $i)) {
                $baseline = $this->problem->baseline[$employeeId][$date] ?? null;
                $planned = $this->plan[$employeeId][$i] ?? null;

                if ($baseline !== $planned && ! ($baseline === null && $planned === null)) {
                    $m['changes']++;
                }
            }

            if (! $current) {
                continue;
            }

            [$o, $s, $ot, $f] = $current;
            $shift = $this->problem->shifts[$s];
            $m['worked']++;

            if ($this->supportInterval($o, $s, $f)) {
                $m['lobby']++;
            }

            if ($f && ! $this->isFixed($employeeId, $i)) {
                $m['support_flags']++;
            }

            $m['hours'] += $shift->hours + $ot;
            $m[$shift->category()]++;
            $m['outlets'][$o] = ($m['outlets'][$o] ?? 0) + 1;

            if ($o !== null && isset($this->problem->outletParents[$o]) && ! $this->problem->isHomeOutlet($employee, $o)) {
                $m['child_borrow']++;
            }

            if ($o !== null && ! $this->problem->isHomeOutlet($employee, $o)) {
                $m['non_home']++;
            }

            if ($o !== null && $employee->skillLevel($o, $s) !== EmployeeSpec::PRIMARY) {
                $m['non_primary']++;
            }

            if (isset($this->problem->softUnavailable[$employeeId][$date])) {
                $m['soft_conflicts']++;
            }

            if ($this->isHeavyPair($employeeId, $i, $i + 1)) {
                $m['heavy']++;
            }
        }

        // Cặp (ngày trước phạm vi, ngày đầu phạm vi).
        $first = $this->scopeIndexes()[0] ?? null;

        if ($first !== null && $first > 0 && $this->isHeavyPair($employeeId, $first - 1, $first)) {
            $m['heavy']++;
        }

        // Cụm OFF: mỗi chuỗi ngày không làm bắt đầu trong phạm vi là một cụm; ít cụm hơn = OFF liền nhau hơn.
        // Cụm dài quá 3 ngày bị phạt thêm từng ngày vượt (muốn nghỉ liền 2–3 ngày, không dồn quá dài).
        foreach ($this->scopeIndexes() as $i) {
            if ($this->shiftAt($employeeId, $i) || ($i > 0 && ! $this->shiftAt($employeeId, $i - 1) && isset($this->dates[$i - 1]))) {
                continue;
            }

            $length = 0;

            for ($k = $i; isset($this->dates[$k]) && ! $this->shiftAt($employeeId, $k); $k++) {
                $length++;
            }

            $m['off_blocks']++;
            $m['off_overlong'] += max(0, $length - 3);

            // OFF lẻ 1 ngày kẹp giữa hai ngày làm: phạt thêm (ưu tiên 2+2 hơn 3+1).
            if ($length === 1 && isset($this->dates[$i + 1]) && $this->shiftAt($employeeId, $i + 1)) {
                $m['off_single']++;
            }
        }

        // Tuần có ca đêm, và số ca ngày lẫn trong tuần có ca đêm.
        $byWeekNight = [];

        foreach ($this->scopeIndexes() as $i) {
            if ($current = $this->shiftAt($employeeId, $i)) {
                $kind = $this->problem->shifts[$current[1]]->category() === 'night' ? 'n' : 'd';
                $byWeekNight[$this->weekOf[$i]][$kind] = ($byWeekNight[$this->weekOf[$i]][$kind] ?? 0) + 1;
            }
        }

        if (isset($this->nightPool[$employeeId], $this->nightOutlets[$employee->homeOutletId]) && ! $employee->fixed) {
            foreach ($byWeekNight as $counts) {
                $m['night_staff_day'] += $counts['d'] ?? 0;
            }
        }

        foreach ($byWeekNight as $counts) {
            if (($counts['n'] ?? 0) > 0) {
                $m['night_weeks']++;
                $m['night_mixed'] += min($counts['n'], $counts['d'] ?? 0);
            }
        }

        // OFF 2 ngày liền nhau (trong cùng tuần – không có tuần OFF thứ Hai + Chủ nhật) & hạn chế OFF ngày đông khách.
        // Chỉ xét ngày OFF do thuật toán chọn:
        // ngày nhân viên tự xin OFF / nghỉ phép đã duyệt và ô quản lý đã khóa được miễn (sinh nhật thì không – OFF còn lại nên ghép cạnh).
        foreach ($this->scopeIndexes() as $i) {
            if (! $this->isOffDay($employeeId, $i) || $this->isExcusedOff($employeeId, $i)) {
                continue;
            }

            if ($this->isBusyDay($i)) {
                $m['busy_off']++;
            }

            if ($employee->daysOffPerWeek >= 2 && ! $this->isOffDayInWeek($employeeId, $i - 1, $i) && ! $this->isOffDayInWeek($employeeId, $i + 1, $i)) {
                $m['off_split']++;
            }
        }


        // Xen kẽ ca: người không có ca cứng, mỗi tuần làm >= 2 ca ngày thì phải có cả ca sáng lẫn ca chiều,
        // (phạt nặng), và số ca sáng / chiều không lệch quá 2 (phạt nhẹ). Ca đêm không tính.
        if (! $employee->fixed) {
            $byWeek = [];

            foreach ($this->scopeIndexes() as $i) {
                if (($current = $this->shiftAt($employeeId, $i)) && in_array($category = $this->problem->shifts[$current[1]]->category(), ['morning', 'afternoon'], true)) {
                    $byWeek[$this->weekOf[$i]][$category] = ($byWeek[$this->weekOf[$i]][$category] ?? 0) + 1;
                }
            }

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

        // Ngày làm vượt định mức tuần (chỉ có thể xảy ra ở chế độ OFF theo cặp 2 tuần).
        $weeks = [];

        foreach ($this->scopeIndexes() as $i) {
            $weeks[$this->weekOf[$i]] = $i;
        }

        foreach ($weeks as $i) {
            $m['over_norm'] += max(0, $this->workedInWeek($employeeId, $i) - $employee->maxWorkDaysPerWeek());
        }

        // Thiếu ngày làm so với định mức (tuần trọn trong kỳ): không để ai mất ngày làm khi vẫn còn chỗ
        // (vd người ca cứng M6 bị nhường chỗ cho người khác xen kẽ ca). Cộng cả kỳ để hợp chế độ OFF theo cặp 2 tuần.
        $expected = 0;
        $worked = 0;

        foreach ($this->weekDays as $days) {
            if (count(array_filter($days, fn ($k) => $this->inScope[$k])) < 7) {
                continue;
            }

            $available = count(array_filter($days, fn ($k) => ! isset($this->problem->hardUnavailable[$employeeId][$this->dates[$k]])
                && ! (isset($this->fixed[$employeeId][$k]) && ! $this->fixed[$employeeId][$k]->isWorking())));
            $expected += min($employee->maxWorkDaysPerWeek(), $available);
            $worked += $this->workedInWeek($employeeId, $days[0]);
        }

        $m['under_norm'] = max(0, $expected - $worked);

        return $this->metrics[$employeeId] = $m;
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

    private function adjustCount(int $i, int $outletId, int $shiftId, int $employeeId, int $delta, bool $support = false): void
    {
        if (! empty($this->coverage[$i])) {
            $source = $this->supportSource($employeeId, $outletId);

            foreach ($this->supportWindows($i, $outletId, $shiftId, $support) as $k) {
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

        // Người đi hỗ trợ Lobby rời nhà hàng: không tính vào định biên outlet × ca (đã đếm ở supportFrom).
        if ($support && $outletId !== $this->problem->stationOutletId) {
            return;
        }

        $requirement = $this->requirements[$i]["{$outletId}|{$shiftId}"] ?? null;
        $isMale = $this->problem->employees[$employeeId]?->isMale() ?? false;

        $before = $this->count[$key] ?? 0;
        $beforeMale = $this->maleCount[$key] ?? 0;
        $this->count[$key] = $before + $delta;
        $this->maleCount[$key] = $beforeMale + ($isMale ? $delta : 0);

        $groups = $this->problem->groupsOfPosition($this->problem->employees[$employeeId]?->positionId);
        $beforeGroups = [];

        foreach ($groups as $group) {
            $beforeGroups[$group] = $this->positionCount[$key][$group] ?? 0;
            $this->positionCount[$key][$group] = $beforeGroups[$group] + $delta;
        }

        if (! $requirement) {
            return;
        }

        $short = fn (int $need, int $have) => max(0, $need - $have);

        $this->minShort += $short($requirement->min, $this->count[$key]) - $short($requirement->min, $before)
            + $short($requirement->minMale, $this->maleCount[$key]) - $short($requirement->minMale, $beforeMale);
        $this->targetShort += $short($requirement->target, $this->count[$key]) - $short($requirement->target, $before)
            + $short($requirement->targetMale, $this->maleCount[$key]) - $short($requirement->targetMale, $beforeMale);

        // Tối thiểu theo nhóm vị trí (vd mỗi ca M6/A2 có ít nhất 1 Hostess, 1 quản lý): tính ở cả mức tối thiểu và mục tiêu.
        foreach ($beforeGroups as $group => $before) {
            if (($need = $requirement->positionMins[$group] ?? 0) > 0) {
                $diff = $short($need, $before + $delta) - $short($need, $before);
                $this->minShort += $diff;
                $this->targetShort += $diff;
            }
        }
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
