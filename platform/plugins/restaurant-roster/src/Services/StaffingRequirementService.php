<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\OutletDay;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Run;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Models\StaffingRequirementPosition;
use Botble\RestaurantRoster\Models\StaffingRequirementRevision;
use Botble\RestaurantRoster\Models\Violation;
use Botble\RestaurantRoster\Scheduling\RequirementResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nghiệp vụ Định biên nhân sự (tách khỏi controller / view):
 * - kiểm tra hợp lệ và xung đột (hai dòng cùng mức ưu tiên, hiệu lực chồng nhau, giá trị khác nhau);
 * - lưu trong transaction kèm nhóm theo vị trí và lịch sử (trước / sau, lý do, người sửa);
 * - ngừng / bật hiệu lực (không xóa dòng đã dùng), sao chép;
 * - xem trước ảnh hưởng lên lịch đã tạo / đã công bố;
 * - số liệu tổng quan và ma trận định biên theo tuần.
 */
class StaffingRequirementService
{
    /** Số tuần tới được xét khi xem trước ảnh hưởng. */
    public const IMPACT_WEEKS = 8;

    /**
     * Dòng định biên dạng mảng cho RequirementResolver (kèm nhóm theo vị trí).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolverRows(Builder $query): array
    {
        return $query->with('positions')->get()->map(fn (StaffingRequirement $r) => self::row($r))->all();
    }

    public static function row(StaffingRequirement $r): array
    {
        return [
            ...$r->getAttributes(),
            'date' => $r->date?->toDateString(),
            'effective_from' => $r->effective_from?->toDateString(),
            'effective_to' => $r->effective_to?->toDateString(),
            'positions' => $r->relationLoaded('positions')
                ? $r->positions->map(fn (StaffingRequirementPosition $p) => $p->only(['kind', 'position_id', 'min_staff', 'target_staff']))->all()
                : [],
        ];
    }

    // ------------------------------------------------------------------ Kiểm tra

    /**
     * Kiểm tra một cấu hình (dữ liệu form đã chuẩn hóa). Nháp được phép thiếu số; đang áp dụng thì phải đầy đủ.
     *
     * @param array<int, array{kind: string, position_id: ?int, applies: bool, min_staff: ?int, target_staff: ?int}> $positions
     * @return array<string, string> [trường => lỗi]
     */
    public function validate(array $data, array $positions, ?int $ignoreId = null): array
    {
        $errors = [];
        $active = ($data['status'] ?? StaffingRequirement::STATUS_ACTIVE) === StaffingRequirement::STATUS_ACTIVE;
        $pair = function (string $min, string $target, string $label, bool $required) use (&$errors, $data, $active): void {
            $a = $data[$min] ?? null;
            $b = $data[$target] ?? null;

            if ($required && $active && $a === null) {
                $errors[$min] = "Nhập {$label} tối thiểu (hoặc lưu nháp).";
            }

            if ($a !== null && $a < 0) {
                $errors[$min] = 'Không được âm.';
            }

            if ($b !== null && $b < 0) {
                $errors[$target] = 'Không được âm.';
            }

            if ($a !== null && $b !== null && $a > $b) {
                $errors[$target] = "Mục tiêu không được nhỏ hơn tối thiểu ({$a}).";
            }

            if ($active && $a === null && $b !== null) {
                $errors[$min] = 'Đã nhập mục tiêu thì phải nhập tối thiểu.';
            }
        };

        $pair('min_staff', 'target_staff', 'số người', true);
        $pair('min_male', 'target_male', 'nam', false);
        $pair('min_female', 'target_female', 'nữ waiter', false);

        foreach (['min_male', 'min_female'] as $field) {
            if (($data[$field] ?? null) !== null && ($data['target_staff'] ?? null) !== null && $data[$field] > $data['target_staff']) {
                $errors[$field] = 'Không được lớn hơn tổng số người mục tiêu.';
            }
        }

        if (($data['max_staff'] ?? null) !== null && ($data['target_staff'] ?? null) !== null && $data['max_staff'] < $data['target_staff']) {
            $errors['max_staff'] = 'Tối đa không được nhỏ hơn mục tiêu.';
        }

        if (($data['effective_from'] ?? null) && ($data['effective_to'] ?? null) && $data['effective_from'] > $data['effective_to']) {
            $errors['effective_to'] = 'Ngày kết thúc phải sau ngày bắt đầu hiệu lực.';
        }

        if (($data['date'] ?? null) && ($data['weekday'] ?? null)) {
            $errors['date'] = 'Chọn một: ngày cụ thể hoặc thứ trong tuần.';
        }

        if ((($data['count_start_time'] ?? null) === null) !== (($data['count_end_time'] ?? null) === null)) {
            $errors['count_end_time'] = 'Khung đếm cần cả giờ bắt đầu và giờ kết thúc.';
        } elseif (($data['count_start_time'] ?? null) && $data['count_start_time'] >= $data['count_end_time']) {
            $errors['count_end_time'] = 'Giờ kết thúc khung đếm phải sau giờ bắt đầu.';
        }

        $seen = [];

        foreach ($positions as $k => $position) {
            if (! $position['applies']) {
                continue;
            }

            $key = $position['kind'] . ':' . ($position['position_id'] ?? 0);

            if (isset($seen[$key])) {
                $errors["positions.{$k}.position_id"] = 'Nhóm này đã có ở dòng khác.';
            }

            $seen[$key] = true;

            if ($position['kind'] === StaffingRequirementPosition::KIND_POSITION && ! $position['position_id']) {
                $errors["positions.{$k}.position_id"] = 'Chọn vị trí.';
            }

            if ($active && $position['min_staff'] === null) {
                $errors["positions.{$k}.min_staff"] = 'Nhóm đang áp dụng: nhập số tối thiểu (hoặc bỏ chọn "áp dụng").';
            }

            foreach (['min_staff', 'target_staff'] as $field) {
                if ($position[$field] !== null && $position[$field] < 0) {
                    $errors["positions.{$k}.{$field}"] = 'Không được âm.';
                }
            }

            if ($position['min_staff'] !== null && $position['target_staff'] !== null && $position['min_staff'] > $position['target_staff']) {
                $errors["positions.{$k}.target_staff"] = 'Mục tiêu không được nhỏ hơn tối thiểu.';
            }
        }

        if ($active && ! $errors && ($conflicts = $this->conflictsFor($data, $positions, $ignoreId))) {
            $errors['conflict'] = 'Mâu thuẫn với dòng định biên đang áp dụng cùng mức ưu tiên và hiệu lực chồng nhau: '
                . implode('; ', array_map(fn (StaffingRequirement $r) => "#{$r->id} ({$r->scheduleLabel()}, {$r->effectiveLabel()})", $conflicts))
                . '. Sửa dòng đó, đặt khoảng hiệu lực không chồng nhau, hoặc lưu nháp.';
        }

        return $errors;
    }

    /**
     * Dòng đang áp dụng cùng outlet × ca × điều kiện (ngày / thứ / tầng trên – cùng mức ưu tiên), hiệu lực chồng nhau và giá trị khác.
     *
     * @return StaffingRequirement[]
     */
    public function conflictsFor(array $data, array $positions, ?int $ignoreId = null): array
    {
        $candidate = [...$data, 'positions' => array_values(array_filter($positions, fn ($p) => $p['applies']))];

        return StaffingRequirement::query()
            ->with('positions')
            ->where('status', StaffingRequirement::STATUS_ACTIVE)
            ->where('outlet_id', $data['outlet_id'])
            ->where('shift_template_id', $data['shift_template_id'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->filter(fn (StaffingRequirement $r) => self::sameCondition(self::row($r), $candidate)
                && self::overlaps(self::row($r), $candidate)
                && RequirementResolver::values(self::row($r)) != RequirementResolver::values($candidate))
            ->values()
            ->all();
    }

    private static function sameCondition(array $a, array $b): bool
    {
        $norm = fn ($row) => [
            ($row['date'] ?? null) ? substr((string) $row['date'], 0, 10) : null,
            ($row['date'] ?? null) ? null : (isset($row['weekday']) && $row['weekday'] !== null ? (int) $row['weekday'] : null),
            isset($row['upper_floor']) && $row['upper_floor'] !== null && $row['upper_floor'] !== '' ? (bool) $row['upper_floor'] : null,
        ];

        return $norm($a) === $norm($b);
    }

    private static function overlaps(array $a, array $b): bool
    {
        $aFrom = $a['effective_from'] ?? null ?: '0000-00-00';
        $aTo = $a['effective_to'] ?? null ?: '9999-12-31';
        $bFrom = $b['effective_from'] ?? null ?: '0000-00-00';
        $bTo = $b['effective_to'] ?? null ?: '9999-12-31';

        return $aFrom <= $bTo && $bFrom <= $aTo;
    }

    // ------------------------------------------------------------------ Lưu

    /**
     * Tạo / cập nhật một dòng định biên cùng nhóm theo vị trí và lịch sử, trong một transaction.
     *
     * @throws ValidationException
     */
    public function save(?StaffingRequirement $model, array $data, array $positions, ?string $reason, ?int $userId): StaffingRequirement
    {
        if ($errors = $this->validate($data, $positions, $model?->getKey())) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($model, $data, $positions, $reason, $userId) {
            $before = $model?->snapshot();
            // Cột tổng người không nhận null: bản nháp thiếu số được lưu 0 nhưng không bao giờ được thuật toán dùng (chỉ dòng active).
            $data['min_staff'] ??= 0;
            $data['target_staff'] ??= $data['min_staff'];
            $values = [...$data, 'updated_by' => $userId, 'source' => $model?->source === 'history' && ! ($data['is_confirmed'] ?? false) ? 'history' : 'manual'];

            if ($model) {
                $model->update($values);
            } else {
                $model = StaffingRequirement::query()->create([...$values, 'created_by' => $userId]);
            }

            $model->positions()->delete();

            foreach ($positions as $position) {
                if ($position['applies']) {
                    $model->positions()->create([
                        'kind' => $position['kind'],
                        'position_id' => $position['kind'] === StaffingRequirementPosition::KIND_POSITION ? $position['position_id'] : null,
                        'min_staff' => $position['min_staff'],
                        'target_staff' => $position['target_staff'],
                        'note' => $position['note'] ?? null,
                    ]);
                }
            }

            $model->unsetRelation('positions');
            $this->log($model, $before ? 'update' : 'create', $before, $model->snapshot(), $reason, $userId);

            return $model;
        });
    }

    public function setStatus(StaffingRequirement $model, string $status, ?string $reason, ?int $userId): StaffingRequirement
    {
        if ($status === StaffingRequirement::STATUS_ACTIVE && ($errors = $this->validate([...self::row($model->loadMissing('positions')), 'status' => $status],
            $model->positions->map(fn ($p) => ['kind' => $p->kind, 'position_id' => $p->position_id, 'applies' => true, 'min_staff' => $p->min_staff, 'target_staff' => $p->target_staff])->all(),
            $model->getKey()))) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($model, $status, $reason, $userId) {
            $before = $model->snapshot();
            $model->update(['status' => $status, 'updated_by' => $userId]);
            $this->log($model, $status === StaffingRequirement::STATUS_ACTIVE ? 'activate' : 'deactivate', $before, $model->snapshot(), $reason, $userId);

            return $model;
        });
    }

    /**
     * Xóa hẳn chỉ dành cho bản nháp chưa từng áp dụng; dòng đã dùng thì ngừng hiệu lực để giữ lịch sử.
     */
    public function delete(StaffingRequirement $model, ?int $userId): bool
    {
        $everActive = $model->status !== StaffingRequirement::STATUS_DRAFT
            || $model->revisions()->get()->contains(fn ($r) => $r->action === 'activate' || ($r->after['status'] ?? null) === StaffingRequirement::STATUS_ACTIVE);

        if ($everActive) {
            $this->setStatus($model, StaffingRequirement::STATUS_INACTIVE, 'Xóa → ngừng hiệu lực (dòng đã từng áp dụng, giữ lịch sử)', $userId);

            return false;
        }

        DB::transaction(function () use ($model, $userId): void {
            $this->log($model, 'delete', $model->snapshot(), null, 'Xóa bản nháp', $userId);
            $model->positions()->delete();
            $model->delete();
        });

        return true;
    }

    private function log(StaffingRequirement $model, string $action, ?array $before, ?array $after, ?string $reason, ?int $userId): void
    {
        StaffingRequirementRevision::query()->create([
            'requirement_id' => $model->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'user_id' => $userId,
        ]);
    }

    // ------------------------------------------------------------------ Xem trước ảnh hưởng

    /**
     * Ảnh hưởng của cấu hình mới so với hiện tại lên lịch đã tạo (hôm nay → IMPACT_WEEKS tuần tới):
     * với mỗi ngày outlet × ca bị đổi định biên, so số người thực tế (tổng, nam, nữ waiter trực tiếp) với mức cũ / mới.
     */
    public function impact(?StaffingRequirement $model, array $data, array $positions): array
    {
        $outletId = (int) $data['outlet_id'];
        $shiftId = (int) $data['shift_template_id'];
        $from = CarbonImmutable::today();
        $to = $from->addWeeks(self::IMPACT_WEEKS);
        $currentRows = self::resolverRows(StaffingRequirement::query()->where('status', StaffingRequirement::STATUS_ACTIVE)->where('outlet_id', $outletId)->where('shift_template_id', $shiftId));
        $proposed = array_values(array_filter($currentRows, fn ($row) => (int) $row['id'] !== (int) $model?->getKey()));

        if (($data['status'] ?? null) === StaffingRequirement::STATUS_ACTIVE) {
            $proposed[] = [...$data, 'id' => $model?->getKey() ?? PHP_INT_MAX, 'positions' => array_values(array_filter($positions, fn ($p) => $p['applies']))];
        }

        $old = new RequirementResolver($currentRows);
        $new = new RequirementResolver($proposed);
        $flags = OutletDay::query()->where('outlet_id', $outletId)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get()->keyBy(fn ($d) => $d->date->toDateString());
        $actual = $this->actualStaffing($outletId, $shiftId, $from, $to);
        $published = $actual['published'];
        $rows = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $flag = $flags[$date] ?? null;
            $a = $old->resolve($date, $outletId, $shiftId, (bool) $flag?->upper_floor_open, (bool) $flag?->is_closed);
            $b = $new->resolve($date, $outletId, $shiftId, (bool) $flag?->upper_floor_open, (bool) $flag?->is_closed);
            $sig = fn ($r) => $r ? [$r->min, $r->target, $r->minMale, $r->targetMale, $r->minFemale, $r->targetFemale, $r->positionMins, $r->positionTargets, $r->countWindow] : null;

            if ($sig($a) == $sig($b)) {
                continue;
            }

            $have = $actual['days'][$date] ?? ['total' => 0, 'male' => 0, 'female' => 0];
            $status = fn ($r) => match (true) {
                ! $r => 'none',
                $have['total'] < $r->min || $have['male'] < $r->minMale || $have['female'] < $r->minFemale => 'short_min',
                $have['total'] < $r->target || $have['male'] < $r->targetMale || $have['female'] < $r->targetFemale => 'short_target',
                default => 'ok',
            };

            $rows[] = [
                'date' => $date,
                'actual' => $have,
                'scheduled' => isset($actual['days'][$date]),
                'published' => isset($published[$date]),
                'old' => $a ? ['min' => $a->min, 'target' => $a->target, 'min_male' => $a->minMale, 'min_female' => $a->minFemale] : null,
                'new' => $b ? ['min' => $b->min, 'target' => $b->target, 'min_male' => $b->minMale, 'min_female' => $b->minFemale] : null,
                'old_status' => $status($a),
                'new_status' => $status($b),
            ];
        }

        $worse = array_values(array_filter($rows, fn ($r) => $r['scheduled'] && $r['new_status'] === 'short_min' && $r['old_status'] !== 'short_min'));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'changed_days' => count($rows),
            'scheduled_days' => count(array_filter($rows, fn ($r) => $r['scheduled'])),
            'published_days' => count(array_filter($rows, fn ($r) => $r['published'])),
            'newly_short' => $worse,
            'conflicts' => array_values($new->conflicts()),
        ];
    }

    /**
     * Số người thực tế đã xếp theo ngày cho outlet × ca: tổng, nam, nữ waiter trực tiếp (người hỗ trợ (L) không tính).
     */
    private function actualStaffing(int $outletId, int $shiftId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $managerPositions = Position::query()->where('is_manager', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $femaleNames = array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) config('plugins.restaurant-roster.general.scheduling.female_staff_positions', ['Waiter']));
        $femaleStaff = Position::query()->get(['id', 'name'])
            ->filter(fn ($p) => ! in_array((int) $p->id, $managerPositions, true) && (! $femaleNames || in_array(mb_strtolower(trim((string) $p->name)), $femaleNames, true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $station = ShiftTemplate::query()->whereKey($shiftId)->value('support_station');
        $days = [];
        $published = [];

        Shift::query()
            ->with('employee:id,gender,position_id')
            ->where('outlet_id', $outletId)->where('shift_template_id', $shiftId)->where('kind', Shift::KIND_SHIFT)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->each(function (Shift $shift) use (&$days, &$published, $femaleStaff, $station): void {
                $date = $shift->date->toDateString();
                $days[$date] ??= ['total' => 0, 'male' => 0, 'female' => 0];

                if ($shift->status === Shift::STATUS_PUBLISHED) {
                    $published[$date] = true;
                }

                if (in_array('L', $shift->tags ?: [], true) && ! $station) {
                    return;
                }

                $days[$date]['total']++;
                $days[$date]['male'] += $shift->employee?->gender === 'male' ? 1 : 0;
                $days[$date]['female'] += $shift->employee?->gender === 'female' && in_array((int) $shift->employee?->position_id, $femaleStaff, true) ? 1 : 0;
            });

        return ['days' => $days, 'published' => $published];
    }

    // ------------------------------------------------------------------ Tổng quan

    /**
     * Ma trận định biên một tuần: outlet × ca × 7 ngày → định biên đang áp dụng, "chưa cấu hình" hoặc "xung đột".
     * Cặp outlet × ca được xét: có dòng định biên (bất kỳ trạng thái) hoặc có ca làm trong 4 tuần gần nhất.
     */
    public function matrix(CarbonImmutable $weekStart): array
    {
        $outlets = Outlet::query()->wherePublished()->orderBy('order')->get(['id', 'code', 'name'])->keyBy('id');
        $templates = ShiftTemplate::query()->wherePublished()->orderBy('order')->get(['id', 'code'])->keyBy('id');
        $pairs = StaffingRequirement::query()->distinct()->get(['outlet_id', 'shift_template_id'])
            ->map(fn ($r) => (int) $r->outlet_id . '|' . (int) $r->shift_template_id)->all();
        $recent = Shift::query()->where('kind', Shift::KIND_SHIFT)->whereNotNull('outlet_id')->whereNotNull('shift_template_id')
            ->whereDate('date', '>=', $weekStart->subWeeks(4)->toDateString())->whereDate('date', '<', $weekStart->addWeek()->toDateString())
            ->distinct()->get(['outlet_id', 'shift_template_id'])->map(fn ($s) => (int) $s->outlet_id . '|' . (int) $s->shift_template_id)->all();
        $pairs = array_values(array_unique([...$pairs, ...$recent]));
        $resolver = new RequirementResolver(self::resolverRows(StaffingRequirement::query()->where('status', StaffingRequirement::STATUS_ACTIVE)));
        $flags = OutletDay::query()->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekStart->addDays(6)->toDateString())
            ->get()->keyBy(fn ($d) => $d->outlet_id . '|' . $d->date->toDateString());
        $positionNames = Position::query()->pluck('name', 'id');
        $rows = [];

        foreach ($pairs as $pair) {
            [$outletId, $shiftId] = array_map('intval', explode('|', $pair));

            if (! isset($outlets[$outletId], $templates[$shiftId])) {
                continue;
            }

            $cells = [];

            foreach (range(0, 6) as $offset) {
                $date = $weekStart->addDays($offset)->toDateString();
                $flag = $flags[$outletId . '|' . $date] ?? null;

                if ($flag?->is_closed) {
                    $cells[$date] = ['state' => 'closed'];

                    continue;
                }

                $requirement = $resolver->resolve($date, $outletId, $shiftId, (bool) $flag?->upper_floor_open);
                $cells[$date] = match (true) {
                    isset($resolver->conflicts()["{$date}|{$outletId}|{$shiftId}"]) => ['state' => 'conflict', 'ids' => $resolver->conflicts()["{$date}|{$outletId}|{$shiftId}"]['ids']],
                    $requirement === null => ['state' => 'missing'],
                    default => ['state' => 'ok', 'id' => $requirement->sourceId, 'min' => $requirement->min, 'target' => $requirement->target,
                        'male' => $requirement->minMale ? "{$requirement->minMale}/{$requirement->targetMale}" : null,
                        'female' => $requirement->minFemale ? "{$requirement->minFemale}/{$requirement->targetFemale}" : null,
                        'groups' => array_map(fn ($key, $min) => ($key === RequirementResolver::MANAGER_GROUP ? 'QL' : ($positionNames[(int) $key] ?? $key)) . " {$min}",
                            array_keys($requirement->positionMins), $requirement->positionMins)],
                };
            }

            $rows[] = ['outlet' => $outlets[$outletId], 'template' => $templates[$shiftId], 'cells' => $cells];
        }

        usort($rows, fn ($a, $b) => [$a['outlet']->order ?? 0, $a['outlet']->code, $a['template']->code] <=> [$b['outlet']->order ?? 0, $b['outlet']->code, $b['template']->code]);

        return $rows;
    }

    /**
     * Chỉ số tổng quan lấy từ database (theo ma trận tuần đang xem).
     */
    public function overview(array $matrix): array
    {
        $byOutlet = [];
        $missing = 0;
        $conflicts = [];

        foreach ($matrix as $row) {
            $outletId = $row['outlet']->id;
            $byOutlet[$outletId] ??= ['configured' => false, 'complete' => true];

            foreach ($row['cells'] as $date => $cell) {
                if ($cell['state'] === 'ok') {
                    $byOutlet[$outletId]['configured'] = true;
                } elseif ($cell['state'] === 'missing') {
                    $byOutlet[$outletId]['complete'] = false;
                    $missing++;
                } elseif ($cell['state'] === 'conflict') {
                    $byOutlet[$outletId]['complete'] = false;
                    $conflicts[$date . '|' . $outletId . '|' . $row['template']->id] = true;
                }
            }
        }

        $recentRuns = Run::query()->where('created_at', '>=', now()->subDays(30))->whereNotIn('status', ['discarded', 'stale', 'error'])->pluck('id');

        return [
            'outlets_configured' => count(array_filter($byOutlet, fn ($o) => $o['configured'])),
            'outlets_complete' => count(array_filter($byOutlet, fn ($o) => $o['configured'] && $o['complete'])),
            'missing_cells' => $missing,
            'conflict_cells' => count($conflicts),
            'drafts' => StaffingRequirement::query()->where('status', StaffingRequirement::STATUS_DRAFT)->count(),
            'needs_review' => StaffingRequirement::query()->where('status', StaffingRequirement::STATUS_ACTIVE)->where('is_confirmed', false)->count(),
            'shortage_warnings' => Violation::query()->whereIn('run_id', $recentRuns)->where('category', 'shortage')->count(),
            'female_staff' => Employee::query()->wherePublished()->where('gender', 'female')->count(),
        ];
    }
}
