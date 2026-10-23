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
 * Nhân sự Night: Night cứng (ca cứng ca đêm, ≥ 1 người / ngày) và nam waiter luân phiên Night theo tuần.
 *
 * Luân phiên (quản lý 23/10/2026): mỗi tuần chọn N người (night_rotation_per_week, mặc định 4) trong các nam waiter của
 * Villa / Gusto / Night (night_rotation_outlets) có năng lực Night, không phải Night cứng (ca cứng ca đêm), không phải Room Service
 * chuyên trách, đang bật "tham gia luân phiên". Mỗi người ít nhất 2 đêm liền nhau, làm thêm đêm (tới night_rotation_max_nights) khi cần
 * đủ 3 người / ngày, hôm sau OFF. Nam waiter ưu tiên giữ định mức nhà hàng (không rút người làm nhà hàng thiếu tối thiểu).
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
        return max(0, (int) config('plugins.restaurant-roster.general.scheduling.night_rotation_per_week', 4));
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

    /** @return int[] outlet nguồn của người luân phiên Night (Villa, Gusto, Night) */
    public function rotationOutletIds(): array
    {
        return Outlet::query()->whereIn('code', (array) config('plugins.restaurant-roster.general.scheduling.night_rotation_outlets', ['GUSTO', 'VILLA', 'NIGHT']))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Nhóm nhân sự Night (đọc từ database mỗi lần – đổi outlet / ca cứng / Room Service / bật tắt không cần sửa code).
     *
     * @return array{core: Collection, room_service: Collection, rotation: Collection, disabled: Collection, not_skilled: Collection}
     */
    public function groups(?CarbonInterface $week = null): array
    {
        $weekStart = CarbonImmutable::parse($week ?? CarbonImmutable::today())->startOfWeek();
        $skilled = array_flip($this->nightSkilledIds());
        $sources = $this->rotationOutletIds();
        $roomLeads = app(RoomServiceStaffService::class)->leadIds($weekStart, $weekStart->addDays(6));
        $employees = Employee::query()->where('status', BaseStatusEnum::PUBLISHED)->with(['outlet', 'position', 'scheduleGroup', 'fixedShift'])->orderBy('code')->get();
        // Lý do một người không vào được danh sách luân phiên (null = đủ điều kiện hồ sơ). Đã khai năng lực Night thì không bắt buộc vị trí "Waiter".
        $blocker = function (Employee $e) use ($roomLeads, $sources, $skilled): ?string {
            return match (true) {
                $e->isNightCore() => 'Night cứng',
                in_array((int) $e->getKey(), $roomLeads, true) => 'Room Service chuyên trách',
                (bool) $e->fixed_shift_template_id => 'đang có ca cứng ' . ($e->fixedShift?->code ?? ''),
                (bool) $e->is_trainee => 'thực tập sinh',
                $e->gender !== 'male' => $e->gender === null ? 'chưa khai giới tính (cần Nam)' : 'không phải nhân viên nam',
                (bool) $e->position?->is_manager => 'thuộc cấp quản lý',
                ! in_array((int) $e->outlet_id, $sources, true) => 'outlet chính ' . ($e->outlet?->code ?: '—') . ' không thuộc nguồn luân phiên (Villa / Gusto / Night)',
                ! isset($skilled[$e->getKey()]) && ! $this->isMaleWaiter($e) => 'chưa có năng lực Night và không phải Waiter',
                default => null,
            };
        };
        $profile = $employees->filter(fn (Employee $e) => $blocker($e) === null);

        return [
            // Night cứng = ca cứng là ca đêm (quản lý chọn ở mục Ca cứng của nhân viên).
            'core' => $employees->filter(fn (Employee $e) => $e->isNightCore())->values(),
            'room_service' => $employees->filter(fn (Employee $e) => in_array((int) $e->getKey(), $roomLeads, true))->values(),
            // Nhân viên đa năng: người thuộc outlet Night trừ Night cứng và Room Service chuyên trách – được mượn trước khi outlet thiếu tối thiểu.
            'flex' => $employees->filter(fn (Employee $e) => in_array((int) $e->outlet_id, $this->nightOutletIds(), true) && ! $e->isNightCore()
                && ! in_array((int) $e->getKey(), $roomLeads, true) && ! $e->is_trainee)->values(),
            'rotation' => $profile->filter(fn (Employee $e) => isset($skilled[$e->getKey()]) && $e->night_rotation_enabled !== false)->values(),
            // Đã tắt "tham gia luân phiên Night" trong hồ sơ / trang Nhân sự Night.
            'disabled' => $profile->filter(fn (Employee $e) => isset($skilled[$e->getKey()]) && $e->night_rotation_enabled === false)->values(),
            // Nam waiter chưa khai báo năng lực Night: không được chọn luân phiên (không tự gán năng lực).
            'not_skilled' => $profile->reject(fn (Employee $e) => isset($skilled[$e->getKey()]))->values(),
            // Có năng lực Night nhưng không được chọn luân phiên – kèm lý do để quản lý sửa hồ sơ.
            'ineligible' => $employees->filter(fn (Employee $e) => isset($skilled[$e->getKey()]) && ! $e->isNightCore() && $blocker($e) !== null)
                ->map(fn (Employee $e) => ['employee' => $e, 'reason' => $blocker($e)])->values(),
        ];
    }

    /**
     * Bước 1 – Đề xuất: lưu danh sách thuật toán chọn cho tuần ở trạng thái "đề xuất" (thay đề xuất cũ). Tuần đã duyệt / công bố thì
     * phải hủy duyệt trước.
     *
     * @return int[] nhân viên được đề xuất
     */
    public function storeProposal(CarbonInterface $week, ?int $userId): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();

        if (NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->where('status', '!=', NightRotation::STATUS_PROPOSED)->exists()) {
            throw new InvalidArgumentException('Tuần ' . $weekStart->format('d/m') . ' đã duyệt – bấm "Hủy duyệt" trước khi đề xuất lại.');
        }

        $proposal = $this->propose($weekStart);

        return DB::transaction(function () use ($weekStart, $proposal, $userId) {
            NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->delete();
            $ids = [];

            foreach ($proposal['selected'] as $row) {
                $ids[] = $row['employee']->getKey();
                NightRotation::query()->create(['week_start' => $weekStart->toDateString(), 'employee_id' => $row['employee']->getKey(),
                    'source_outlet_id' => $row['outlet_id'], 'status' => NightRotation::STATUS_PROPOSED, 'note' => 'Thuật toán đề xuất', 'created_by' => $userId]);
            }

            return $ids;
        });
    }

    /**
     * Hủy duyệt (kể cả đã công bố): danh sách về trạng thái "đề xuất", giữ nguyên người để quản lý chọn lại. Lịch ca đã xếp không bị đổi.
     *
     * @return int số người được đưa về đề xuất
     */
    public function unapprove(CarbonInterface $week, ?int $userId, ?string $reason = null): int
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();

        return DB::transaction(function () use ($weekStart, $userId, $reason) {
            $rows = NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->where('status', '!=', NightRotation::STATUS_PROPOSED)->lockForUpdate()->get();

            foreach ($rows as $row) {
                ChangeLog::query()->create(['employee_id' => $row->employee_id, 'date' => $weekStart->toDateString(), 'action' => 'night_rotation',
                    'before' => ['night_rotation' => $row->status], 'after' => ['night_rotation' => NightRotation::STATUS_PROPOSED],
                    'reason' => mb_substr('Hủy duyệt luân phiên Night tuần ' . $weekStart->format('d/m') . ($reason ? ': ' . $reason : ''), 0, 500), 'user_id' => $userId]);
                $row->update(['status' => NightRotation::STATUS_PROPOSED, 'approved_by' => null, 'approved_at' => null]);
            }

            return $rows->count();
        });
    }

    /**
     * Phạm vi "Reset lịch Night" của một tuần (để hiện trong hộp xác nhận): ca đêm do thuật toán xếp còn nháp và chưa khóa sẽ bị xóa;
     * ca đêm đã khóa / đã công bố / quản lý xếp tay được giữ; danh sách luân phiên đề xuất / đã duyệt / đã công bố.
     *
     * @return array{engine: int, kept_locked: int, kept_published: int, kept_manual: int, proposed: int, approved: int, published: int}
     */
    public function resetScope(CarbonInterface $week): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();
        $cells = $this->nightCells($weekStart)->get();
        $rows = NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->get();

        return [
            'engine' => $cells->filter(fn (Shift $s) => $this->resettable($s))->count(),
            'kept_locked' => $cells->filter(fn (Shift $s) => $s->is_locked)->count(),
            'kept_published' => $cells->filter(fn (Shift $s) => ! $s->is_locked && $s->status === Shift::STATUS_PUBLISHED)->count(),
            'kept_manual' => $cells->filter(fn (Shift $s) => ! $s->is_locked && $s->status !== Shift::STATUS_PUBLISHED && $s->source !== 'engine')->count(),
            'proposed' => $rows->where('status', NightRotation::STATUS_PROPOSED)->count(),
            'approved' => $rows->where('status', NightRotation::STATUS_APPROVED)->count(),
            'published' => $rows->where('status', NightRotation::STATUS_PUBLISHED)->count(),
        ];
    }

    /**
     * Reset lịch Night của MỘT tuần để chạy lại thuật toán: xóa ca đêm do thuật toán xếp (nháp, chưa khóa) ở outlet Night,
     * xóa danh sách luân phiên đề xuất – và danh sách đã duyệt nếu người dùng xác nhận. Không động tới: ca đã khóa / đã công bố /
     * quản lý xếp tay, danh sách đã công bố, tuần khác, outlet khác, OFF, nhân viên, cấu hình. Một transaction; ghi nhật ký từng ô.
     *
     * @return array{deleted_cells: int, deleted_rotation: int, kept: array<string, int>}
     */
    public function resetWeek(CarbonInterface $week, ?int $userId, bool $includeApproved = false): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();
        $scope = $this->resetScope($weekStart);

        return DB::transaction(function () use ($weekStart, $userId, $includeApproved, $scope) {
            $deleted = 0;
            $codes = ShiftTemplate::query()->pluck('code', 'id');

            foreach ($this->nightCells($weekStart)->lockForUpdate()->get()->filter(fn (Shift $s) => $this->resettable($s)) as $cell) {
                ChangeLog::query()->create(['employee_id' => $cell->employee_id, 'date' => $cell->date->toDateString(), 'action' => 'night_reset',
                    'before' => ['notation' => $codes[$cell->shift_template_id] ?? null, 'outlet_id' => $cell->outlet_id, 'status' => $cell->status, 'source' => $cell->source],
                    'after' => null, 'reason' => 'Reset lịch Night tuần ' . $weekStart->format('d/m') . ' để chạy lại thuật toán', 'user_id' => $userId]);
                $cell->delete();
                $deleted++;
            }

            $statuses = [NightRotation::STATUS_PROPOSED, ...($includeApproved ? [NightRotation::STATUS_APPROVED] : [])];
            $rows = NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->whereIn('status', $statuses)->get();

            foreach ($rows as $row) {
                ChangeLog::query()->create(['employee_id' => $row->employee_id, 'date' => $weekStart->toDateString(), 'action' => 'night_reset',
                    'before' => ['night_rotation' => $row->status], 'after' => ['night_rotation' => null],
                    'reason' => 'Reset danh sách luân phiên Night tuần ' . $weekStart->format('d/m'), 'user_id' => $userId]);
                $row->delete();
            }

            return [
                'deleted_cells' => $deleted,
                'deleted_rotation' => $rows->count(),
                'kept' => ['locked' => $scope['kept_locked'], 'published' => $scope['kept_published'], 'manual' => $scope['kept_manual'],
                    'approved' => $includeApproved ? 0 : $scope['approved'], 'published_rotation' => $scope['published']],
            ];
        });
    }

    /** Ca đêm ở outlet Night trong tuần. */
    private function nightCells(CarbonImmutable $weekStart): \Illuminate\Database\Eloquent\Builder
    {
        return Shift::query()->whereIn('outlet_id', $this->nightOutletIds())->whereIn('shift_template_id', $this->nightTemplates()->modelKeys())
            ->where('kind', Shift::KIND_SHIFT)->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekStart->addDays(6)->toDateString());
    }

    /** Ô được phép reset: do thuật toán xếp, còn nháp, chưa khóa. */
    private function resettable(Shift $shift): bool
    {
        return $shift->source === 'engine' && ! $shift->is_locked && $shift->status !== Shift::STATUS_PUBLISHED;
    }

    /** Bật / tắt tư cách luân phiên Night của một người (ghi nhật ký). */
    public function setRotationEnabled(Employee $employee, bool $enabled, ?int $userId, ?string $reason = null): void
    {
        $before = (bool) ($employee->night_rotation_enabled ?? true);

        if ($before === $enabled) {
            return;
        }

        $employee->update(['night_rotation_enabled' => $enabled]);
        ChangeLog::query()->create(['employee_id' => $employee->getKey(), 'date' => now()->toDateString(), 'action' => 'night_rotation',
            'before' => ['night_rotation_enabled' => $before], 'after' => ['night_rotation_enabled' => $enabled],
            'reason' => mb_substr(($enabled ? 'Bật' : 'Tắt') . ' tham gia luân phiên Night' . ($reason ? ': ' . $reason : ''), 0, 500), 'user_id' => $userId]);
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
        $nights = [];
        $worked = [];

        Shift::query()->whereIn('employee_id', $employeeIds)->whereIn('shift_template_id', $this->nightTemplates()->modelKeys())
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<', $weekStart->toDateString())
            ->get(['employee_id', 'date'])
            ->each(function (Shift $shift) use (&$weeks, &$nights): void {
                $weeks[(int) $shift->employee_id][$shift->date->format('o-W')] = $shift->date->startOfWeek()->format('d/m');
                $nights[(int) $shift->employee_id][$shift->date->toDateString()] = true;
            });

        // Tuần thực sự đi làm (có ca bất kỳ): số đêm quy về số tuần đi làm – người nghỉ dài không bị dồn Night bù.
        Shift::query()->whereIn('employee_id', $employeeIds)->where('kind', Shift::KIND_SHIFT)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<', $weekStart->toDateString())
            ->get(['employee_id', 'date'])
            ->each(function (Shift $shift) use (&$worked): void {
                $worked[(int) $shift->employee_id][$shift->date->format('o-W')] = true;
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
            $count = count($nights[$id] ?? []);
            $workedWeeks = count($worked[$id] ?? []);
            $result[$id] = ['weeks' => count($list), 'nights' => $count, 'last_week' => isset($list[$lastWeek]), 'list' => array_values($list),
                'rate' => $workedWeeks > 0 ? round($count * self::HISTORY_WEEKS / $workedWeeks, 1) : (float) $count];
        }

        return $result;
    }

    /**
     * Đề xuất người luân phiên cho một tuần: ứng viên (điểm, lịch sử), người bị loại + lý do, người được chọn, cảnh báo.
     *
     * @param array<int, int> $planned số tuần đã được xếp (đề xuất / duyệt) ở các tuần trước trong cùng lần lập lịch nhiều tuần,
     *                              người có trong tuần liền trước nằm trong $lastWeek – được tính như lịch sử (xếp sau, không bị loại)
     * @param int[] $lastWeek
     */
    public function propose(CarbonInterface $week, array $planned = [], array $lastWeek = []): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();
        $weekEnd = $weekStart->addDays(6);
        $groups = $this->groups($weekStart);
        $pool = $groups['rotation'];
        $ids = $pool->modelKeys();
        $history = $this->history($ids, $weekStart);

        foreach ($history as $id => $row) {
            $history[$id]['weeks'] += $planned[$id] ?? 0;
            $history[$id]['last_week'] = $row['last_week'] || in_array($id, $lastWeek, true);
        }
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
            // Ngày không làm Night được: OFF / nghỉ đã duyệt, ô khóa ca ngày. Cần 2 đêm liền nhau và hôm sau không phải ca khóa.
            $blocked = [];
            $lockedWork = [];

            foreach ($own as $s) {
                $d = $s->date->toDateString();

                if ($s->kind !== Shift::KIND_SHIFT || ($s->is_locked && ! in_array((int) $s->shift_template_id, $nightIds, true))) {
                    $blocked[$d] = true;
                }

                if ($s->kind === Shift::KIND_SHIFT && $s->is_locked && ! in_array((int) $s->shift_template_id, $nightIds, true)) {
                    $lockedWork[$d] = $s;
                }
            }

            foreach (array_keys($hard[$id] ?? []) as $d) {
                $blocked[$d] = true;
            }

            $nights = 0;
            $pairs = 0;

            for ($k = 0; $k < 7; $k++) {
                $d = $weekStart->addDays($k)->toDateString();
                $nights += isset($blocked[$d]) ? 0 : 1;
                $next = $weekStart->addDays($k + 1)->toDateString();
                $after = $weekStart->addDays($k + 2)->toDateString();
                $pairs += ($k < 6 && ! isset($blocked[$d]) && ! isset($blocked[$next]) && ! isset($lockedWork[$after])) ? 1 : 0;
            }

            $row = ['employee' => $employee, 'outlet_id' => (int) $employee->outlet_id, 'history' => $history[$id], 'nights' => $nights, 'off_days' => $offDays];

            if (! $pairs) {
                $first = collect($lockedWork)->first();
                $excluded[] = $row + ['reason' => 'Không có 2 đêm liền nhau làm được trong tuần'
                    . ($first ? " (ca khóa {$first->template?->code} ngày {$first->date->format('d/m')})" : " (OFF / nghỉ {$offDays} ngày)")];
            } else {
                // Ít tuần Night trước → ưu tiên; vừa làm tuần trước → tránh lặp; nghỉ nhiều ngày → xếp sau.
                // Ít đêm Night trong lịch sử (quy về tuần đi làm) → ưu tiên; tuần đã chọn trong cùng lần lập lịch tính như 2–3 đêm;
                // vừa làm tuần trước → tránh lặp; nghỉ nhiều ngày → xếp sau.
                $row['score'] = $history[$id]['rate'] * 4 + ($history[$id]['weeks'] - count($history[$id]['list'])) * 10
                    + ($history[$id]['last_week'] ? 25 : 0) + (5 - min(5, $nights));
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

            // Người của outlet Night: không rút người khỏi nhà hàng nào. Chế độ "balance": nhà hàng không còn định biên tối thiểu.
            if (in_array($outletId, $this->nightOutletIds(), true)
                || (config('plugins.restaurant-roster.general.scheduling.staffing_mode', 'balance') === 'balance' && ! in_array($outletId, $this->builder->staffedOutletIds(), true))) {
                return null;
            }

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
            // Đi Night 2 đêm liền nhau (OFF sau đêm là một trong các ngày OFF tuần): outlet nguồn mất 2 ngày công.
            $taken = 2;
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
        if (! $this->groups($from)['rotation']->count() && ! NightRotation::query()->exists()) {
            return ['plan' => [], 'weeks' => []];
        }

        $plan = [];
        $weeks = [];
        $start = CarbonImmutable::parse($from)->startOfWeek();
        $taken = [];
        $counts = [];

        for ($week = $start; $week->lte(CarbonImmutable::parse($to)); $week = $week->addWeek()) {
            $rows = NightRotation::query()->whereDate('week_start', $week->toDateString())->where('status', '!=', NightRotation::STATUS_PROPOSED)->pluck('employee_id');

            $stored = $rows->isEmpty()
                ? NightRotation::query()->whereDate('week_start', $week->toDateString())->where('status', NightRotation::STATUS_PROPOSED)->pluck('employee_id')
                : collect();

            if ($rows->isNotEmpty()) {
                $ids = $rows->map(fn ($id) => (int) $id)->all();
                $weeks[$week->toDateString()] = ['source' => 'approved', 'ids' => $ids, 'warnings' => []];
            } elseif ($stored->isNotEmpty()) {
                // Đề xuất đã lưu (bước "Đề xuất" trên trang Nhân sự Night): dùng đúng danh sách đó, chưa duyệt.
                $ids = $stored->map(fn ($id) => (int) $id)->all();
                $weeks[$week->toDateString()] = ['source' => 'proposed', 'ids' => $ids, 'warnings' => []];
            } else {
                // Tuần chưa duyệt: đề xuất có tính cả các tuần trước trong cùng lần lập lịch (luân phiên công bằng nhiều tuần).
                $proposal = $this->propose($week, $counts, $taken);
                $ids = array_map(fn ($row) => $row['employee']->getKey(), $proposal['selected']);
                $weeks[$week->toDateString()] = ['source' => 'proposed', 'ids' => $ids, 'warnings' => $proposal['warnings']];
            }

            $taken = $ids;

            // Tuần đã duyệt đã nằm trong lịch sử (database); tuần chỉ đề xuất thì cộng tạm cho các tuần sau.
            foreach ($rows->isEmpty() ? $ids : [] as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }

            foreach (CarbonPeriod::create($week, $week->addDays(6)) as $day) {
                $plan[$day->toDateString()] = $ids;
            }
        }

        return ['plan' => $plan, 'weeks' => $weeks];
    }

    /**
     * Xem trước ảnh hưởng của một danh sách luân phiên (chưa lưu): người bị loại và lý do, outlet nguồn, số lượt Night cần so với
     * số lượt 4 người × 2–3 đêm làm được (theo ngày làm tối đa của Night cứng).
     *
     * @param int[] $employeeIds
     * @return string[] cảnh báo dễ hiểu (rỗng = hợp lệ)
     */
    public function check(CarbonInterface $week, array $employeeIds): array
    {
        $weekStart = CarbonImmutable::parse($week)->startOfWeek();
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $proposal = $this->propose($weekStart);
        $excluded = collect($proposal['excluded'])->keyBy(fn ($row) => $row['employee']->getKey());
        $pool = $this->groups($weekStart)['rotation']->keyBy('id');
        $warnings = [];

        if (count($employeeIds) !== $this->perWeek()) {
            $warnings[] = sprintf('Đang chọn %d người – quy tắc là %d người luân phiên mỗi tuần.', count($employeeIds), $this->perWeek());
        }

        $check = $this->sourceChecker($weekStart);

        foreach ($employeeIds as $id) {
            $employee = $pool[$id] ?? Employee::query()->find($id);

            if (! isset($pool[$id])) {
                $warnings[] = 'Không thể chọn ' . ($employee?->name ?? "#{$id}") . ': không đủ điều kiện luân phiên (Night cứng / Room Service chuyên trách / chưa có năng lực Night / đã tắt luân phiên).';
            } elseif ($row = $excluded[$id] ?? null) {
                $warnings[] = "Không thể chọn {$employee->name} vì {$row['reason']} (cần lý do ngoại lệ để duyệt).";
            } elseif ($problem = $check($employee)) {
                $warnings[] = "Không thể chọn {$employee->name} vì {$problem}.";
            }
        }

        // Lượt Night cần cho người luân phiên: 3 người × 7 ngày − số đêm tối đa của Night cứng.
        $daily = $this->dailyStaffing($weekStart)['days'];
        $need = array_sum(array_column($daily, 'min'));
        $coreNights = $this->groups($weekStart)['core']->sum(fn (Employee $e) => max(0, 7 - (int) $e->days_off_per_week));
        $slots = max(0, $need - $coreNights);
        [$low, $high] = [count($employeeIds) * 2, count($employeeIds) * max(2, (int) config('plugins.restaurant-roster.general.scheduling.night_rotation_max_nights', 5))];

        if ($need && ($slots < $low || $slots > $high)) {
            $warnings[] = sprintf('Night cần khoảng %d lượt luân phiên trong tuần (%d − %d đêm của Night cứng), %d người làm được %d–%d lượt.',
                $slots, $need, $coreNights, count($employeeIds), $low, $high);
        }

        return $warnings;
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
        $rotationIds = $this->groups($weekStart)['rotation']->modelKeys();
        $exceptions = [];

        foreach ($employeeIds as $id) {
            if (! in_array($id, $rotationIds, true)) {
                throw new InvalidArgumentException('Nhân viên #' . $id . ' không đủ điều kiện luân phiên Night (cần nam waiter Villa / Gusto / Night có năng lực Night, không phải Night cứng / Room Service chuyên trách, đang bật luân phiên).');
            }

            if (! in_array($id, $valid, true)) {
                $row = collect($proposal['excluded'])->first(fn ($r) => $r['employee']->getKey() === $id);
                $exceptions[$id] = $row['reason'] ?? 'ngoài danh sách hợp lệ';
            }
        }

        // Công bố đúng danh sách đã duyệt: ngoại lệ đã được ghi lý do lúc duyệt.
        $approvedIds = NightRotation::query()->whereDate('week_start', $weekStart->toDateString())->where('status', NightRotation::STATUS_APPROVED)
            ->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $sameAsApproved = $approvedIds && $approvedIds === collect($employeeIds)->sort()->values()->all();

        if ($exceptions && ! trim((string) $reason) && ! $sameAsApproved) {
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
        $perPerson = array_fill_keys($planned, 0);

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

            $people = ['core' => [], 'rotation' => [], 'other' => []];

            foreach ($shifts[$date] ?? [] as $shift) {
                $role = match (true) {
                    (bool) $shift->employee?->isNightCore() => 'core',
                    in_array((int) $shift->employee_id, $planned, true) => 'rotation',
                    default => 'other',
                };
                $people[$role][] = (string) $shift->employee?->name;

                if ($role === 'rotation') {
                    $perPerson[(int) $shift->employee_id] = ($perPerson[(int) $shift->employee_id] ?? 0) + 1;
                }
            }

            $actual = array_sum(array_map('count', $people));
            $days[$date] = ['date' => $day, 'min' => $min, 'target' => $target, 'people' => $people, 'actual' => $actual,
                'short' => max(0, $min - $actual), 'over' => max(0, $actual - max($target, $min)), 'has_core' => (bool) $people['core']];
        }

        return ['days' => $days, 'per_person' => $perPerson];
    }
}
