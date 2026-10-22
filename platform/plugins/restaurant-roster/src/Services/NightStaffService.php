<?php

namespace Botble\RestaurantRoster\Services;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\NightRotation;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Scheduling\RequirementResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Nhân sự Night: Night cố định (core), Night đa năng (flex) và nam waiter luân phiên Night theo tuần.
 *
 * Luân phiên: mỗi tuần chọn N người (cấu hình night_rotation_per_week, mặc định 2) trong các nam waiter có năng lực Night
 * (khai báo ở Năng lực nhân viên), không phải Night cố định / đa năng, outlet chính không phải outlet Night.
 * Xếp hạng: ít tuần Night trong khoảng lịch sử → không làm tuần liền trước → tránh dồn cùng một outlet;
 * loại người có ca khóa trùng tuần, nghỉ nhiều ngày, hoặc rút đi làm outlet chính thiếu ngày công nam / tổng tối thiểu.
 * Quản lý duyệt danh sách (có thể sửa, chọn ngoài đề xuất phải ghi lý do) – mọi thay đổi ghi nhật ký.
 */
class NightStaffService
{
    public const HISTORY_WEEKS = 8;

    public function __construct(private readonly ProblemBuilder $builder)
    {
    }

    public function perWeek(): int
    {
        return max(0, (int) config('plugins.restaurant-roster.general.scheduling.night_rotation_per_week', 2));
    }

    /** @return Collection<int, ShiftTemplate> */
    public function nightTemplates(): Collection
    {
        return ShiftTemplate::query()->get()->filter(fn (ShiftTemplate $t) => $this->builder->shiftSpec($t)->category() === 'night')->values();
    }

    /**
     * Outlet Night: outlet cố định của ca đêm (shift_outlet, vd N → NIGHT) và outlet có định biên ca đêm.
     *
     * @return int[]
     */
    public function nightOutletIds(): array
    {
        $templates = $this->nightTemplates();
        $map = (array) config('plugins.restaurant-roster.general.scheduling.shift_outlet', []);
        $codes = $templates->map(fn ($t) => $map[strtoupper((string) $t->code)] ?? null)->filter()->all();

        return Outlet::query()->whereIn('code', $codes)->pluck('id')
            ->merge(StaffingRequirement::query()->whereIn('shift_template_id', $templates->modelKeys())->pluck('outlet_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function isMaleWaiter(Employee $employee): bool
    {
        $names = array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) config('plugins.restaurant-roster.general.scheduling.male_staff_positions', ['Waiter']));
        $position = $employee->position_id ? $employee->position : null;

        return $employee->gender === 'male' && ! $position?->is_manager
            && (! $names || in_array(mb_strtolower(trim((string) $position?->name)), $names, true));
    }

    /**
     * Nhân viên có năng lực làm ca đêm tại outlet Night (dòng năng lực đúng ca đêm hoặc mọi ca).
     *
     * @return int[]
     */
    private function nightSkilledIds(): array
    {
        $outlets = $this->nightOutletIds();
        $templates = $this->nightTemplates()->modelKeys();

        return EmployeeSkill::query()->whereIn('outlet_id', $outlets)
            ->where(fn ($q) => $q->whereNull('shift_template_id')->orWhere('shift_template_id', 0)->orWhereIn('shift_template_id', $templates))
            ->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Ba nhóm nhân sự Night.
     *
     * @return array{core: Collection, flex: Collection, rotation: Collection, not_skilled: Collection}
     */
    public function groups(): array
    {
        $nightOutlets = $this->nightOutletIds();
        $skilled = array_flip($this->nightSkilledIds());
        $employees = Employee::query()->where('status', BaseStatusEnum::PUBLISHED)->with(['outlet', 'position', 'scheduleGroup', 'fixedShift'])->orderBy('code')->get();
        // Luân phiên: nam waiter không có ca cứng, không phải Night đa năng, không thuộc outlet Night, không phải thực tập sinh.
        $rotationPool = $employees->filter(fn (Employee $e) => ! $e->night_role && ! $e->fixed_shift_template_id && ! $e->is_trainee
            && ! in_array((int) $e->outlet_id, $nightOutlets, true) && $this->isMaleWaiter($e));

        return [
            // Night cố định = ca cứng là ca đêm (quản lý chọn ở mục Ca cứng của nhân viên).
            'core' => $employees->filter(fn (Employee $e) => $e->isNightCore())->values(),
            'flex' => $employees->filter(fn (Employee $e) => $e->night_role === Employee::NIGHT_FLEX && ! $e->isNightCore())->values(),
            'rotation' => $rotationPool->filter(fn (Employee $e) => isset($skilled[$e->getKey()]))->values(),
            // Nam waiter chưa khai báo năng lực Night: không được chọn luân phiên (không tự gán năng lực).
            'not_skilled' => $rotationPool->reject(fn (Employee $e) => isset($skilled[$e->getKey()]))->values(),
        ];
    }

    /**
     * Số tuần có ca đêm (lịch thực tế) hoặc có tên trong kế hoạch đã duyệt, trong HISTORY_WEEKS tuần trước tuần này.
     *
     * @param int[] $employeeIds
     * @return array<int, array{weeks: int, last_week: bool, list: string[]}>
     */
    public function history(array $employeeIds, CarbonImmutable $weekStart): array
    {
        $from = $weekStart->subWeeks(self::HISTORY_WEEKS);
        $lastWeek = $weekStart->subWeek()->format('o-W');
        $weeks = [];

        Shift::query()->whereIn('employee_id', $employeeIds)->whereIn('shift_template_id', $this->nightTemplates()->modelKeys())
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<', $weekStart->toDateString())
            ->get(['employee_id', 'date'])
            ->each(function (Shift $shift) use (&$weeks): void {
                $weeks[(int) $shift->employee_id][$shift->date->format('o-W')] = $shift->date->startOfWeek()->format('d/m');
            });

        NightRotation::query()->whereIn('employee_id', $employeeIds)->where('status', '!=', NightRotation::STATUS_PROPOSED)
            ->whereDate('week_start', '>=', $from->toDateString())->whereDate('week_start', '<', $weekStart->toDateString())
            ->get(['employee_id', 'week_start'])
            ->each(function (NightRotation $row) use (&$weeks): void {
                $weeks[(int) $row->employee_id][$row->week_start->format('o-W')] = $row->week_start->format('d/m');
            });

        $result = [];

        foreach ($employeeIds as $id) {
            $list = $weeks[$id] ?? [];
            ksort($list);
            $result[$id] = ['weeks' => count($list), 'last_week' => isset($list[$lastWeek]), 'list' => array_values($list)];
        }

        return $result;
    }

    /**
     * Đề xuất người luân phiên cho một tuần: ứng viên (điểm, lịch sử), người bị loại + lý do, người được chọn, cảnh báo.
     *
     * @param int[] $exclude không xét những người này (vd đã được chọn ở tuần khác của cùng lần đề xuất)
     */
    public function propose(CarbonInterface $week, array $exclude = []): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();
        $weekEnd = $weekStart->addDays(6);
        $groups = $this->groups();
        $pool = $groups['rotation']->reject(fn (Employee $e) => in_array($e->getKey(), $exclude, true));
        $ids = $pool->modelKeys();
        $history = $this->history($ids, $weekStart);
        [$hard] = $this->builder->availability($ids, $weekStart, $weekEnd);
        $cells = Shift::query()->whereIn('employee_id', $ids)->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekEnd->toDateString())
            ->with('template:id,code')->get()->groupBy('employee_id');
        $nightIds = $this->nightTemplates()->modelKeys();

        $candidates = [];
        $excluded = [];

        foreach ($pool as $employee) {
            $id = $employee->getKey();
            $own = $cells[$id] ?? collect();
            $offDays = collect(array_keys($hard[$id] ?? []))
                ->merge($own->where('kind', '!=', Shift::KIND_SHIFT)->map(fn (Shift $s) => $s->date->toDateString()))->unique()->count();
            $lockedDay = $own->first(fn (Shift $s) => $s->kind === Shift::KIND_SHIFT && $s->is_locked && ! in_array((int) $s->shift_template_id, $nightIds, true));
            $nights = max(0, 7 - max((int) $employee->days_off_per_week, $offDays));
            $row = ['employee' => $employee, 'outlet_id' => (int) $employee->outlet_id, 'history' => $history[$id], 'nights' => $nights, 'off_days' => $offDays];

            if ($lockedDay) {
                $excluded[] = $row + ['reason' => "Có ca khóa {$lockedDay->template?->code} ngày {$lockedDay->date->format('d/m')} trong tuần"];
            } elseif ($nights < 3) {
                $excluded[] = $row + ['reason' => "Nghỉ / OFF {$offDays} ngày trong tuần – chỉ còn {$nights} đêm làm được"];
            } else {
                // Ít tuần Night trước → ưu tiên; vừa làm tuần trước → tránh lặp; nghỉ nhiều ngày → xếp sau.
                $row['score'] = $history[$id]['weeks'] * 10 + ($history[$id]['last_week'] ? 25 : 0) + (5 - min(5, $nights));
                $candidates[] = $row;
            }
        }

        usort($candidates, fn ($a, $b) => [$a['score'], $a['employee']->code] <=> [$b['score'], $b['employee']->code]);

        $selected = [];
        $sourceLoad = [];
        $warnings = [];
        $check = $this->sourceChecker($weekStart);

        while (count($selected) < $this->perWeek() && $candidates) {
            $best = null;

            foreach ($candidates as $k => $row) {
                // Tránh dồn cùng một outlet khi outlet khác còn người đủ điều kiện (≈ nửa tuần lịch sử).
                $score = $row['score'] + 5 * ($sourceLoad[$row['outlet_id']] ?? 0);

                if ($best === null || $score < $best[1]) {
                    $best = [$k, $score];
                }
            }

            $row = $candidates[$best[0]];
            unset($candidates[$best[0]]);

            if ($problem = $check($row['employee'])) {
                $excluded[] = $row + ['reason' => $problem];

                continue;
            }

            $selected[] = $row;
            $sourceLoad[$row['outlet_id']] = ($sourceLoad[$row['outlet_id']] ?? 0) + 1;
        }

        if (count($selected) < $this->perWeek()) {
            $warnings[] = sprintf('Tuần %s chỉ chọn được %d / %d người luân phiên Night hợp lệ (%d người bị loại, %d nam waiter chưa có năng lực Night).',
                $weekStart->format('d/m'), count($selected), $this->perWeek(), count($excluded), $groups['not_skilled']->count());
        }

        return ['week_start' => $weekStart, 'selected' => $selected, 'candidates' => array_values($candidates), 'excluded' => $excluded, 'warnings' => $warnings,
            'not_skilled' => $groups['not_skilled']];
    }

    /**
     * Kiểm tra outlet nguồn: sau khi rút người này đi Night cả tuần, outlet chính còn đủ ngày công nam waiter và tổng
     * cho định biên tối thiểu M6 / A2… của tuần (tính theo số ngày làm tối đa của từng người, trừ ngày OFF / nghỉ đã duyệt).
     *
     * @return callable(Employee): ?string thông báo lý do nếu không đạt
     */
    private function sourceChecker(CarbonImmutable $weekStart): callable
    {
        $dates = array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($weekStart, $weekStart->addDays(6))));
        $resolver = new RequirementResolver(StaffingRequirementService::resolverRows(StaffingRequirement::query()->where('status', StaffingRequirement::STATUS_ACTIVE)));
        $templates = ShiftTemplate::query()->pluck('id')->all();
        $cache = [];

        return function (Employee $employee) use ($dates, $resolver, $templates, &$cache): ?string {
            $outletId = (int) $employee->outlet_id;

            if (! isset($cache[$outletId])) {
                $need = ['total' => 0, 'male' => 0];

                foreach ($dates as $date) {
                    foreach ($templates as $templateId) {
                        if ($r = $resolver->resolve($date, $outletId, (int) $templateId)) {
                            $need['total'] += $r->min;
                            $need['male'] += $r->minMale;
                        }
                    }
                }

                $staff = Employee::query()->where('outlet_id', $outletId)->where('status', BaseStatusEnum::PUBLISHED)->where('is_trainee', false)->get();
                [$hard] = $this->builder->availability($staff->modelKeys(), CarbonImmutable::parse($dates[0]), CarbonImmutable::parse($dates[6]));
                $days = fn (Employee $e) => max(0, min(7 - (int) $e->days_off_per_week, 7 - count($hard[$e->getKey()] ?? [])));
                $cache[$outletId] = [
                    'need' => $need,
                    'total' => $staff->sum($days),
                    'male' => $staff->filter(fn ($e) => $this->isMaleWaiter($e))->sum($days),
                    'name' => Outlet::query()->whereKey($outletId)->value('code') ?: 'Outlet chính',
                    'days' => [],
                ];
            }

            $c = &$cache[$outletId];
            $taken = 7 - (int) $employee->days_off_per_week;
            $removed = array_sum($c['days']) + $taken;

            if ($c['male'] - $removed < $c['need']['male']) {
                return sprintf('%s sẽ thiếu nam waiter: còn %d ngày công nam, cần %d (tối thiểu nam các ca trong tuần)', $c['name'], $c['male'] - $removed, $c['need']['male']);
            }

            if ($c['total'] - $removed < $c['need']['total']) {
                return sprintf('%s sẽ thiếu người: còn %d ngày công, cần %d (tối thiểu các ca trong tuần)', $c['name'], $c['total'] - $removed, $c['need']['total']);
            }

            $c['days'][] = $taken;

            return null;
        };
    }

    /**
     * Kế hoạch luân phiên dùng khi xếp lịch: tuần đã duyệt / công bố → đúng danh sách đó; tuần chưa có → đề xuất của thuật toán.
     *
     * @return array{plan: array<string, int[]>, weeks: array<string, array{source: string, ids: int[], warnings: string[]}>}
     */
    public function planFor(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->groups()['rotation']->count() && ! NightRotation::query()->exists()) {
            return ['plan' => [], 'weeks' => []];
        }

        $plan = [];
        $weeks = [];
        $start = CarbonImmutable::parse($from)->startOfWeek();
        $taken = [];

        for ($week = $start; $week->lte(CarbonImmutable::parse($to)); $week = $week->addWeek()) {
            $rows = NightRotation::query()->whereDate('week_start', $week->toDateString())->where('status', '!=', NightRotation::STATUS_PROPOSED)->pluck('employee_id');

            if ($rows->isNotEmpty()) {
                $ids = $rows->map(fn ($id) => (int) $id)->all();
                $weeks[$week->toDateString()] = ['source' => 'approved', 'ids' => $ids, 'warnings' => []];
            } else {
                $proposal = $this->propose($week, $taken);
                $ids = array_map(fn ($row) => $row['employee']->getKey(), $proposal['selected']);
                $weeks[$week->toDateString()] = ['source' => 'proposed', 'ids' => $ids, 'warnings' => $proposal['warnings']];
            }

            $taken = $ids;

            foreach (CarbonPeriod::create($week, $week->addDays(6)) as $day) {
                $plan[$day->toDateString()] = $ids;
            }
        }

        return ['plan' => $plan, 'weeks' => $weeks];
    }

    /**
     * Lưu danh sách luân phiên của một tuần (đã duyệt / công bố). Tuần đã công bố là tuần khóa – không ghi đè.
     * Chọn người ngoài danh sách hợp lệ của đề xuất là ngoại lệ: bắt buộc lý do, ghi nhật ký.
     *
     * @param int[] $employeeIds
     */
    public function save(CarbonInterface $week, array $employeeIds, string $status, ?int $userId, ?string $reason = null): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));

        if (! in_array($status, [NightRotation::STATUS_APPROVED, NightRotation::STATUS_PUBLISHED], true)) {
            throw new InvalidArgumentException('Trạng thái không hợp lệ.');
        }

        if (NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->where('status', NightRotation::STATUS_PUBLISHED)->exists()) {
            throw new InvalidArgumentException('Tuần ' . $weekStart->format('d/m') . ' đã công bố (khóa) – không sửa danh sách luân phiên.');
        }

        $proposal = $this->propose($weekStart);
        $valid = collect([...$proposal['selected'], ...$proposal['candidates']])->map(fn ($row) => $row['employee']->getKey())->all();
        $rotationIds = $this->groups()['rotation']->modelKeys();
        $exceptions = [];

        foreach ($employeeIds as $id) {
            if (! in_array($id, $rotationIds, true)) {
                throw new InvalidArgumentException('Nhân viên #' . $id . ' không đủ điều kiện luân phiên Night (cần nam waiter có năng lực Night, không phải Night cố định / đa năng).');
            }

            if (! in_array($id, $valid, true)) {
                $row = collect($proposal['excluded'])->first(fn ($r) => $r['employee']->getKey() === $id);
                $exceptions[$id] = $row['reason'] ?? 'ngoài danh sách hợp lệ';
            }
        }

        if ($exceptions && ! trim((string) $reason)) {
            throw new InvalidArgumentException('Có người bị thuật toán loại (' . implode('; ', $exceptions) . ') – ghi lý do ngoại lệ để duyệt.');
        }

        return DB::transaction(function () use ($weekStart, $employeeIds, $status, $userId, $reason, $exceptions) {
            $before = NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->get()->keyBy('employee_id');
            NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->delete();
            $outlets = Employee::query()->whereIn('id', $employeeIds)->pluck('outlet_id', 'id');

            foreach ($employeeIds as $id) {
                NightRotation::query()->create(['week_start' => $weekStart->toDateString(), 'employee_id' => $id, 'source_outlet_id' => $outlets[$id] ?? null,
                    'status' => $status, 'note' => isset($exceptions[$id]) ? mb_substr('Ngoại lệ: ' . $exceptions[$id] . ' – ' . $reason, 0, 255) : ($reason ? mb_substr($reason, 0, 255) : null),
                    'created_by' => $before[$id]->created_by ?? $userId, 'approved_by' => $userId, 'approved_at' => now()]);
            }

            foreach (array_unique([...$before->keys()->map(fn ($id) => (int) $id)->all(), ...$employeeIds]) as $id) {
                $was = $before[$id]->status ?? null;
                $now = in_array($id, $employeeIds, true) ? $status : null;

                if ($was !== $now) {
                    ChangeLog::query()->create(['employee_id' => $id, 'date' => $weekStart->toDateString(), 'action' => 'night_rotation',
                        'before' => ['night_rotation' => $was], 'after' => ['night_rotation' => $now, 'exception' => $exceptions[$id] ?? null],
                        'reason' => mb_substr('Luân phiên Night tuần ' . $weekStart->format('d/m') . ($reason ? ': ' . $reason : ''), 0, 500), 'user_id' => $userId]);
                }
            }

            return ['week_start' => $weekStart, 'ids' => $employeeIds, 'exceptions' => $exceptions];
        });
    }

    /**
     * Định biên Night từng ngày của tuần theo lịch đã lưu: cần (tối thiểu / mục tiêu), số người theo nhóm, thiếu / vượt.
     */
    public function dailyStaffing(CarbonInterface $week): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();
        $outlets = $this->nightOutletIds();
        $templates = $this->nightTemplates()->modelKeys();
        $resolver = new RequirementResolver(StaffingRequirementService::resolverRows(StaffingRequirement::query()->where('status', StaffingRequirement::STATUS_ACTIVE)->whereIn('outlet_id', $outlets)));
        $planned = NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->where('status', '!=', NightRotation::STATUS_PROPOSED)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        $shifts = Shift::query()->whereIn('outlet_id', $outlets)->whereIn('shift_template_id', $templates)->where('kind', Shift::KIND_SHIFT)
            ->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekStart->addDays(6)->toDateString())
            ->with('employee:id,name,night_role,fixed_shift_template_id', 'employee.fixedShift:id,start_time')->get()->groupBy(fn (Shift $s) => $s->date->toDateString());
        $days = [];

        foreach (CarbonPeriod::create($weekStart, $weekStart->addDays(6)) as $day) {
            $date = $day->toDateString();
            [$min, $target] = [0, 0];

            foreach ($outlets as $outletId) {
                foreach ($templates as $templateId) {
                    if ($r = $resolver->resolve($date, $outletId, (int) $templateId)) {
                        $min += $r->min;
                        $target += $r->target;
                    }
                }
            }

            $people = ['core' => [], 'flex' => [], 'rotation' => [], 'other' => []];

            foreach ($shifts[$date] ?? [] as $shift) {
                $role = match (true) {
                    (bool) $shift->employee?->isNightCore() => 'core',
                    $shift->employee?->night_role === Employee::NIGHT_FLEX => 'flex',
                    in_array((int) $shift->employee_id, $planned, true) => 'rotation',
                    default => 'other',
                };
                $people[$role][] = (string) $shift->employee?->name;
            }

            $actual = array_sum(array_map('count', $people));
            $days[$date] = ['date' => $day, 'min' => $min, 'target' => $target, 'people' => $people, 'actual' => $actual,
                'short' => max(0, $min - $actual), 'over' => max(0, $actual - max($target, $min))];
        }

        return $days;
    }
}
