<?php

namespace Botble\RestaurantRoster\Services;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\OutletDay;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\ShiftTransition;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Models\CoverageRequirement;
use Botble\RestaurantRoster\Scheduling\BirthdayRule;
use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Problem;
use Botble\RestaurantRoster\Scheduling\RequirementResolver;
use Botble\RestaurantRoster\Scheduling\Rules;
use Botble\RestaurantRoster\Scheduling\ShiftSpec;
use Botble\RestaurantRoster\Services\StaffingRequirementService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Đọc dữ liệu từ database và dựng bài toán cho Scheduling Engine.
 *
 * - Nhân viên: đang hoạt động và bật "xếp lịch tự động"; năng lực lấy từ roster_employee_skills
 *   (không có dòng năng lực = không đủ điều kiện, không suy đoán).
 * - Đơn đã duyệt và ngày nghỉ phép trong lịch → không được xếp (cứng); đơn chờ duyệt → ràng buộc mềm.
 * - Ô đã khóa → cố định. Ô 7 ngày trước và 1 ngày sau phạm vi → ngữ cảnh cố định
 *   (để tính đúng giờ nghỉ, số ngày làm liên tục và số ngày OFF trong tuần).
 * - Ô chưa khóa trong phạm vi → lịch gốc, thuật toán cố giữ nguyên khi còn hợp lệ.
 * - Khi giới hạn theo outlet: nhân viên thuộc outlet khác giữ nguyên lịch (cố định).
 */
class ProblemBuilder
{
    /** @var array<string, string> */
    public array $notes = [];

    /**
     * @param int[]|null $outletIds null = mọi outlet
     * @param array<string, mixed> $overrides Ghi đè tham số chạy (time_limit_seconds, seed, ...)
     * @param bool $freezeCurrent true = coi toàn bộ lịch hiện tại là cố định (dùng cho "Kiểm tra lịch")
     */
    public function build(CarbonInterface $from, CarbonInterface $to, ?array $outletIds = null, array $overrides = [], bool $freezeCurrent = false): Problem
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        $this->conflicts = [];
        $this->usedRequirementIds = [];
        $days = array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($from, $to)));

        $outlets = Outlet::query()->wherePublished()->get(['id', 'name', 'code']);
        // Ép kiểu số: id từ form là chuỗi ("2"), id từ database là số – so sánh chặt bên dưới sẽ trượt nếu lẫn kiểu.
        $allOutletIds = $outlets->pluck('id')->map(fn ($id) => (int) $id)->all();
        $targetOutletIds = $outletIds
            ? array_values(array_intersect(array_map('intval', $outletIds), $allOutletIds))
            : $allOutletIds;

        $templates = ShiftTemplate::query()->wherePublished()->get();
        $shifts = $templates->map(fn (ShiftTemplate $t) => $this->shiftSpec($t))->all();

        // Thực tập sinh không được tính vào định biên và không được tự xếp: quản lý xếp tay, bộ giải bỏ qua.
        $employees = Employee::query()
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->where('is_trainee', false)
            ->with(['skills', 'fixedShift:id,start_time'])
            ->get();

        // Điều chuyển giữa các outlet (quản lý 23/10/2026, cấu hình cross_outlet_transfer):
        // "auto" (mặc định) = ưu tiên đủ người mọi outlet: waiter / hostess (và Night đa năng) tự đủ điều kiện thay thế ở các outlet
        //   transfer_outlets (Gusto ↔ Villa); người outlet chính vẫn được ưu tiên, không rút làm outlet nguồn dưới tối thiểu;
        // "skills" = chỉ theo năng lực đã khai; "flex_only" = không tự lấy người outlet nhà hàng khác (trừ Night đa năng).
        $nightStaff = new NightStaffService($this);
        $nightOutletIds = $nightStaff->nightOutletIds();
        $parents = $this->outletParents($outlets);
        $stationId = $outlets->firstWhere('code', config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY'))?->getKey();
        $transferPolicy = config('plugins.restaurant-roster.general.scheduling.cross_outlet_transfer', 'auto');
        $transferOutletIds = $outlets->whereIn('code', (array) config('plugins.restaurant-roster.general.scheduling.transfer_outlets', ['GUSTO', 'VILLA']))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $transferPositions = array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config('plugins.restaurant-roster.general.scheduling.transfer_positions', ['Waiter', 'Hostess']));
        // Room Service: chuyên trách theo ca, dự phòng, người đã xác nhận kỹ năng thay thế (Nhân sự Room Service).
        $roomService = app(RoomServiceStaffService::class);
        $roomOutletId = $roomService->outletId();
        $roomStaff = $roomOutletId ? $roomService->effectiveRows($from, $to)->groupBy('employee_id') : collect();
        // Nam waiter đủ điều kiện luân phiên Night (hồ sơ): Villa / Gusto / Night, không Night cứng, không Room Service cứng, đang bật.
        $rotationIds = array_flip($nightStaff->groups($from)['rotation']->modelKeys());
        $maleStaffPositions = array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config('plugins.restaurant-roster.general.scheduling.male_staff_positions', ['Waiter']));

        // Cấp quản lý: theo cờ "Cấp quản lý" của vị trí (không so tên chức vụ); chỉ tự xếp các ca manager_shift_codes.
        $managerPositions = array_flip($this->managerPositionIds());
        $managerCodes = array_map('strtoupper', (array) config('plugins.restaurant-roster.general.scheduling.manager_shift_codes', ['M6', 'A2']));
        $managerShiftIds = $templates->filter(fn (ShiftTemplate $t) => in_array(strtoupper((string) $t->code), $managerCodes, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?: null;

        $childStaff = $this->childOutletStaffRules($outlets);
        // Vị trí được tính là "nữ waiter" cho định biên nữ trực tiếp tại outlet (trống = mọi vị trí không thuộc cấp quản lý).
        $femaleStaffPositions = array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config('plugins.restaurant-roster.general.scheduling.female_staff_positions', ['Waiter']));
        $positionNames = Position::query()->pluck('name', 'id')->map(fn ($name) => mb_strtolower(trim((string) $name)))->all();

        $specs = [];
        $decisionIds = [];

        foreach ($employees as $employee) {
            $skills = [];
            $fixed = [];

            foreach ($employee->skills as $skill) {
                $key = $skill->outlet_id . ':' . ($skill->shift_template_id ?: 0);
                $skills[$key] = $skill->level === EmployeeSpec::PRIMARY ? EmployeeSpec::PRIMARY : EmployeeSpec::ELIGIBLE;

                if ($skill->is_fixed) {
                    $fixed[] = $key;
                }
            }

            // Ca cứng trong hồ sơ: thuật toán chỉ xếp vào ca này, tại các outlet có năng lực làm ca đó
            // (chưa có thì mặc định outlet chính). Ca cứng trong hồ sơ thay cho cờ "ca cứng" trên dòng năng lực.
            if ($fixedShiftId = (int) $employee->fixed_shift_template_id) {
                $fixed = [];

                foreach ($skills as $key => $level) {
                    [$o, $s] = array_map('intval', explode(':', $key));

                    if ($s === 0 || $s === $fixedShiftId) {
                        $fixed[] = "{$o}:{$fixedShiftId}";
                    }
                }

                if (! $fixed && $employee->outlet_id) {
                    $skills["{$employee->outlet_id}:{$fixedShiftId}"] = EmployeeSpec::PRIMARY;
                    $fixed[] = "{$employee->outlet_id}:{$fixedShiftId}";
                }
            }

            // Room Service: chuyên trách ca → chỉ làm ca đó ở Room Service (hoặc OFF) khi hiệu lực trọn kỳ; dự phòng → ưu tiên thay;
            // đã xác nhận kỹ năng → đủ điều kiện thay thế. Không ai khác được tự coi là làm được Room Service.
            $isRoomLead = false;

            foreach ($roomStaff[$employee->getKey()] ?? [] as $row) {
                if ($row->role === \Botble\RestaurantRoster\Models\RoomServiceStaff::ROLE_LEAD && $row->shift_template_id) {
                    $isRoomLead = true;
                    $skills["{$roomOutletId}:{$row->shift_template_id}"] = EmployeeSpec::PRIMARY;

                    if ((! $row->effective_from || $row->effective_from->lte($from)) && (! $row->effective_to || $row->effective_to->gte($to))) {
                        $fixed = ["{$roomOutletId}:{$row->shift_template_id}"];
                    }
                } elseif ($row->role === \Botble\RestaurantRoster\Models\RoomServiceStaff::ROLE_BACKUP) {
                    $skills["{$roomOutletId}:0"] = EmployeeSpec::PRIMARY;
                } else {
                    $skills["{$roomOutletId}:0"] ??= EmployeeSpec::ELIGIBLE;
                }
            }

            // Khu trực thuộc theo quy tắc chung (vd Kid-Deline: bất kỳ nữ Waiter của Gusto) – không cần khai báo từng người.
            foreach ($childStaff as $childId => $rule) {
                if ((int) $employee->outlet_id === $rule['parent_id']
                    && ($rule['gender'] === null || $employee->gender === $rule['gender'])
                    && (! $rule['positions'] || in_array($positionNames[(int) $employee->position_id] ?? '', $rule['positions'], true))) {
                    $skills["{$childId}:0"] ??= EmployeeSpec::PRIMARY;
                }
            }

            // Nhân viên đa năng: thuộc outlet Night, không phải Night cứng, không phải Room Service chuyên trách – được mượn trước tiên.
            $isFlex = in_array((int) $employee->outlet_id, $nightOutletIds, true) && ! $employee->isNightCore() && ! $isRoomLead;
            $canTransfer = ! isset($managerPositions[(int) $employee->position_id]) && ! $employee->fixed_shift_template_id && ! $isRoomLead
                && ($isFlex || in_array($positionNames[(int) $employee->position_id] ?? '', $transferPositions, true));

            if ($transferPolicy === 'auto' && $canTransfer) {
                foreach ($transferOutletIds as $o) {
                    if ($o !== (int) $employee->outlet_id) {
                        $skills["{$o}:0"] ??= EmployeeSpec::ELIGIBLE;
                    }
                }
            }

            if ($transferPolicy === 'flex_only' && $employee->night_role !== EmployeeSpec::NIGHT_FLEX) {
                foreach (array_keys($skills) as $key) {
                    $o = (int) explode(':', $key)[0];

                    if ($o !== (int) $employee->outlet_id && $o !== (int) $stationId && ! in_array($o, $nightOutletIds, true)
                        && ($parents[$o] ?? null) !== (int) $employee->outlet_id) {
                        unset($skills[$key]);
                        $this->notes['transfer_blocked'][$employee->name] = true;
                    }
                }
            }

            if (! $skills && $employee->auto_schedule) {
                $this->notes['no_skills'][] = $employee->name;
            }

            if ($employee->gender === null) {
                $this->notes['no_gender'][] = $employee->name;
            }

            $specs[] = new EmployeeSpec(
                id: $employee->getKey(),
                name: (string) $employee->name,
                gender: $employee->gender,
                homeOutletId: $employee->outlet_id,
                weeklyHours: (float) $employee->weekly_hours,
                daysOffPerWeek: (int) $employee->days_off_per_week,
                skills: $skills,
                fixed: $fixed,
                residence: $employee->residence,
                positionId: $employee->position_id ? (int) $employee->position_id : null,
                isManager: isset($managerPositions[(int) $employee->position_id]),
                managerShiftIds: $managerShiftIds,
                isFemaleStaff: $employee->gender === 'female' && ! isset($managerPositions[(int) $employee->position_id])
                    && (! $femaleStaffPositions || in_array($positionNames[(int) $employee->position_id] ?? '', $femaleStaffPositions, true)),
                // Định biên nam = nam waiter: không tính cấp quản lý, chỉ vị trí trong male_staff_positions.
                isMaleStaff: $employee->gender === 'male' && ! isset($managerPositions[(int) $employee->position_id])
                    && (! $maleStaffPositions || in_array($positionNames[(int) $employee->position_id] ?? '', $maleStaffPositions, true)),
                // Night cứng = ca cứng ca đêm (bắt buộc ≥ 1 người / ngày); luân phiên = nam waiter đủ điều kiện (hồ sơ).
                nightRole: match (true) {
                    $employee->isNightCore() => EmployeeSpec::NIGHT_CORE,
                    isset($rotationIds[$employee->getKey()]) => EmployeeSpec::NIGHT_ROTATION,
                    default => null,
                },
                isFlex: $isFlex,
            );

            if ($employee->auto_schedule && ! $freezeCurrent && in_array((int) $employee->outlet_id, $targetOutletIds, true)) {
                $decisionIds[$employee->getKey()] = true;
            }
        }

        // Trạm hỗ trợ (Lobby): chỉ xếp khi không lọc outlet, hoặc có chọn outlet trạm.
        $stationCode = config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY');
        $stationOutletId = ($id = $outlets->firstWhere('code', $stationCode)?->getKey()) ? (int) $id : null;
        $withStation = $stationOutletId !== null && (! $outletIds || in_array($stationOutletId, $targetOutletIds, true));
        $coverage = $withStation ? $this->coverage($days, $stationCode, $templates) : [];

        $employeeIds = $employees->pluck('id')->all();
        [$hard, $soft] = $this->availability($employeeIds, $from, $to);
        [$fixed, $baseline] = $this->existingShifts($employeeIds, $decisionIds, $from, $to, $days, $hard);
        $regularOff = $this->birthdayOffs($employees, $days, $fixed, $hard);

        $requirements = $this->requirements($days, array_values(array_diff($targetOutletIds, [$stationOutletId])), $templates->pluck('id')->all());

        // Khoảng đánh giá công bằng: quản lý chọn khi chạy (4 / 8 tuần…), mặc định theo cấu hình.
        $historyWeeks = max(1, min(26, (int) ($overrides['history_weeks'] ?? config('plugins.restaurant-roster.general.scheduling.history_weeks', 4))));
        [$supportHistory, $shiftHistory] = $this->assignmentHistory($employeeIds, $from, $historyWeeks, $stationOutletId, $outlets, $templates);

        return new Problem(
            days: $days,
            employees: $specs,
            shifts: $shifts,
            requirements: [
                ...$requirements,
                ...$this->stationSlots($coverage, $stationOutletId, $templates, $stationCode),
                ...$this->supportSlots($coverage, $requirements, $specs, $shifts, array_values(array_diff($targetOutletIds, [$stationOutletId]))),
            ],
            rules: Rules::fromConfig(
                array_merge(config('plugins.restaurant-roster.general.scheduling', []), $overrides),
                ShiftTransition::query()->get()->mapWithKeys(fn ($t) => ["{$t->from_template_id}:{$t->to_template_id}" => $t->rule])->all()
            ),
            hardUnavailable: $hard,
            softUnavailable: $soft,
            fixed: $fixed,
            baseline: $baseline,
            outletNames: $outlets->mapWithKeys(fn ($o) => [$o->id => $o->code ?: $o->name])->all(),
            regularOff: $regularOff,
            coverage: $coverage,
            stationOutletId: $stationOutletId,
            lobbyHistory: $this->lobbyHistory($employeeIds, $from, $stationOutletId, $historyWeeks),
            nightHistory: $this->nightHistory($employeeIds, $from, $templates, $historyWeeks),
            positionNames: Position::query()->pluck('name', 'id')->all(),
            outletParents: $this->outletParents($outlets),
            positionGroups: $this->positionGroups(),
            supportHistory: $supportHistory,
            shiftHistory: $shiftHistory,
            historyWeeks: $historyWeeks,
            nightPlan: $this->nightPlan($nightStaff, $from, $to),
        );
    }

    /**
     * Quy tắc ai được đứng khu trực thuộc (config scheduling.child_outlet_staff theo mã outlet).
     *
     * @return array<int, array{parent_id: int, gender: ?string, positions: string[]}>
     */
    private function childOutletStaffRules($outlets): array
    {
        $ids = $outlets->mapWithKeys(fn ($o) => [(string) $o->code => (int) $o->getKey()])->all();
        $parents = $this->outletParents($outlets);
        $rules = [];

        foreach (config('plugins.restaurant-roster.general.scheduling.child_outlet_staff', []) as $code => $rule) {
            $childId = $ids[$code] ?? null;

            if ($childId && isset($parents[$childId])) {
                $rules[$childId] = [
                    'parent_id' => $parents[$childId],
                    'gender' => $rule['gender'] ?? null,
                    'positions' => array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) ($rule['positions'] ?? [])),
                ];
            }
        }

        return $rules;
    }

    /**
     * @return array<int, int> [outletId khu] => outletId nhà hàng chủ quản (config scheduling.outlet_parents theo mã outlet)
     */
    /** @var array<string, array{source: string, ids: int[], warnings: string[]}> Kế hoạch luân phiên Night đã dùng ở lần build gần nhất (theo tuần) */
    public array $nightWeeks = [];

    /**
     * Kế hoạch luân phiên Night cho các ngày xếp lịch: tuần đã duyệt → đúng danh sách; chưa duyệt → đề xuất của thuật toán.
     */
    private function nightPlan(NightStaffService $nightStaff, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = $nightStaff->planFor($from, $to);
        $this->nightWeeks = $result['weeks'];

        foreach ($result['weeks'] as $week => $info) {
            foreach ($info['warnings'] as $warning) {
                $this->notes['night_rotation'][] = $warning;
            }

            if ($info['source'] === 'proposed') {
                $this->notes['night_rotation_proposed'][] = $week;
            }
        }

        return $result['plan'];
    }

    private function outletParents($outlets): array
    {
        $ids = $outlets->mapWithKeys(fn ($o) => [(string) $o->code => (int) $o->getKey()])->all();
        $parents = [];

        foreach (config('plugins.restaurant-roster.general.scheduling.outlet_parents', []) as $child => $parent) {
            if (isset($ids[$child], $ids[$parent])) {
                $parents[$ids[$child]] = $ids[$parent];
            }
        }

        return $parents;
    }

    /**
     * Nhóm vị trí dùng trong định biên: "cấp quản lý" (mọi vị trí bật cờ Cấp quản lý). Một vị trí đơn lẻ dùng khóa = position_id,
     * không cần khai báo nhóm.
     *
     * @return array<string, array{ids: int[], label: string}>
     */
    private function positionGroups(): array
    {
        return [RequirementResolver::MANAGER_GROUP => ['ids' => $this->managerPositionIds(), 'label' => 'Cấp quản lý (Junior Captain trở lên)']];
    }

    /**
     * @return int[] Vị trí bật "Cấp quản lý" (Junior Restaurant Captain trở lên).
     */
    private function managerPositionIds(): array
    {
        return $this->managerPositionIds ??= Position::query()->where('is_manager', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @var int[]|null */
    private ?array $managerPositionIds = null;

    /**
     * Số tuần có làm ca đêm của từng nhân viên trong 4 tuần ngay trước kỳ – để luân phiên ca Night theo tuần.
     *
     * @return array<int, int>
     */
    private function nightHistory(array $employeeIds, CarbonImmutable $from, $templates, int $weeks = 4): array
    {
        $nightIds = $templates->filter(fn (ShiftTemplate $t) => $this->shiftSpec($t)->category() === 'night')->pluck('id')->all();

        if (! $nightIds) {
            return [];
        }

        $nightWeeks = [];

        Shift::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('shift_template_id', $nightIds)
            ->whereDate('date', '>=', $from->subWeeks($weeks)->toDateString())->whereDate('date', '<=', $from->subDay()->toDateString())
            ->get(['employee_id', 'date'])
            ->each(function (Shift $shift) use (&$nightWeeks): void {
                $nightWeeks[(int) $shift->employee_id][$shift->date->format('o-W')] = true;
            });

        return array_map('count', $nightWeeks);
    }

    /**
     * Số lượt đi Lobby ((L) hoặc ca làm trọn ở Lobby) trung bình mỗi tuần của từng nhân viên trong 4 tuần ngay trước kỳ,
     * để luân phiên: người đã đi Lobby nhiều tuần trước được ưu tiên ở lại nhà hàng.
     *
     * @return array<int, float>
     */
    private function lobbyHistory(array $employeeIds, CarbonImmutable $from, ?int $stationOutletId, int $weeks = 4): array
    {
        if ($stationOutletId === null) {
            return [];
        }

        $history = [];

        Shift::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $from->subWeeks($weeks)->toDateString())->whereDate('date', '<=', $from->subDay()->toDateString())
            ->get(['employee_id', 'outlet_id', 'tags'])
            ->each(function (Shift $shift) use (&$history, $stationOutletId): void {
                if ((int) $shift->outlet_id === $stationOutletId || in_array('L', (array) $shift->tags, true)) {
                    $history[(int) $shift->employee_id] = ($history[(int) $shift->employee_id] ?? 0) + 1;
                }
            });

        return array_map(fn (int $count) => round($count / $weeks, 2), $history);
    }

    /**
     * Lịch sử phân công thực tế trong $weeks tuần ngay trước kỳ (theo các ô lịch còn lại, không tính ô đã xóa / đổi):
     * - lượt từng loại ca hỗ trợ: OH(L), M9(L), M6(L), A2(L) (có mặt ở Lobby), M6(K), A2(K) (đứng Kid-Deline);
     * - số ca sáng / chiều (để cân bằng M6 / A2 qua nhiều tuần).
     *
     * @return array{0: array<int, array<string, int>>, 1: array<int, array{morning: int, afternoon: int}>}
     */
    private function assignmentHistory(array $employeeIds, CarbonImmutable $from, int $weeks, ?int $stationOutletId, $outlets, $templates): array
    {
        $childOutlets = array_flip(array_keys($this->outletParents($outlets)));
        $specs = $templates->mapWithKeys(fn (ShiftTemplate $t) => [$t->getKey() => $this->shiftSpec($t)])->all();
        $support = [];
        $halves = [];

        Shift::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('kind', Shift::KIND_SHIFT)
            ->whereNotNull('shift_template_id')
            ->whereDate('date', '>=', $from->subWeeks($weeks)->toDateString())->whereDate('date', '<=', $from->subDay()->toDateString())
            ->get(['employee_id', 'outlet_id', 'shift_template_id', 'tags'])
            ->each(function (Shift $shift) use (&$support, &$halves, $specs, $childOutlets, $stationOutletId): void {
                $spec = $specs[$shift->shift_template_id] ?? null;

                if (! $spec) {
                    return;
                }

                $employeeId = (int) $shift->employee_id;
                $category = $spec->category();

                if ($category !== 'night') {
                    $halves[$employeeId][$category] = ($halves[$employeeId][$category] ?? 0) + 1;
                }

                $atStation = $stationOutletId !== null && (int) $shift->outlet_id === $stationOutletId;
                $kind = match (true) {
                    isset($childOutlets[(int) $shift->outlet_id]) => 'kid',
                    $atStation || (in_array('L', (array) $shift->tags, true) && $spec->lobbyWindow) => 'lobby',
                    default => null,
                };

                if ($kind) {
                    $key = \Botble\RestaurantRoster\Scheduling\Problem::supportTypeKey($spec->code, $kind);
                    $support[$employeeId][$key] = ($support[$employeeId][$key] ?? 0) + 1;
                }
            });

        foreach ($halves as &$counts) {
            $counts += ['morning' => 0, 'afternoon' => 0];
        }

        return [$support, $halves];
    }

    /**
     * Định biên phủ Lobby theo khung giờ cho từng ngày: dòng ngày cụ thể > theo thứ > chung (cả ngày theo một mức).
     * Số người là số người có mặt cùng lúc (ScheduleState chia khung thành các đoạn giờ, xem LobbyCoverage).
     *
     * @return Coverage[]
     */
    public function coverage(array $days, string $station, $templates): array
    {
        $idsByCode = $templates->mapWithKeys(fn (ShiftTemplate $t) => [strtoupper((string) $t->code) => (int) $t->getKey()])->all();
        $rows = CoverageRequirement::query()->where('station', $station)->get();
        $toMinutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $result = [];

        foreach ($days as $date) {
            $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;

            // Cả ngày theo một mức: có dòng ngày cụ thể thì chỉ dùng các dòng đó, không thì theo thứ, không thì dòng chung.
            $matching = $rows->filter(fn ($row) => $row->date ? $row->date->toDateString() === $date : (! $row->weekday || $row->weekday === $weekday));
            $rank = fn ($row) => $row->date ? 2 : ($row->weekday ? 1 : 0);
            $top = $matching->max($rank);

            foreach ($matching->filter(fn ($row) => $rank($row) === $top) as $row) {
                if ($row->min_staff > 0 || $row->target_staff > 0) {
                    $codes = $row->shift_code_list;
                    $shiftIds = $codes ? array_values(array_filter(array_map(fn ($code) => $idsByCode[$code] ?? null, $codes))) : null;
                    $label = ($codes ? 'Lobby (' . implode('/', $codes) . ')' : 'Lobby') . ($row->female_only ? ' – nữ waiter cùng lúc' : ' – cùng lúc');

                    $result[] = new Coverage($date, $toMinutes((string) $row->start_time), $toMinutes((string) $row->end_time),
                        (int) $row->min_staff, max((int) $row->min_staff, (int) $row->target_staff), $label, $shiftIds, (bool) $row->female_only);
                }
            }
        }

        return $result;
    }

    /**
     * Ô "sức chứa" cho ca làm trọn ở trạm (OH, M9) vào những ngày có định biên phủ Lobby.
     * Không tự tạo thiếu người – thiếu được tính ở khung giờ phủ.
     *
     * @return \Botble\RestaurantRoster\Scheduling\Requirement[]
     */
    private function stationSlots(array $coverage, ?int $stationOutletId, $templates, string $station): array
    {
        if (! $coverage || ! $stationOutletId) {
            return [];
        }

        $capacity = [];

        foreach ($coverage as $window) {
            $capacity[$window->date] = max($capacity[$window->date] ?? 0, $window->target);
        }

        $slots = [];

        foreach ($templates->where('support_station', $station) as $template) {
            foreach ($capacity as $date => $max) {
                $slots[] = new \Botble\RestaurantRoster\Scheduling\Requirement($date, $stationOutletId, $template->getKey(), 0, 0, $max);
            }
        }

        return $slots;
    }

    /**
     * Ô (L) đi hỗ trợ Lobby từ một outlet. M9(L) làm trọn ở Lobby: (L) chỉ là ký hiệu hiển thị, không phải cờ hỗ trợ.
     */
    private function isSupport(Shift $shift): bool
    {
        return in_array('L', $shift->tags ?: [], true) && ! $shift->template?->support_station;
    }

    /**
     * Chỗ "đi Lobby" cho ca có khung (L) được tính ở khung Lobby (vd OH(L) buổi sáng) tại outlet có người làm được ca đó,
     * vào những ngày outlet chưa có định biên cho ca này. Định biên 0 người: chỉ để xếp người (L) – không tính vào nhà hàng.
     *
     * @param \Botble\RestaurantRoster\Scheduling\Requirement[] $requirements
     * @param EmployeeSpec[] $employees
     * @param ShiftSpec[] $shifts
     * @return \Botble\RestaurantRoster\Scheduling\Requirement[]
     */
    private function supportSlots(array $coverage, array $requirements, array $employees, array $shifts, array $outletIds): array
    {
        $existing = [];

        foreach ($requirements as $requirement) {
            $existing[$requirement->key()] = true;
        }

        $lobbyShifts = [];

        foreach ($shifts as $shift) {
            if ($shift->lobbyWindow) {
                $lobbyShifts[$shift->id] = true;
            }
        }

        $slots = [];

        foreach ($coverage as $window) {
            // Khung không giới hạn ca (trống "Chỉ tính ca" – vd quy tắc 2 nữ waiter 09:00–16:45): mọi ca có khung (L), gồm OH(L).
            foreach ($window->shiftIds ?? array_keys($lobbyShifts) as $shiftId) {
                if (! isset($lobbyShifts[$shiftId])) {
                    continue;
                }

                foreach ($outletIds as $outletId) {
                    $key = "{$window->date}|{$outletId}|{$shiftId}";

                    if (isset($existing[$key]) || ! array_filter($employees, fn (EmployeeSpec $e) => $e->skillLevel((int) $outletId, $shiftId) !== null)) {
                        continue;
                    }

                    $existing[$key] = true;
                    $slots[] = new \Botble\RestaurantRoster\Scheduling\Requirement($window->date, (int) $outletId, $shiftId, 0, 0, 0);
                }
            }
        }

        return $slots;
    }

    public function shiftSpec(ShiftTemplate $template): ShiftSpec
    {
        $toMinutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $segment = function (string $start, string $end, int $after = 0) use ($toMinutes) {
            $s = $toMinutes($start);
            $e = $toMinutes($end);

            while ($s < $after) {
                $s += 1440;
                $e += 1440;
            }

            return [$s, $e <= $s ? $e + 1440 : $e];
        };

        $segments = [$segment($template->start_time, $template->end_time)];

        if ($template->isSplit()) {
            $segments[] = $segment($template->split_start_time, $template->split_end_time, $segments[0][1]);
        }

        $lobby = $template->lobby_start_time && $template->lobby_end_time
            ? $segment($template->lobby_start_time, $template->lobby_end_time)
            : null;

        return new ShiftSpec(
            $template->getKey(),
            (string) $template->code,
            $segments,
            (float) $template->hours,
            $lobby,
            $template->allowed_residence ?: null,
            $template->allowed_gender ?: null,
            $template->allowed_position_id ? (int) $template->allowed_position_id : null,
        );
    }

    /**
     * Quy định: OFF đúng ngày sinh nhật (như một ngày nghỉ đã duyệt, tính vào số ngày OFF của tuần).
     * Nếu quản lý đã KHÓA một ca làm vào ngày đó thì tôn trọng quyết định của quản lý.
     *
     * @param FixedAssignment[] $fixed
     */
    private function birthdayOffs($employees, array $days, array $fixed, array &$hard): array
    {
        $lockedWork = [];
        $regularOff = [];

        foreach ($fixed as $assignment) {
            if ($assignment->isWorking() && $assignment->source === 'locked') {
                $lockedWork[$assignment->employeeId][$assignment->date] = true;
            }
        }

        foreach ($employees as $employee) {
            foreach (BirthdayRule::offDates($employee->birthday, $days) as $date) {
                if (! isset($lockedWork[$employee->getKey()][$date])) {
                    $hard[$employee->getKey()][$date] ??= "Sinh nhật ({$employee->birthday}) – OFF theo quy định";
                    $regularOff[$employee->getKey()][$date] = true;
                }
            }
        }

        return $regularOff;
    }

    /**
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, string>>}
     */
    public function availability(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $labels = config('plugins.restaurant-roster.general.availability_types', []);
        $hard = [];
        $soft = [];

        $requests = AvailabilityRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('date_from', '<=', $to)
            ->whereDate('date_to', '>=', $from)
            ->get();

        // OFF quản lý đã cho được ghi thành ô khóa trên lịch: ô còn trên lịch mới chặn xếp ca
        // (quản lý "gỡ OFF" ô nào thì ngày đó xếp ca được, kể cả khi đơn còn ngày khác).
        $recorded = [];

        Shift::query()
            ->whereIn('availability_request_id', $requests->where('status', 'approved')->modelKeys())
            ->where('kind', '!=', Shift::KIND_SHIFT)
            ->get(['employee_id', 'date'])
            ->each(function (Shift $cell) use (&$recorded): void {
                $recorded[$cell->employee_id][$cell->date->toDateString()] = true;
            });

        foreach ($requests as $request) {
            $label = trim(($labels[$request->type] ?? $request->type) . ($request->group_label ? " – {$request->group_label}" : ''));

            foreach (CarbonPeriod::create(max($request->date_from, $from), min($request->date_to, $to)) as $day) {
                if ($request->status === 'approved') {
                    if (isset($recorded[$request->employee_id][$day->toDateString()])) {
                        $hard[$request->employee_id][$day->toDateString()] = $label . ' (quản lý đã cho OFF)';
                    }
                } else {
                    $soft[$request->employee_id][$day->toDateString()] ??= $label . ' (chờ duyệt)';
                }
            }
        }

        return [$hard, $soft];
    }

    /**
     * @return array{0: FixedAssignment[], 1: array<int, array<string, string>>}
     */
    private function existingShifts(array $employeeIds, array $decisionIds, CarbonImmutable $from, CarbonImmutable $to, array $days, array &$hard): array
    {
        $fixed = [];
        $baseline = [];
        $inScope = array_flip($days);
        $seen = [];

        $shifts = Shift::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$from->subDays(7)->toDateString(), $to->addDay()->toDateString()])
            ->with('template')
            ->get();

        foreach ($shifts as $shift) {
            $date = $shift->date->toDateString();
            $employeeId = $shift->employee_id;
            $seen[$employeeId][$date] = true;
            $working = $shift->kind === Shift::KIND_SHIFT && $shift->shift_template_id;
            $isDecision = isset($decisionIds[$employeeId]) && isset($inScope[$date]);

            if ($shift->kind === Shift::KIND_LEAVE && isset($inScope[$date])) {
                $hard[$employeeId][$date] ??= 'Nghỉ phép ' . $shift->leave_type . ' (trong lịch)';
            }

            if ($isDecision && ! $shift->is_locked && $shift->kind !== Shift::KIND_LEAVE) {
                if ($working) {
                    $baseline[$employeeId][$date] = ScheduleState::slotKey((int) $shift->outlet_id, (int) $shift->shift_template_id, $this->isSupport($shift));
                }

                continue;
            }

            $fixed[] = new FixedAssignment(
                employeeId: $employeeId,
                date: $date,
                kind: $working ? FixedAssignment::KIND_SHIFT : ($shift->kind === Shift::KIND_LEAVE ? FixedAssignment::KIND_LEAVE : FixedAssignment::KIND_OFF),
                outletId: $working ? $shift->outlet_id : null,
                shiftId: $working ? $shift->shift_template_id : null,
                overtimeHours: (float) $shift->overtime_hours,
                source: isset($inScope[$date]) ? ($shift->is_locked ? 'locked' : 'frozen') : 'context',
                support: $working && $this->isSupport($shift),
            );
        }

        // Nhân viên không thuộc phạm vi xếp: ô trống trong phạm vi được coi là OFF cố định.
        foreach ($employeeIds as $employeeId) {
            if (isset($decisionIds[$employeeId])) {
                continue;
            }

            foreach ($days as $date) {
                if (! isset($seen[$employeeId][$date])) {
                    $fixed[] = new FixedAssignment($employeeId, $date, FixedAssignment::KIND_OFF, source: 'frozen');
                }
            }
        }

        return [$fixed, $baseline];
    }

    /**
     * @return \Botble\RestaurantRoster\Scheduling\Requirement[]
     */
    private function requirements(array $days, array $outletIds, array $templateIds): array
    {
        // Định biên đọc trực tiếp từ database (Định biên nhân sự): chỉ dòng đang áp dụng, còn hiệu lực, kèm nhóm theo vị trí.
        $resolver = new RequirementResolver(StaffingRequirementService::resolverRows(
            StaffingRequirement::query()->whereIn('outlet_id', $outletIds)->where('status', StaffingRequirement::STATUS_ACTIVE)
        ));

        $flags = OutletDay::query()
            ->whereIn('outlet_id', $outletIds)
            ->whereDate('date', '>=', reset($days))->whereDate('date', '<=', end($days))
            ->get()
            ->keyBy(fn ($d) => $d->outlet_id . '|' . $d->date->toDateString());

        $requirements = [];
        $busyWeekdays = array_map('intval', config('plugins.restaurant-roster.general.scheduling.busy_weekdays', [6]));
        $busyExtra = (int) config('plugins.restaurant-roster.general.scheduling.busy_day_extra_capacity', 1);
        $flex = (int) config('plugins.restaurant-roster.general.scheduling.flex_capacity', 2);

        foreach ($days as $date) {
            $busy = $busyExtra > 0 && in_array(CarbonImmutable::parse($date)->dayOfWeekIso, $busyWeekdays, true);

            foreach ($outletIds as $outletId) {
                $flag = $flags[$outletId . '|' . $date] ?? null;

                foreach ($templateIds as $templateId) {
                    $requirement = $resolver->resolve($date, $outletId, $templateId, (bool) $flag?->upper_floor_open, (bool) $flag?->is_closed);

                    if ($requirement && ($requirement->min > 0 || $requirement->target > 0 || $requirement->minMale > 0 || $requirement->minFemale > 0 || $requirement->positionMins)) {
                        // Ngày đông khách: mỗi outlet × ca nhận thêm tối đa N người trên mục tiêu để bớt người OFF hôm đó.
                        // (không áp cho ô đã đặt sức chứa tối đa riêng, vd Kid-Deline đúng 1 người).
                        // Ô không đặt sức chứa riêng: nhận thêm người trên mục tiêu để ai cũng đủ ngày làm (thứ Bảy thêm nữa).
                        $max = $requirement->target > 0 && $requirement->max === null
                            ? $requirement->target + max($flex, $busy ? $busyExtra : 0)
                            : null;

                        $requirements[] = $requirement->adjusted($max);

                        if ($requirement->sourceId) {
                            $this->usedRequirementIds[$requirement->sourceId] = true;
                        }
                    }
                }
            }
        }

        $this->conflicts = array_values($resolver->conflicts());

        return $requirements;
    }

    /** @var array<int, array{date: string, outlet_id: int, shift_id: int, ids: int[]}> Xung đột định biên của lần dựng gần nhất */
    public array $conflicts = [];

    /** @var array<int, true> Dòng định biên đã dùng trong lần dựng gần nhất (để lưu kèm lần chạy) */
    public array $usedRequirementIds = [];
}
