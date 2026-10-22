<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Run;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\Violation;
use Botble\RestaurantRoster\Scheduling\HeuristicSolver;
use Botble\RestaurantRoster\Scheduling\ResultBuilder;
use Botble\RestaurantRoster\Scheduling\ScheduleState;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use Botble\RestaurantRoster\Scheduling\SolverInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Điều phối một lần xếp lịch: dựng bài toán → giải → lưu lần chạy & cảnh báo → (quản lý) áp dụng.
 * Không bao giờ tự công bố lịch; kết quả luôn ghi vào lịch ở trạng thái nháp.
 */
class RosterScheduler
{
    public function __construct(
        protected ProblemBuilder $builder,
        protected RosterAssigner $assigner,
        protected ?SolverInterface $solver = null,
    ) {
        $this->solver ??= new HeuristicSolver();
    }

    /**
     * Tạo lịch / tối ưu lại trong phạm vi. Ô đã khóa và ô ngoài phạm vi giữ nguyên.
     */
    public function run(CarbonInterface $from, CarbonInterface $to, ?array $outletIds, string $type, ?int $userId, array $overrides = []): Run
    {
        $lock = Cache::lock('roster-scheduler-run', 120);

        if (! $lock->get()) {
            throw new RuntimeException('Đang có một lần xếp lịch khác chạy. Vui lòng đợi lần đó hoàn tất.');
        }

        $run = Run::query()->create([
            'type' => $type,
            'scope_from' => $from->toDateString(),
            'scope_to' => $to->toDateString(),
            'outlet_ids' => $outletIds,
            'status' => 'running',
            'solver' => $this->solver->name(),
            'created_by' => $userId,
            'settings' => ['schedule_stamp' => $this->scheduleStamp($from, $to)],
        ]);

        try {
            $problem = $this->builder->build($from, $to, $outletIds, ['stability_first' => $type === 'reoptimize', ...$overrides]);

            // Định biên mâu thuẫn (hai dòng cùng mức ưu tiên, giá trị khác nhau): không chạy, không tự chọn một dòng.
            if ($this->builder->conflicts) {
                $run->update(['status' => SolveResult::ERROR, 'explanation' => $this->conflictMessage($this->builder->conflicts)]);

                return $run->refresh();
            }

            @set_time_limit((int) ceil($problem->rules->timeLimitSeconds) + 60);

            $result = $this->solver->solve($problem);
            $this->store($run, $result, $problem->rules->toArray(), $problem->hash());
            $this->rememberStaffing($run);
        } catch (Throwable $exception) {
            Log::error('Roster scheduling run failed', ['run_id' => $run->getKey(), 'exception' => $exception]);
            $run->update(['status' => SolveResult::ERROR, 'explanation' => 'Lỗi hệ thống: ' . $exception->getMessage()]);
        } finally {
            $lock->release();
        }

        return $run->refresh();
    }

    /**
     * Kiểm tra lịch hiện tại (không thay đổi gì): vi phạm cứng, thiếu người, công bằng.
     */
    public function validate(CarbonInterface $from, CarbonInterface $to, ?array $outletIds, ?int $userId): Run
    {
        $run = Run::query()->create([
            'type' => 'validate',
            'scope_from' => $from->toDateString(),
            'scope_to' => $to->toDateString(),
            'outlet_ids' => $outletIds,
            'status' => 'running',
            'solver' => 'validator',
            'created_by' => $userId,
            'settings' => ['schedule_stamp' => $this->scheduleStamp($from, $to)],
        ]);

        try {
            $problem = $this->builder->build($from, $to, $outletIds, freezeCurrent: true);

            if ($this->builder->conflicts) {
                $run->update(['status' => SolveResult::ERROR, 'explanation' => $this->conflictMessage($this->builder->conflicts)]);

                return $run->refresh();
            }

            $state = new ScheduleState($problem);
            $result = (new ResultBuilder())->build($state, true, ['solver' => 'validator', 'duration_ms' => 0]);
            $this->store($run, $result, $problem->rules->toArray(), $problem->hash());
            // Kiểm tra không tạo phương án mới; chỉ ghi nhận trạng thái.
            $run->update(['status' => $result->hardConstraintViolations ? 'invalid' : ($result->unfilledRequirements ? 'has_warnings' : 'valid')]);
        } catch (Throwable $exception) {
            Log::error('Roster validation failed', ['run_id' => $run->getKey(), 'exception' => $exception]);
            $run->update(['status' => SolveResult::ERROR, 'explanation' => 'Lỗi hệ thống: ' . $exception->getMessage()]);
        }

        return $run->refresh();
    }

    /**
     * Áp dụng phương án vào lịch (trạng thái nháp). Từ chối nếu lịch đã bị sửa sau khi chạy,
     * để kết quả cũ không ghi đè chỉnh sửa mới.
     *
     * @return array{changed: int, kept: int}
     */
    public function apply(Run $run, ?int $userId): array
    {
        if (! in_array($run->status, [SolveResult::FEASIBLE, SolveResult::PARTIAL, SolveResult::INFEASIBLE], true)) {
            throw new RuntimeException('Chỉ áp dụng được lần chạy đã hoàn tất và chưa áp dụng.');
        }

        $stamp = $this->scheduleStamp($run->scope_from, $run->scope_to);

        if (($run->settings['schedule_stamp'] ?? null) !== $stamp) {
            $run->update(['status' => 'stale']);

            throw new RuntimeException('Lịch đã được chỉnh sửa sau lần chạy này. Hãy chạy tối ưu lại để không ghi đè thay đổi mới.');
        }

        $templates = ShiftTemplate::query()->get()->keyBy('id');
        $employees = Employee::query()->get()->keyBy('id');
        $changed = 0;
        $kept = 0;

        DB::transaction(function () use ($run, $userId, $templates, $employees, &$changed, &$kept): void {
            $now = now()->format('Y-m-d H:i:s');
            $upserts = [];
            $logs = [];
            $existing = Shift::query()
                ->whereBetween('date', [$run->scope_from->toDateString(), $run->scope_to->toDateString()])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Shift $s) => $s->employee_id . '|' . $s->date->toDateString());

            foreach ($run->proposal['assignments'] ?? [] as $row) {
                $employee = $employees[$row['employee_id']] ?? null;
                $current = $existing[$row['employee_id'] . '|' . $row['date']] ?? null;

                if (! $employee || $row['locked'] || $current?->is_locked || $current?->kind === Shift::KIND_LEAVE) {
                    $kept++;

                    continue;
                }

                $isWork = $row['kind'] === 'shift';
                $same = $current
                    && ($isWork
                        ? $current->kind === Shift::KIND_SHIFT && (int) $current->shift_template_id === (int) $row['shift_id'] && (int) $current->outlet_id === (int) $row['outlet_id']
                            && in_array('L', $current->tags ?: [], true) === (! empty($row['support']) || (bool) $templates[$row['shift_id']]?->support_station)
                        : $current->kind === Shift::KIND_OFF);

                if ($same) {
                    $kept++;

                    continue;
                }

                $template = $isWork ? $templates[$row['shift_id']] : null;
                $outletTags = $isWork ? $this->assigner->tagsForOutlet($employee, (int) $row['outlet_id'], $template) : [];
                $keepTags = $isWork && $current && (int) $current->shift_template_id === (int) $row['shift_id'] && (int) $current->outlet_id === (int) $row['outlet_id']
                    ? array_diff($current->tags ?: [], ['L', ...$outletTags])
                    : [];

                $values = $this->assigner->values($employee, CarbonImmutable::parse($row['date']), [
                    'kind' => $isWork ? Shift::KIND_SHIFT : Shift::KIND_OFF,
                    'template' => $template,
                    'tags' => array_values(array_unique(array_filter([...$keepTags, ...$outletTags, ! empty($row['support']) ? 'L' : null]))),
                    'outlet_id' => $isWork ? (int) $row['outlet_id'] : null,
                    'note' => $row['unavailable_reason'] ?? null,
                    'source' => 'engine',
                    'run_id' => $run->getKey(),
                ], $userId);

                // Ghi hàng loạt (đường truyền tới database chậm): chuẩn hóa json / datetime cho câu upsert.
                $upserts[] = [
                    'employee_id' => $employee->getKey(),
                    'date' => $row['date'],
                    ...array_map(fn ($v) => $v instanceof CarbonInterface ? $v->format('Y-m-d H:i:s') : $v, $values),
                    'tags' => $values['tags'] ? json_encode($values['tags']) : null,
                    'is_locked' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $logs[] = [
                    'employee_id' => $employee->getKey(),
                    'date' => $row['date'],
                    'before' => $current ? json_encode($this->snapshot($current), JSON_UNESCAPED_UNICODE) : null,
                    'after' => json_encode([
                        'kind' => $values['kind'],
                        'outlet_id' => $values['outlet_id'],
                        'shift_template_id' => $values['shift_template_id'],
                        'notation' => $isWork ? $template->code . collect($values['tags'] ?: [])->map(fn ($t) => "({$t})")->implode('') : 'OFF',
                        'status' => $values['status'],
                        'is_locked' => false,
                    ], JSON_UNESCAPED_UNICODE),
                    'action' => 'apply_run',
                    'run_id' => $run->getKey(),
                    'reason' => $row['unavailable_reason'] ? 'Nghỉ: ' . $row['unavailable_reason'] : 'Áp dụng kết quả xếp lịch #' . $run->getKey(),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $changed++;
            }

            $columns = array_values(array_diff(array_keys($upserts[0] ?? []), ['employee_id', 'date', 'created_at']));

            foreach (array_chunk($upserts, 150) as $chunk) {
                Shift::query()->upsert($chunk, ['employee_id', 'date'], $columns);
            }

            foreach (array_chunk($logs, 150) as $chunk) {
                ChangeLog::query()->insert($chunk);
            }

            $run->update(['status' => 'applied', 'applied_by' => $userId, 'applied_at' => now()]);

            // Các lần chạy khác cùng phạm vi chưa áp dụng giờ đã cũ.
            Run::query()
                ->whereKeyNot($run->getKey())
                ->whereIn('status', [SolveResult::FEASIBLE, SolveResult::PARTIAL, SolveResult::INFEASIBLE])
                ->whereDate('scope_from', '<=', $run->scope_to)
                ->whereDate('scope_to', '>=', $run->scope_from)
                ->update(['status' => 'stale']);
        });

        return ['changed' => $changed, 'kept' => $kept];
    }

    /**
     * Dấu phiên bản của lịch trong phạm vi; đổi khi có ô thêm/sửa/xóa.
     */
    public function scheduleStamp(CarbonInterface $from, CarbonInterface $to): string
    {
        $row = Shift::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) as c, MAX(updated_at) as u, SUM(id) as s')
            ->first();

        return sha1(implode('|', [$row?->c, $row?->u, $row?->s]));
    }

    protected function store(Run $run, SolveResult $result, array $rules, string $hash): void
    {
        $data = $result->toArray();

        DB::transaction(function () use ($run, $result, $data, $rules, $hash): void {
            $run->update([
                'status' => $result->status,
                'input_hash' => $hash,
                'settings' => [...($run->settings ?? []), 'rules' => $rules],
                'metrics' => [
                    'objective' => $data['soft_constraint_scores']['objective'] ?? [],
                    'fairness' => $data['soft_constraint_scores']['fairness'] ?? [],
                    'unfilled_min' => count(array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'min')),
                    'unfilled_target' => count(array_filter($result->unfilledRequirements, fn ($u) => $u['level'] === 'target')),
                    'hard_violations' => count($result->hardConstraintViolations),
                    'changed_cells' => count(array_filter($result->assignments, fn ($a) => $a['changed'])),
                    'solver' => $result->solverMetadata,
                ],
                'proposal' => $data,
                'explanation' => implode("\n", $result->explanation),
                'duration_ms' => $result->solverMetadata['duration_ms'] ?? null,
            ]);

            $rows = [];
            $now = now();

            foreach ($result->hardConstraintViolations as $v) {
                $rows[] = ['run_id' => $run->getKey(), 'date' => $v['date'], 'outlet_id' => $v['outlet_id'], 'shift_template_id' => $v['shift_id'], 'employee_id' => $v['employee_id'],
                    'category' => 'hard', 'severity' => 'critical', 'code' => $v['code'], 'message' => mb_substr(($v['employee_name'] ?? '') . ': ' . $v['message'], 0, 500), 'suggestions' => null, 'created_at' => $now, 'updated_at' => $now];
            }

            foreach ($result->unfilledRequirements as $u) {
                $rows[] = ['run_id' => $run->getKey(), 'date' => $u['date'], 'outlet_id' => $u['outlet_id'], 'shift_template_id' => $u['shift_id'], 'employee_id' => null,
                    'category' => 'shortage', 'severity' => $u['level'] === 'min' ? 'high' : 'medium', 'code' => $u['level'] === 'min' ? 'below_min' : 'below_target',
                    'message' => mb_substr(sprintf('%s %s: có %d/%d người (tối thiểu %d)%s', $u['outlet_name'], $u['shift_code'], $u['assigned'], $u['target'], $u['min'],
                        ($u['min_male'] || $u['target_male'] ? sprintf(', nam %d/%d', $u['assigned_male'], $u['target_male']) : '')
                        . (($u['min_female'] ?? 0) || ($u['target_female'] ?? 0) ? sprintf(', nữ waiter trực tiếp %d/%d (tối thiểu %d)', $u['assigned_female'], $u['target_female'], $u['min_female']) : '')), 0, 500),
                    'suggestions' => json_encode(array_column($u['proposals'], 'text'), JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now];
            }

            foreach ($result->warnings as $w) {
                $rows[] = ['run_id' => $run->getKey(), 'date' => $w['date'], 'outlet_id' => $w['outlet_id'], 'shift_template_id' => null, 'employee_id' => null,
                    'category' => 'operations', 'severity' => 'medium', 'code' => $w['code'], 'message' => mb_substr($w['message'], 0, 500),
                    'suggestions' => null, 'created_at' => $now, 'updated_at' => $now];
            }

            foreach ($result->employeeWorkload as $w) {
                if ($w['heavy_transitions'] >= 3) {
                    $rows[] = ['run_id' => $run->getKey(), 'date' => null, 'outlet_id' => null, 'shift_template_id' => null, 'employee_id' => $w['employee_id'],
                        'category' => 'fairness', 'severity' => 'low', 'code' => 'many_heavy', 'message' => "{$w['name']}: {$w['heavy_transitions']} lần chuyển ca nặng trong kỳ",
                        'suggestions' => null, 'created_at' => $now, 'updated_at' => $now];
                }

                if ($w['unapproved_request_conflicts'] > 0) {
                    $rows[] = ['run_id' => $run->getKey(), 'date' => null, 'outlet_id' => null, 'shift_template_id' => null, 'employee_id' => $w['employee_id'],
                        'category' => 'preference', 'severity' => 'low', 'code' => 'pending_request', 'message' => "{$w['name']}: được xếp vào {$w['unapproved_request_conflicts']} ngày đã đăng ký bận (đơn chưa duyệt)",
                        'suggestions' => null, 'created_at' => $now, 'updated_at' => $now];
                }

                if ($w['off_home_outlet'] >= 3) {
                    $rows[] = ['run_id' => $run->getKey(), 'date' => null, 'outlet_id' => null, 'shift_template_id' => null, 'employee_id' => $w['employee_id'],
                        'category' => 'operations', 'severity' => 'low', 'code' => 'many_transfers', 'message' => "{$w['name']}: {$w['off_home_outlet']} ca ngoài outlet chính",
                        'suggestions' => null, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                Violation::query()->insert($chunk);
            }
        });
    }

    /**
     * Lưu kèm lần chạy ảnh chụp các dòng định biên đã dùng (để giải thích kết quả về sau, kể cả khi định biên đổi).
     */
    protected function rememberStaffing(Run $run): void
    {
        $snapshot = \Botble\RestaurantRoster\Models\StaffingRequirement::query()->with('positions')
            ->whereIn('id', array_keys($this->builder->usedRequirementIds))->get()
            ->mapWithKeys(fn ($r) => [$r->getKey() => [...$r->snapshot(), 'updated_at' => $r->updated_at?->toDateTimeString()]])->all();

        $names = \Botble\RestaurantRoster\Models\Employee::query()->whereIn('id', collect($this->builder->nightWeeks)->pluck('ids')->flatten()->all())->pluck('name', 'id');
        $nightPlan = collect($this->builder->nightWeeks)->map(fn ($week) => [...$week, 'names' => array_map(fn ($id) => (string) ($names[$id] ?? "#{$id}"), $week['ids'])])->all();

        $run->update(['settings' => [...($run->settings ?? []), 'staffing_snapshot' => $snapshot, 'night_plan' => $nightPlan]]);
    }

    /**
     * @param array<int, array{date: string, outlet_id: int, shift_id: int, ids: int[]}> $conflicts
     */
    protected function conflictMessage(array $conflicts): string
    {
        $outlets = \Botble\RestaurantRoster\Models\Outlet::query()->pluck('code', 'id');
        $templates = ShiftTemplate::query()->pluck('code', 'id');
        $lines = [];

        foreach ($conflicts as $c) {
            $key = ($outlets[$c['outlet_id']] ?? $c['outlet_id']) . ' ' . ($templates[$c['shift_id']] ?? $c['shift_id']) . ': dòng #' . implode(', #', $c['ids']);
            $lines[$key][] = date('d/m', strtotime($c['date']));
        }

        return "Không chạy được: định biên mâu thuẫn (hai dòng cùng mức ưu tiên, hiệu lực chồng nhau, giá trị khác nhau) – thuật toán không tự chọn một dòng.
"
            . implode("
", array_map(fn ($key, $dates) => "• {$key} (ngày " . implode(', ', array_slice($dates, 0, 10)) . (count($dates) > 10 ? '…' : '') . ')', array_keys($lines), $lines))
            . "
Vào Định biên nhân sự để sửa (xem cột Xung đột).";
    }

    protected function snapshot(Shift $shift): array
    {
        return [
            'kind' => $shift->kind,
            'outlet_id' => $shift->outlet_id,
            'shift_template_id' => $shift->shift_template_id,
            'notation' => $shift->notation,
            'status' => $shift->status,
            'is_locked' => (bool) $shift->is_locked,
        ];
    }
}
