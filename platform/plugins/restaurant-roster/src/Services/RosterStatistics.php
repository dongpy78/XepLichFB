<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Thống kê nhân sự dùng chung cho Bảng xếp ca và trang Thống kê nhân sự:
 * cơ cấu nhân viên (nam/nữ, quản lý, nơi ở, ca cứng, ngày OFF) và số người đi làm theo ngày × outlet × ca,
 * đối chiếu quy định mỗi ca (có nam, Hostess, quản lý – config scheduling.staffing_rules).
 */
class RosterStatistics
{
    private ?array $rules = null;

    /**
     * @return array{leaderIds: int[], hostessIds: int[], femaleStaffIds: int[], checks: array<string, bool>}
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        // Cấp quản lý theo cờ "Cấp quản lý" của vị trí (Junior Restaurant Captain trở lên).
        $leaders = Position::query()->where('is_manager', true)->get(['id', 'name']);
        $leaderIds = $leaders->map(fn ($p) => (int) $p->id)->all();
        // Incharge: cấp quản lý trừ Assistant Manager, Restaurant Manager – cảnh báo lệch sáng / chiều chỉ tính nhóm này.
        $topManagers = array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config('plugins.restaurant-roster.general.scheduling.incharge_exclude_positions', []));
        $inChargeIds = $leaders->reject(fn ($p) => in_array(mb_strtolower(trim((string) $p->name)), $topManagers, true))->map(fn ($p) => (int) $p->id)->values()->all();
        $hostessIds = [];
        $checks = [];

        // Ca được kiểm tra "có nam / Hostess / quản lý": outlet × ca có dòng định biên đang áp dụng đặt nam hoặc nhóm vị trí.
        $outletCodes = \Botble\RestaurantRoster\Models\Outlet::query()->pluck('code', 'id');
        $templateCodes = \Botble\RestaurantRoster\Models\ShiftTemplate::query()->pluck('code', 'id');

        \Botble\RestaurantRoster\Models\StaffingRequirement::query()
            ->with('positions')
            ->where('status', \Botble\RestaurantRoster\Models\StaffingRequirement::STATUS_ACTIVE)
            ->when($this->balanceMode(), fn ($q) => $q->whereIn('outlet_id', app(ProblemBuilder::class)->staffedOutletIds()))
            ->get()
            ->each(function ($row) use (&$checks, &$hostessIds, $outletCodes, $templateCodes): void {
                if ((int) $row->min_male > 0 || $row->positions->isNotEmpty()) {
                    $checks[($outletCodes[$row->outlet_id] ?? '') . '|' . ($templateCodes[$row->shift_template_id] ?? '')] = true;
                }

                foreach ($row->positions as $position) {
                    if ($position->kind === 'position' && $position->position_id) {
                        $hostessIds[] = (int) $position->position_id;
                    }
                }
            });

        $hostessIds = array_values(array_unique($hostessIds));

        // Nữ waiter thông thường (định biên nữ waiter trực tiếp): vị trí trong scheduling.female_staff_positions, không thuộc cấp quản lý.
        $femaleNames = array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config('plugins.restaurant-roster.general.scheduling.female_staff_positions', ['Waiter']));
        $femaleStaffIds = Position::query()->get(['id', 'name'])
            ->filter(fn ($p) => ! in_array((int) $p->id, $leaderIds, true) && (! $femaleNames || in_array(mb_strtolower(trim((string) $p->name)), $femaleNames, true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $maleNames = array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config('plugins.restaurant-roster.general.scheduling.male_staff_positions', ['Waiter']));
        $maleStaffIds = Position::query()->get(['id', 'name'])
            ->filter(fn ($p) => ! in_array((int) $p->id, $leaderIds, true) && (! $maleNames || in_array(mb_strtolower(trim((string) $p->name)), $maleNames, true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->rules = ['leaderIds' => $leaderIds, 'inChargeIds' => $inChargeIds, 'hostessIds' => $hostessIds, 'femaleStaffIds' => $femaleStaffIds, 'maleStaffIds' => $maleStaffIds, 'checks' => $checks];
    }

    /**
     * Định biên phủ Lobby theo số người CÓ MẶT CÙNG LÚC cho từng ngày của tuần (đúng cách bộ giải đếm – LobbyCoverage):
     * người làm trọn ca ở Lobby (M9) có mặt cả ca, người có (L) có mặt trong khung (L) của mẫu ca (OH(L), M6(L), A2(L)).
     * Đọc mọi ca của tuần (không theo bộ lọc outlet của bảng) để không báo thiếu sai.
     *
     * @return array<string, array{min: int, min_count: int, short: string[], segments: string[], present: string[]}>
     */
    public function lobbyCoverage(Carbon $weekStart): array
    {
        $station = (string) config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY');
        $stationOutletId = \Botble\RestaurantRoster\Models\Outlet::query()->where('code', $station)->value('id');
        $templates = \Botble\RestaurantRoster\Models\ShiftTemplate::query()->get();
        $days = array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($weekStart, $weekStart->copy()->addDays(6))));
        $builder = app(ProblemBuilder::class);
        $coverage = $builder->coverage($days, $station, $templates);

        if (! $coverage) {
            return [];
        }

        $specs = $templates->mapWithKeys(fn ($t) => [$t->getKey() => $builder->shiftSpec($t)]);
        $stationCodes = $templates->where('support_station', $station)->pluck('id');
        $femaleStaffIds = $this->rules()['femaleStaffIds'];
        $presence = [];

        Shift::query()
            ->whereDate('date', '>=', $days[0])->whereDate('date', '<=', $days[6])
            ->with(['employee:id,name,gender,position_id,is_trainee'])
            ->get()
            ->each(function (Shift $shift) use (&$presence, $specs, $stationOutletId, $stationCodes, $femaleStaffIds): void {
                $spec = $specs[$shift->shift_template_id] ?? null;

                if (! $spec || ! $shift->isWorking() || ! $shift->employee || $shift->employee->is_trainee) {
                    return;
                }

                // Ca làm trọn ở Lobby: xếp vào outlet Lobby, hoặc mẫu ca thuộc trạm Lobby (M9).
                $atStation = ($stationOutletId && (int) $shift->outlet_id === (int) $stationOutletId) || $stationCodes->contains($shift->shift_template_id);
                $interval = $atStation ? [$spec->start(), $spec->end()]
                    : (in_array('L', $shift->tags ?: [], true) ? $spec->lobbyWindow : null);

                if ($interval) {
                    $presence[$shift->date->toDateString()][] = [
                        'interval' => $interval,
                        'shift_id' => (int) $shift->shift_template_id,
                        'female_staff' => $shift->employee->gender === 'female' && in_array((int) $shift->employee->position_id, $femaleStaffIds, true),
                        'name' => $shift->employee->name . ' ' . $spec->code . ($atStation ? '' : '(L)'),
                    ];
                }
            });

        $result = [];

        foreach ($coverage as $window) {
            $people = array_values(array_filter($presence[$window->date] ?? [], fn ($p) => (! $window->femaleOnly || $p['female_staff'])
                && ($window->shiftIds === null || in_array($p['shift_id'], $window->shiftIds, true))));
            $check = \Botble\RestaurantRoster\Scheduling\LobbyCoverage::evaluate($window->start, $window->end, $window->min, array_column($people, 'interval'));
            $time = fn ($row) => \Botble\RestaurantRoster\Scheduling\LobbyCoverage::time($row['start']) . '–' . \Botble\RestaurantRoster\Scheduling\LobbyCoverage::time($row['end']);
            $day = $result[$window->date] ?? ['min' => 0, 'min_count' => PHP_INT_MAX, 'short' => [], 'segments' => [], 'present' => []];

            $day['min'] = max($day['min'], $window->min);
            $day['min_count'] = min($day['min_count'], $check['min_count']);
            $day['present'] = array_values(array_unique([...$day['present'], ...array_column($people, 'name')]));

            foreach ($check['segments'] as $row) {
                $day['segments'][] = $time($row) . ": {$row['count']}/{$window->min}";
            }

            foreach ($check['short'] as $row) {
                $day['short'][] = 'Lobby ' . $time($row) . ": {$row['count']}/{$window->min} " . ($window->femaleOnly ? 'nữ waiter' : 'người') . ' cùng lúc';
            }

            $result[$window->date] = $day;
        }

        return $result;
    }

    /** Chế độ "balance": nhà hàng thông thường không còn định biên → không cảnh báo thiếu theo định biên cũ của các outlet đó. */
    private function balanceMode(): bool
    {
        return config('plugins.restaurant-roster.general.scheduling.staffing_mode', 'balance') === 'balance';
    }

    public function summarize(Collection $employees): array
    {
        $leaderIds = $this->rules()['leaderIds'];
        $leaders = $employees->filter(fn ($e) => in_array((int) $e->position_id, $leaderIds, true));

        return [
            'total' => $employees->count(),
            'male' => $employees->where('gender', 'male')->count(),
            'female' => $employees->where('gender', 'female')->count(),
            'no_gender' => $employees->whereNull('gender')->count(),
            'leaders' => $leaders->count(),
            'leaders_male' => $leaders->where('gender', 'male')->count(),
            'nha_trang' => $employees->where('residence', 'nha_trang')->count(),
            'cam_ranh' => $employees->where('residence', 'cam_ranh')->count(),
            'no_residence' => $employees->whereNull('residence')->count(),
            'fixed' => $employees->filter(fn ($e) => $e->fixedShift)->groupBy(fn ($e) => $e->fixedShift->code)->map->count()->all(),
            'days_off' => $employees->groupBy('days_off_per_week')->map->count()->sortKeys()->all(),
            'manual' => $employees->where('auto_schedule', false)->count(),
        ];
    }

    /**
     * Định biên nữ waiter trực tiếp theo ngày × outlet × mã ca – chọn dòng định biên như bộ giải (ngày cụ thể > thứ > chung, tầng trên).
     *
     * @param string[] $dates
     * @return array<string, array<int, array<string, array{0: int, 1: int}>>>
     */
    private function femaleMinimums(array $dates, Collection $outlets): array
    {
        $rows = \Botble\RestaurantRoster\Models\StaffingRequirement::query()
            ->where('status', \Botble\RestaurantRoster\Models\StaffingRequirement::STATUS_ACTIVE)
            ->whereIn('outlet_id', $outlets->pluck('id'))
            ->when($this->balanceMode(), fn ($q) => $q->whereIn('outlet_id', app(ProblemBuilder::class)->staffedOutletIds()))
            ->where(fn ($q) => $q->where('min_female', '>', 0)->orWhere('target_female', '>', 0))
            ->get();

        if ($rows->isEmpty() || ! $dates) {
            return [];
        }

        $resolver = new \Botble\RestaurantRoster\Scheduling\RequirementResolver(StaffingRequirementService::resolverRows(\Botble\RestaurantRoster\Models\StaffingRequirement::query()
            ->where('status', \Botble\RestaurantRoster\Models\StaffingRequirement::STATUS_ACTIVE)
            ->whereIn('outlet_id', $rows->pluck('outlet_id')->unique())));
        $flags = \Botble\RestaurantRoster\Models\OutletDay::query()
            ->whereIn('outlet_id', $rows->pluck('outlet_id')->unique())
            ->whereDate('date', '>=', min($dates))->whereDate('date', '<=', max($dates))
            ->get()->keyBy(fn ($d) => $d->outlet_id . '|' . $d->date->toDateString());
        $codes = \Botble\RestaurantRoster\Models\ShiftTemplate::query()->pluck('code', 'id');
        $result = [];

        foreach ($dates as $date) {
            foreach ($rows->map(fn ($r) => [(int) $r->outlet_id, (int) $r->shift_template_id])->unique(fn ($p) => implode('|', $p)) as [$outletId, $templateId]) {
                $flag = $flags[$outletId . '|' . $date] ?? null;
                $requirement = $resolver->resolve($date, $outletId, $templateId, (bool) $flag?->upper_floor_open, (bool) $flag?->is_closed);

                if ($requirement && ($requirement->minFemale > 0 || $requirement->targetFemale > 0)) {
                    $result[$date][$outletId][(string) ($codes[$templateId] ?? '')] = [$requirement->minFemale, $requirement->targetFemale];
                }
            }
        }

        return $result;
    }

    /**
     * Thống kê nhân sự cuối Bảng xếp ca: mỗi nhà hàng một khối (tổng, Incharge, Quản lý, Hostess, Waiter nữ / nam, hỗ trợ Lobby, người từ nơi khác sang, OFF),
     * rồi các khu vực khác và tổng toàn bộ. Người tính theo nơi làm thực tế.
     * Nhà hàng đóng cửa: ngày đó hiện "Đóng cửa", OFF của nhân viên nhà hàng đó tính sang nhà hàng còn mở; đóng cả tuần thì chỉ còn khối nhà hàng mở, có dòng "Từ <nhà hàng đóng>".
     *
     * @param string[] $dates
     * @param array $status RosterController::restaurantStatus()['outlets']
     */
    public function board(Collection $shifts, Collection $outlets, array $dates, array $onDuty, array $status = []): array
    {
        $rules = $this->rules();
        $templates = \Botble\RestaurantRoster\Models\ShiftTemplate::query()->orderBy('start_time')->get(['code', 'color', 'start_time']);
        $codeOrder = $templates->pluck('code')->values()->flip()->all();
        $names = fn (string $key) => array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config("plugins.restaurant-roster.general.scheduling.{$key}", []));
        $balancedNames = $names('balance_positions');
        $hostessIds = Position::query()->get(['id', 'name'])
            ->filter(fn ($p) => in_array(mb_strtolower(trim((string) $p->name)), $balancedNames, true))->map(fn ($p) => (int) $p->id)->values()->all();
        $labelOf = fn ($outlet) => (string) ($outlet->code ?: $outlet->name);
        $transfer = (array) config('plugins.restaurant-roster.general.scheduling.transfer_outlets', ['GUSTO', 'VILLA']);
        $restaurants = $outlets->filter(fn ($o) => in_array($o->code, $transfer, true))->keyBy('id');
        $byId = $outlets->keyBy('id');
        $lobbyId = (int) ($outlets->firstWhere('code', config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY'))?->id ?? 0);
        $isClosed = fn (int $id, string $date) => (bool) ($status[$id]['days'][$date]['closed'] ?? false);
        $openBy = function (int $home, string $date) use ($restaurants, $isClosed): int {
            if (! $restaurants->has($home) || ! $isClosed($home, $date)) {
                return $home;
            }

            foreach ($restaurants->keys() as $id) {
                if (! $isClosed((int) $id, $date)) {
                    return (int) $id;
                }
            }

            return $home;
        };

        $roles = [];
        $from = [];
        $other = [];
        $absent = [];
        $trainee = [];
        $grand = [];

        foreach ($shifts as $shift) {
            $employee = $shift->employee;
            $date = $shift->date->toDateString();

            if (! $employee || ! in_array($date, $dates, true)) {
                continue;
            }

            $home = (int) $employee->outlet_id;
            $code = (string) ($shift->template?->code ?? 'Ca');
            $working = $shift->isWorking();
            $absentKind = $shift->kind === Shift::KIND_LEAVE ? 'leave' : 'off';

            if ($employee->is_trainee) {
                $working ? $trainee[$date]['codes'][$code] = ($trainee[$date]['codes'][$code] ?? 0) + 1
                    : $trainee[$date]['absent'][$absentKind] = ($trainee[$date]['absent'][$absentKind] ?? 0) + 1;

                continue;
            }

            if (! $working) {
                $grand[$date][$absentKind] = ($grand[$date][$absentKind] ?? 0) + 1;
                $to = $openBy($home, $date);
                $absent[$to][$date][$absentKind] = ($absent[$to][$date][$absentKind] ?? 0) + 1;

                continue;
            }

            $grand[$date]['work'] = ($grand[$date]['work'] ?? 0) + 1;
            $outletId = (int) ($shift->outlet_id ?: $home);
            $position = (int) $employee->position_id;
            $isMale = $employee->gender === 'male';

            // Hỗ trợ Lobby (L): làm ca của nhà hàng nhưng lên quầy Lobby trong khung giờ, tính riêng như định biên.
            if (in_array('L', $shift->tags ?: [], true) && ! $shift->template?->support_station) {
                $roles[$outletId]['lobby'][$date][$code] = ($roles[$outletId]['lobby'][$date][$code] ?? 0) + 1;
                $other[$lobbyId][$date][$code] = ($other[$lobbyId][$date][$code] ?? 0) + 1;

                continue;
            }

            if (! $restaurants->has($outletId)) {
                $other[$outletId][$date][$code] = ($other[$outletId][$date][$code] ?? 0) + 1;

                // Làm trọn ca ở Lobby (M9(L)): vẫn hiện ở dòng Lobby của nhà hàng gốc (nhà hàng gốc đóng cửa thì nhà hàng đang mở).
                if ($outletId === $lobbyId && $restaurants->has($home)) {
                    $to = $openBy($home, $date);
                    $roles[$to]['lobby'][$date][$code] = ($roles[$to]['lobby'][$date][$code] ?? 0) + 1;
                }

                continue;
            }

            $role = match (true) {
                in_array($position, $rules['inChargeIds'], true) => 'incharge',
                in_array($position, $rules['leaderIds'], true) => 'manager',
                in_array($position, $hostessIds, true) => 'hostess',
                $isMale && in_array($position, $rules['maleStaffIds'], true) => 'waiter_male',
                ! $isMale && in_array($position, $rules['femaleStaffIds'], true) => 'waiter_female',
                default => 'other',
            };
            $roles[$outletId][$role][$date][$code] = ($roles[$outletId][$role][$date][$code] ?? 0) + 1;

            if ($home && $home !== $outletId) {
                $from[$outletId][$home][$date][$code] = ($from[$outletId][$home][$date][$code] ?? 0) + 1;
            }
        }

        $sorted = function (array $codes) use ($codeOrder): array {
            uksort($codes, fn ($a, $b) => [$codeOrder[$a] ?? 999, $a] <=> [$codeOrder[$b] ?? 999, $b]);

            return $codes;
        };
        $problemsOf = function (int $outletId, string $date) use ($onDuty): array {
            $list = [];

            foreach ($onDuty['problems'][$date][$outletId] ?? [] as $code => $problems) {
                array_push($list, ...$problems);
            }

            return array_values(array_unique($list));
        };
        // Định biên nữ waiter trực tiếp (nếu có cấu hình): NW đang có / tối thiểu.
        $notesOf = function (int $outletId, string $date) use ($onDuty): array {
            $notes = [];

            foreach ($onDuty['female_rules'][$date][$outletId] ?? [] as $code => [$min]) {
                $notes[] = "{$code}: NW" . ($onDuty['cells'][$date][$outletId][$code]['female_staff'] ?? 0) . "/{$min}";
            }

            return $notes;
        };
        $balanceCodes = array_map('strtoupper', (array) config('plugins.restaurant-roster.general.scheduling.open_shift_codes', ['M6', 'A2']));
        [$am, $pm] = [$balanceCodes[0] ?? 'M6', $balanceCodes[1] ?? 'A2'];
        $hasData = fn (int $id) => ! empty($roles[$id]) || ! empty($absent[$id]);
        $blocks = [];

        foreach ($restaurants as $id => $outlet) {
            $id = (int) $id;

            if (! $hasData($id)) {
                continue;
            }

            $closedAll = $restaurants->keys()->reject(fn ($o) => (int) $o === $id)
                ->filter(fn ($o) => collect($dates)->every(fn ($d) => $isClosed((int) $o, $d)))
                ->map(fn ($o) => $labelOf($restaurants[$o]))->values()->all();
            $rows = [];

            foreach ([
                'incharge' => ['Incharge', 'Junior Captain → Senior Supervisor', true, true],
                'manager' => ['Quản lý', 'Assistant, Restaurant Manager', false, false],
                'hostess' => ['Hostess', null, true, true],
                'waiter_female' => ['Waiter nữ', null, true, true],
                'waiter_male' => ['Waiter nam', 'không tính quản lý', true, true],
                'other' => ['Vị trí khác', null, false, false],
                'lobby' => ['Lên Lobby (L)', 'M9(L), OH(L), M6(L)… chưa tính vào tổng', true, false],
            ] as $key => [$label, $hint, $always, $balance]) {
                $data = $roles[$id][$key] ?? [];

                if (! $always && ! $data) {
                    continue;
                }

                $warn = [];

                foreach ($balance ? $data : [] as $date => $codes) {
                    [$morning, $afternoon] = [$codes[$am] ?? 0, $codes[$pm] ?? 0];

                    if (\Botble\RestaurantRoster\Scheduling\ScheduleState::managerPenalty($morning, $afternoon) > 0) {
                        $warn[$date] = "{$label} lệch: {$am} {$morning} / {$pm} {$afternoon}";
                    }
                }

                $rows[] = ['label' => $label, 'hint' => $hint, 'cells' => array_map($sorted, $data), 'warn' => $warn];
            }

            foreach ($from[$id] ?? [] as $home => $data) {
                $rows[] = ['label' => 'Từ ' . ($byId->has($home) ? $labelOf($byId[$home]) : '#' . $home), 'hint' => 'đã tính trong các dòng trên', 'cells' => array_map($sorted, $data), 'warn' => []];
            }

            $total = [];

            foreach ($dates as $date) {
                $codes = [];

                foreach (($roles[$id] ?? []) as $key => $data) {
                    if ($key !== 'lobby') {
                        foreach ($data[$date] ?? [] as $code => $count) {
                            $codes[$code] = ($codes[$code] ?? 0) + $count;
                        }
                    }
                }

                $total[$date] = ['count' => array_sum($codes), 'codes' => $sorted($codes), 'closed' => $isClosed($id, $date), 'problems' => $problemsOf($id, $date), 'notes' => $notesOf($id, $date)];
            }

            $blocks[] = [
                'code' => $labelOf($outlet),
                'hint' => $closedAll ? 'gồm nhân viên ' . implode(', ', $closedAll) . ' – ' . implode(', ', $closedAll) . ' đóng cửa cả tuần' : 'tổng tại nhà hàng',
                'total' => $total,
                'rows' => $rows,
                'absent' => $absent[$id] ?? [],
                'absent_hint' => $closedAll ? 'nhân viên ' . implode(' + ', [$labelOf($outlet), ...$closedAll]) : 'nhân viên nhà hàng',
            ];
        }

        $others = [];

        foreach ($outlets as $outlet) {
            $id = (int) $outlet->id;

            if ($restaurants->has($id) || (empty($other[$id]) && empty($absent[$id]))) {
                continue;
            }

            $cells = [];

            foreach ($dates as $date) {
                $cells[$date] = ['codes' => $sorted($other[$id][$date] ?? []), 'absent' => $absent[$id][$date] ?? [], 'problems' => $problemsOf($id, $date), 'notes' => $notesOf($id, $date)];
            }

            $others[] = ['label' => $labelOf($outlet), 'hint' => $id === $lobbyId ? 'gồm người hỗ trợ (L)' : null, 'cells' => $cells];
        }

        if ($trainee) {
            $cells = [];

            foreach ($dates as $date) {
                $cells[$date] = ['codes' => $sorted($trainee[$date]['codes'] ?? []), 'absent' => $trainee[$date]['absent'] ?? [], 'problems' => [], 'notes' => []];
            }

            $others[] = ['label' => 'TRAINEE', 'hint' => 'không tính định biên', 'cells' => $cells];
        }

        return [
            'has_shifts' => $shifts->isNotEmpty(),
            'colors' => $templates->pluck('color', 'code')->all(),
            'restaurants' => $blocks,
            'others' => $others,
            'grand' => $grand,
        ];
    }

    /**
     * Người đi làm theo ngày × outlet làm việc × ca trong tuần (người đi Lobby (L) tính riêng),
     * kèm số OFF / nghỉ phép theo outlet chính và các ô vi phạm quy định.
     */
    public function onDuty(Carbon $weekStart, Collection $outlets, ?Collection $shifts = null): array
    {
        $rules = $this->rules();
        $end = $weekStart->copy()->addDays(6);
        $shifts ??= Shift::query()
            ->whereBetween('date', [$weekStart->toDateString(), $end->toDateString()])
            ->with(['employee:id,gender,position_id,outlet_id,is_trainee', 'template:id,code,start_time,support_station'])
            ->get();

        $days = [];

        foreach (CarbonPeriod::create($weekStart, $end) as $date) {
            $days[$date->toDateString()] = $date->copy();
        }

        $codes = [];
        $cells = [];
        $absent = [];
        $managerSplit = [];

        foreach ($shifts as $shift) {
            $date = $shift->date->toDateString();
            $employee = $shift->employee;

            // Thực tập sinh không được tính vào định biên.
            if (! $employee || ! isset($days[$date]) || $employee->is_trainee) {
                continue;
            }

            if (! $shift->isWorking()) {
                $kind = $shift->kind === Shift::KIND_LEAVE ? 'leave' : 'off';
                $absent[$date][$employee->outlet_id][$kind] = ($absent[$date][$employee->outlet_id][$kind] ?? 0) + 1;

                continue;
            }

            $code = $shift->template?->code ?? 'Ca';
            $codes[$shift->outlet_id][$code] = $shift->template?->start_time ?? '99';
            $support = in_array('L', $shift->tags ?: [], true) && ! $shift->template?->support_station;
            $cell = &$cells[$date][$shift->outlet_id][$code];
            $cell ??= ['total' => 0, 'male' => 0, 'male_staff' => 0, 'female' => 0, 'female_staff' => 0, 'leaders' => 0, 'hostess' => 0, 'lobby' => 0];

            if ($support) {
                $cell['lobby']++;
            } else {
                $cell['total']++;
                $cell[$employee->gender === 'male' ? 'male' : 'female']++;
                $cell['leaders'] += in_array((int) $employee->position_id, $rules['leaderIds'], true) ? 1 : 0;

                if (in_array((int) $employee->position_id, $rules['inChargeIds'], true) && $shift->template?->start_time) {
                    $hour = (int) substr((string) $shift->template->start_time, 0, 2);
                    $half = $hour >= 4 && $hour < 12 ? 'morning' : ($hour >= 12 && $hour < 20 ? 'afternoon' : null);

                    if ($half) {
                        $managerSplit[$date][$shift->outlet_id][$half] = ($managerSplit[$date][$shift->outlet_id][$half] ?? 0) + 1;
                    }
                }
                $cell['hostess'] += in_array((int) $employee->position_id, $rules['hostessIds'], true) ? 1 : 0;
                $cell['female_staff'] += $employee->gender === 'female' && in_array((int) $employee->position_id, $rules['femaleStaffIds'], true) ? 1 : 0;
                $cell['male_staff'] += $employee->gender === 'male' && in_array((int) $employee->position_id, $rules['maleStaffIds'], true) ? 1 : 0;
            }

            unset($cell);
        }

        // Mỗi outlet chỉ hiện các ca có người làm trong tuần, theo giờ bắt đầu; ca thuộc quy định luôn hiện.
        foreach ($outlets as $outlet) {
            foreach (array_keys($rules['checks']) as $check) {
                [$outletCode, $shiftCode] = explode('|', $check);

                if ($outletCode === $outlet->code && ! isset($codes[$outlet->id][$shiftCode])) {
                    $codes[$outlet->id][$shiftCode] = '00';
                }
            }
        }

        $codes = array_map(function (array $list) {
            asort($list);

            return array_keys($list);
        }, $codes);

        $problems = [];

        // Nữ waiter trực tiếp dưới định biên tối thiểu (Định biên nhân sự → Nữ waiter tối thiểu) – người hỗ trợ Lobby (L) / Kid không tính.
        foreach ($this->femaleMinimums(array_keys($days), $outlets) as $date => $byOutlet) {
            foreach ($byOutlet as $outletId => $byCode) {
                foreach ($byCode as $code => [$min, $target]) {
                    $have = $cells[$date][$outletId][$code]['female_staff'] ?? 0;

                    if ($have < $min) {
                        $problems[$date][$outletId][$code][] = "thiếu nữ waiter: {$have}/{$min} tối thiểu";
                    }

                    $femaleRules[$date][$outletId][$code] = [$min, $target];

                    if (! in_array($code, $codes[$outletId] ?? [], true)) {
                        $codes[$outletId][] = $code;
                    }
                }
            }
        }

        // Cấp quản lý: số quản lý ca sáng / chiều lệch quá mức không tránh được (vd 4 sáng / 1 chiều) → báo ở các ca của outlet hôm đó.
        foreach ($managerSplit as $date => $byOutlet) {
            foreach ($byOutlet as $outletId => $split) {
                [$morning, $afternoon] = [$split['morning'] ?? 0, $split['afternoon'] ?? 0];

                if (\Botble\RestaurantRoster\Scheduling\ScheduleState::managerPenalty($morning, $afternoon) > 0) {
                    foreach ($codes[$outletId] ?? [] as $code) {
                        $problems[$date][$outletId][$code][] = "quản lý lệch: sáng {$morning} / chiều {$afternoon}";
                    }
                }
            }
        }

        foreach ($shifts->isEmpty() ? [] : $outlets as $outlet) {
            foreach ($codes[$outlet->id] ?? [] as $code) {
                if (! isset($rules['checks'][$outlet->code . '|' . $code])) {
                    continue;
                }

                foreach (array_keys($days) as $date) {
                    $cell = $cells[$date][$outlet->id][$code] ?? null;
                    $list = $cell ? array_values(array_filter([
                        $cell['male_staff'] ? null : 'không có nam waiter',
                        $cell['hostess'] ? null : 'không có Hostess',
                        $cell['leaders'] ? null : 'không có quản lý',
                    ])) : ['không có ai'];

                    if ($list) {
                        $problems[$date][$outlet->id][$code] = [...$list, ...($problems[$date][$outlet->id][$code] ?? [])];
                    }
                }
            }
        }

        return [
            'days' => $days,
            'codes' => $codes,
            'cells' => $cells,
            'absent' => $absent,
            'problems' => $problems,
            // [date][outletId][mã ca] => [nữ waiter tối thiểu, mục tiêu] – ô có cấu hình thì hiện số nữ waiter trực tiếp.
            'female_rules' => $femaleRules ?? [],
            // [date] => số nữ waiter có mặt cùng lúc ở Lobby, các đoạn giờ thiếu (quy tắc 2 nữ waiter cùng lúc 09:00–16:45).
            'lobby' => $shifts->isEmpty() ? [] : $this->lobbyCoverage($weekStart->copy()),
            'outlets' => $outlets->filter(fn ($o) => ! empty($codes[$o->id]) || collect($absent)->contains(fn ($day) => isset($day[$o->id])))->values(),
            'empty' => $shifts->isEmpty(),
        ];
    }
}
