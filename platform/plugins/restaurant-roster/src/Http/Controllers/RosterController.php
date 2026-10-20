<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Facades\Assets;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Run;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Services\OutletRotationService;
use Botble\RestaurantRoster\Services\RosterAssigner;
use Botble\RestaurantRoster\Services\RosterConflictChecker;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class RosterController extends BaseController
{
    public function __construct(
        protected RosterConflictChecker $checker,
        protected RosterAssigner $assigner
    ) {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()->add('Xếp lịch nhà hàng', route('roster.index'));
    }

    public function index(Request $request)
    {
        $this->pageTitle('Bảng xếp ca');

        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css')
            ->addScriptsDirectly('vendor/core/plugins/restaurant-roster/js/roster.js');

        $outlets = Outlet::query()
            ->wherePublished()
            ->orderBy('order')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $templates = ShiftTemplate::query()
            ->wherePublished()
            ->orderBy('order')
            ->orderBy('start_time')
            ->get();

        return view('plugins/restaurant-roster::roster.index', [
            'outlets' => $outlets,
            'templates' => $templates,
            'leaveTypes' => config('plugins.restaurant-roster.general.leave_types', []),
            'shiftTags' => $this->assigner->tagDefinitions(),
            'canManage' => $this->canManage($request),
            'waiterSwap' => $request->user()?->hasPermission('roster.settings') ? rescue(fn () => app(OutletRotationService::class)->preview(), null, false) : null,
            'grid' => $this->buildGrid($request),
        ]);
    }

    public function grid(Request $request)
    {
        return $this->gridResponse($request);
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:roster_employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'action' => ['required', Rule::in(['shift', 'off', 'leave', 'clear', 'notation'])],
            'shift_template_id' => ['required_if:action,shift', 'nullable', 'integer', 'exists:roster_shift_templates,id'],
            'leave_type' => ['required_if:action,leave', 'nullable', Rule::in(array_keys(config('plugins.restaurant-roster.general.leave_types', [])))],
            'notation' => ['required_if:action,notation', 'nullable', 'string', 'max:40'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', Rule::in(array_keys($this->assigner->tagDefinitions()))],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:12'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $date = Carbon::parse($data['date']);
        $existing = Shift::query()->with('template')->where('employee_id', $employee->getKey())->whereDate('date', $date)->first();

        if ($data['action'] === 'clear') {
            if ($existing) {
                $this->logChange($request, $employee->getKey(), $date, $existing, null, 'delete');
                $existing->delete();
            }

            return $this->gridResponse($request, 'Đã xóa ca.');
        }

        if ($data['action'] === 'notation') {
            try {
                $spec = $this->assigner->parse($data['notation']);
            } catch (InvalidArgumentException $exception) {
                return $this->httpResponse()->setError()->setMessage($exception->getMessage());
            }
        } else {
            $spec = [
                'kind' => $data['action'],
                'template' => $data['action'] === Shift::KIND_SHIFT ? ShiftTemplate::query()->findOrFail($data['shift_template_id']) : null,
                'tags' => $data['tags'] ?? [],
                'overtime' => (float) ($data['overtime_hours'] ?? 0),
                'leave_type' => $data['leave_type'] ?? null,
            ];
        }

        $before = $existing ? $this->snapshot($existing) : null;
        $shift = $this->assigner->assign($employee, $date, [...$spec, 'note' => $data['note'] ?? null, 'source' => 'manual'], $request->user()?->getKey());
        $message = "Đã lưu {$shift->notation} cho {$employee->name}.";

        // Xếp tay trái quy định (giới tính / khu vực / vị trí): vẫn lưu theo quyết định quản lý nhưng cảnh báo rõ.
        if ($warnings = $this->ruleWarnings($employee, $shift)) {
            $message .= ' ⚠ ' . implode(' ', $warnings);
        }

        // Ca chạy tiệc (BQ) do quản lý chọn người có kinh nghiệm: tự khóa để thuật toán xếp những người còn lại xung quanh.
        if (in_array('BQ', $shift->tags ?: [], true) && ! $shift->is_locked) {
            $shift->update(['is_locked' => true]);
            $message .= ' Ô tiệc (BQ) đã tự khóa – xếp tự động sẽ giữ nguyên.';
        }

        // Xếp tay khác ca cứng: giữ quyết định của quản lý bằng cách khóa ô, để "Tối ưu lại" không đổi về ca cứng.
        if ($employee->fixed_shift_template_id && $shift->kind === Shift::KIND_SHIFT
            && (int) $shift->shift_template_id !== (int) $employee->fixed_shift_template_id && ! $shift->is_locked) {
            $shift->update(['is_locked' => true]);
            $message .= ' Ô đã tự khóa vì khác ca cứng ' . ($employee->fixedShift?->code ?? '') . ' – xếp tự động sẽ giữ nguyên.';
        }

        $this->logChange($request, $employee->getKey(), $date, $before, $shift->load('template'), 'manual');

        return $this->gridResponse($request, $message);
    }

    public function copyPreviousWeek(Request $request)
    {
        [$weekStart, $weekEnd] = $this->weekRange($request);
        $employeeIds = $this->employeeQuery($request)->pluck('id');

        $previous = Shift::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$weekStart->copy()->subWeek()->toDateString(), $weekStart->copy()->subDay()->toDateString()])
            ->get();

        $taken = Shift::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get(['employee_id', 'date'])
            ->map(fn (Shift $shift) => $shift->employee_id . '|' . $shift->date->toDateString())
            ->flip();

        $copied = 0;

        DB::transaction(function () use ($previous, $taken, $request, &$copied): void {
            foreach ($previous as $shift) {
                $date = $shift->date->copy()->addWeek();

                if ($taken->has($shift->employee_id . '|' . $date->toDateString())) {
                    continue;
                }

                $copy = $shift->replicate(['status', 'created_by']);
                $copy->date = $date;

                foreach (['start_at', 'end_at', 'split_start_at', 'split_end_at'] as $column) {
                    $copy->{$column} = $shift->{$column}?->copy()->addWeek();
                }

                $copy->status = Shift::STATUS_DRAFT;
                $copy->is_locked = false;
                $copy->source = 'copy';
                $copy->run_id = null;
                $copy->created_by = $request->user()?->getKey();
                $copy->save();

                $copied++;
            }
        });

        return $this->gridResponse(
            $request,
            $copied ? "Đã sao chép {$copied} ca từ tuần trước (bỏ qua các ô đã có ca)." : 'Tuần trước không có ca nào để sao chép vào ô trống.'
        );
    }

    public function publish(Request $request)
    {
        [$weekStart, $weekEnd] = $this->weekRange($request);

        $drafts = Shift::query()
            ->whereIn('employee_id', $this->employeeQuery($request)->pluck('id'))
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->where('status', Shift::STATUS_DRAFT);

        $count = 0;

        DB::transaction(function () use ($drafts, $request, &$count): void {
            $now = now();
            $logs = (clone $drafts)->get(['employee_id', 'date'])->map(fn (Shift $s) => [
                'employee_id' => $s->employee_id, 'date' => $s->date->toDateString(), 'action' => 'publish',
                'user_id' => $request->user()?->getKey(), 'created_at' => $now, 'updated_at' => $now,
            ])->all();
            $count = $drafts->update(['status' => Shift::STATUS_PUBLISHED]);

            foreach (array_chunk($logs, 200) as $chunk) {
                ChangeLog::query()->insert($chunk);
            }
        });

        return $this->gridResponse($request, $count ? "Đã công bố {$count} ca." : 'Không có ca nháp nào cần công bố.');
    }

    /**
     * Xóa lịch theo HÀNG (một nhân viên cả tuần) hoặc CỘT (một ngày của các nhân viên thuộc một outlet chính)
     * để quản lý xếp tay lại. Ô đã khóa và ngày nghỉ phép được giữ, trừ khi chọn xóa cả. preview=1: chỉ đếm để xác nhận.
     */
    public function clearCells(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['row', 'column'])],
            'employee_id' => ['required_if:mode,row', 'nullable', 'integer'],
            'outlet_id_clear' => ['required_if:mode,column', 'nullable', 'integer'],
            'date' => ['required_if:mode,column', 'nullable', 'date_format:Y-m-d'],
            'include_locked' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
        ]);

        [$weekStart, $weekEnd] = $this->weekRange($request);

        if ($data['mode'] === 'row') {
            $employee = Employee::query()->findOrFail($data['employee_id']);
            $label = $employee->name . ' (cả tuần ' . $weekStart->format('d/m') . ' – ' . $weekEnd->format('d/m') . ')';
            $query = Shift::query()->where('employee_id', $employee->getKey())
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()]);
        } else {
            $outlet = Outlet::query()->findOrFail($data['outlet_id_clear']);
            $date = Carbon::parse($data['date']);
            $label = ($outlet->code ?: $outlet->name) . ' ngày ' . $date->format('d/m/Y');
            // Cột tính theo outlet chính của nhân viên.
            $query = Shift::query()->whereDate('date', $date->toDateString())
                ->whereIn('employee_id', Employee::query()->where('outlet_id', $outlet->getKey())
                    ->where('status', BaseStatusEnum::PUBLISHED)->pluck('id'));
        }

        $shifts = $query->with('template:id,code')->get();
        $isKept = fn (Shift $shift) => $shift->is_locked || $shift->kind === Shift::KIND_LEAVE;
        $kept = $shifts->filter($isKept);
        $targets = $request->boolean('include_locked') ? $shifts : $shifts->reject($isKept);

        if ($request->boolean('preview')) {
            return $this->httpResponse()->setData([
                'label' => $label,
                'total' => $targets->count(),
                'published' => $targets->where('status', Shift::STATUS_PUBLISHED)->count(),
                'kept' => $kept->count(),
            ]);
        }

        if ($targets->isEmpty()) {
            return $this->gridResponse($request, "Không có ô nào để xóa ở {$label}.");
        }

        DB::transaction(function () use ($request, $targets, $data): void {
            $logs = $targets->map(fn (Shift $shift) => [
                'employee_id' => $shift->employee_id,
                'date' => $shift->date->toDateString(),
                'before' => json_encode($this->snapshot($shift), JSON_UNESCAPED_UNICODE),
                'after' => null,
                'action' => $data['mode'] === 'row' ? 'clear_row' : 'clear_column',
                'user_id' => $request->user()?->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            ChangeLog::query()->insert($logs);
            Shift::query()->whereIn('id', $targets->pluck('id'))->delete();
        });

        // Lưu bản sao để "Hoàn tác" (30 phút, mỗi người dùng một lần xóa gần nhất).
        Cache::put($this->undoKey($request), [
            'label' => $label,
            'rows' => $targets->map(fn (Shift $shift) => $shift->getRawOriginal())->values()->all(),
        ], now()->addMinutes(30));

        $published = $targets->where('status', Shift::STATUS_PUBLISHED)->count();

        return $this->gridResponse($request, sprintf('Đã xóa %d ô của %s%s%s.', $targets->count(), $label,
            $published ? " (gồm {$published} ca đã công bố)" : '',
            $kept->count() && ! $request->boolean('include_locked') ? ", giữ {$kept->count()} ô đã khóa / nghỉ phép" : ''
        ), ['undo' => $label]);
    }

    /**
     * Hoàn tác lần xóa hàng / cột gần nhất: khôi phục các ô chưa bị xếp lại (ô đã xếp ca mới thì giữ ca mới).
     */
    public function undoClear(Request $request)
    {
        $saved = Cache::pull($this->undoKey($request));

        if (! $saved) {
            return $this->gridResponse($request, 'Không còn gì để hoàn tác (đã quá 30 phút hoặc đã hoàn tác).');
        }

        $restored = 0;

        DB::transaction(function () use ($request, $saved, &$restored): void {
            foreach ($saved['rows'] as $row) {
                $exists = Shift::query()->where('employee_id', $row['employee_id'])->whereDate('date', substr((string) $row['date'], 0, 10))->exists();

                if ($exists) {
                    continue;
                }

                DB::table((new Shift())->getTable())->insert($row);
                $restored++;

                ChangeLog::query()->create([
                    'employee_id' => $row['employee_id'],
                    'date' => substr((string) $row['date'], 0, 10),
                    'before' => null,
                    'after' => ['restored' => true],
                    'action' => 'undo_clear',
                    'user_id' => $request->user()?->getKey(),
                ]);
            }
        });

        $skipped = count($saved['rows']) - $restored;

        return $this->gridResponse($request, "Đã hoàn tác: khôi phục {$restored} ô của {$saved['label']}" . ($skipped ? ", bỏ qua {$skipped} ô đã được xếp ca mới" : '') . '.');
    }

    protected function undoKey(Request $request): string
    {
        return 'roster-clear-undo:' . ($request->user()?->getKey() ?? 0);
    }

    public function clearWeek(Request $request)
    {
        [$weekStart, $weekEnd] = $this->weekRange($request);

        $count = Shift::query()
            ->whereIn('employee_id', $this->employeeQuery($request)->pluck('id'))
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->where('status', Shift::STATUS_DRAFT)
            ->where('is_locked', false)
            ->delete();

        return $this->gridResponse($request, "Đã xóa {$count} ca nháp (giữ ô đã khóa).");
    }

    /**
     * Khóa / mở khóa ô: ô khóa được thuật toán giữ nguyên khi tạo lịch và tối ưu lại.
     * Khóa một ô trống tạo ô OFF đã khóa (giữ ngày nghỉ).
     */
    /**
     * Quy định của resort mà ô xếp tay đang vi phạm: (L)/(K) chỉ cho nữ; ca giới hạn giới tính / khu vực / vị trí (M9, OH).
     *
     * @return string[]
     */
    protected function ruleWarnings(Employee $employee, Shift $shift): array
    {
        if ($shift->kind !== Shift::KIND_SHIFT) {
            return [];
        }

        $warnings = [];
        $femaleOnly = array_intersect($shift->tags ?: [], config('plugins.restaurant-roster.general.scheduling.female_only_tags', []));

        if ($femaleOnly && $employee->gender !== 'female') {
            $warnings[] = 'Ký hiệu (' . implode(')(', $femaleOnly) . ') theo quy định chỉ dành cho nhân viên nữ.';
        }

        $template = $shift->template ?? ShiftTemplate::query()->find($shift->shift_template_id);
        $residences = config('plugins.restaurant-roster.general.residences', []);

        if ($template?->allowed_gender && $employee->gender !== $template->allowed_gender) {
            $warnings[] = "Ca {$template->code} chỉ dành cho nhân viên " . ($template->allowed_gender === 'female' ? 'nữ' : 'nam') . '.';
        }

        if ($template?->allowed_residence && $employee->residence !== $template->allowed_residence) {
            $warnings[] = "Ca {$template->code} chỉ dành cho nhân viên ở " . ($residences[$template->allowed_residence] ?? $template->allowed_residence) . '.';
        }

        if ($template?->allowed_position_id && (int) $employee->position_id !== (int) $template->allowed_position_id) {
            $warnings[] = "Ca {$template->code} chỉ dành cho vị trí " . (Position::query()->whereKey($template->allowed_position_id)->value('name') ?? '') . '.';
        }

        return $warnings;
    }

    /**
     * Duyệt / từ chối tăng ca (+N) của một ô. Ghi người duyệt và thời điểm.
     */
    public function overtime(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:roster_employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['approved', 'rejected', 'pending'])],
        ]);

        $shift = Shift::query()->with('template')
            ->where('employee_id', $data['employee_id'])
            ->whereDate('date', $data['date'])
            ->where('overtime_hours', '>', 0)
            ->first();

        if (! $shift) {
            return $this->httpResponse()->setError()->setMessage('Ô này không có tăng ca.');
        }

        $before = $this->snapshot($shift);
        $shift->update([
            'overtime_status' => $data['status'],
            'overtime_approved_by' => $data['status'] === 'pending' ? null : $request->user()?->getKey(),
            'overtime_approved_at' => $data['status'] === 'pending' ? null : now(),
        ]);
        $this->logChange($request, (int) $data['employee_id'], Carbon::parse($data['date']), $before, $shift, 'overtime');

        return $this->gridResponse($request, match ($data['status']) {
            'approved' => 'Đã duyệt tăng ca.',
            'rejected' => 'Đã từ chối tăng ca.',
            default => 'Đã chuyển tăng ca về chờ duyệt.',
        });
    }

    public function lock(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:roster_employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'locked' => ['required', 'boolean'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $date = Carbon::parse($data['date']);
        $shift = Shift::query()->where('employee_id', $employee->getKey())->whereDate('date', $date)->first();
        $locked = (bool) $data['locked'];

        if (! $shift && $locked) {
            $shift = $this->assigner->assign($employee, $date, ['kind' => Shift::KIND_OFF, 'source' => 'manual'], $request->user()?->getKey());
        }

        if ($shift) {
            $shift->update(['is_locked' => $locked]);
            $this->logChange($request, $employee->getKey(), $date, null, $shift->load('template'), $locked ? 'lock' : 'unlock');
        }

        return $this->gridResponse($request, $locked ? 'Đã khóa ô – thuật toán sẽ giữ nguyên.' : 'Đã mở khóa ô.');
    }

    /**
     * Hoán đổi outlet chính của toàn bộ Waiter giữa Gusto và Villa (vị trí khác giữ nguyên).
     */
    public function swapWaiters(Request $request, OutletRotationService $rotation)
    {
        try {
            $counts = $rotation->swapWaiters($request->user()?->getKey());
        } catch (RuntimeException $exception) {
            return $this->httpResponse()->setError()->setMessage($exception->getMessage());
        }

        return $this->gridResponse($request, sprintf(
            'Đã hoán đổi: %d Waiter Gusto → Villa, %d Waiter Villa → Gusto. Lịch đã xếp giữ nguyên – chạy "Tối ưu lại" nếu muốn xếp theo outlet mới.',
            $counts['a_to_b'],
            $counts['b_to_a']
        ));
    }

    protected function logChange(Request $request, int $employeeId, Carbon $date, Shift|array|null $before, ?Shift $after, string $action): void
    {
        ChangeLog::query()->create([
            'employee_id' => $employeeId,
            'date' => $date->toDateString(),
            'before' => $before instanceof Shift ? $this->snapshot($before) : $before,
            'after' => $after ? $this->snapshot($after) : null,
            'action' => $action,
            'user_id' => $request->user()?->getKey(),
        ]);
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

    protected function gridResponse(Request $request, ?string $message = null, array $extra = [])
    {
        $grid = $this->buildGrid($request);

        return $this
            ->httpResponse()
            ->setData([
                'html' => view('plugins/restaurant-roster::roster.grid', [
                    'grid' => $grid,
                    'canManage' => $this->canManage($request),
                ])->render(),
                'week_label' => $grid['weekLabel'],
                'week' => $grid['weekStart']->toDateString(),
                ...$extra,
            ])
            ->setMessage($message);
    }

    protected function buildGrid(Request $request): array
    {
        [$weekStart, $weekEnd] = $this->weekRange($request);

        $employees = $this->employeeQuery($request)
            ->with(['position:id,name,order', 'outlet:id,name,code,order', 'fixedShift:id,code'])
            ->get()
            ->sortBy(fn (Employee $employee) => sprintf('%05d|%05d|%s', $employee->outlet->order ?? 99999, $employee->position->order ?? 99999, $employee->code))
            ->values();

        // Lấy thêm 7 ngày trước tuần đang xem để kiểm tra ngày làm liên tục và giờ nghỉ đầu tuần.
        $shifts = Shift::query()
            ->with('template:id,code,name,color,support_station,start_time')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$weekStart->copy()->subDays(7)->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $days = collect(range(0, 6))->map(fn (int $offset) => $weekStart->copy()->addDays($offset));

        $rows = [];
        $dayTotals = $days->mapWithKeys(fn (Carbon $day) => [$day->toDateString() => ['staff' => 0, 'hours' => 0.0, 'lobby' => 0]])->all();

        // Tăng ca trong (các) tháng của tuần đang xem – cảnh báo khi vượt giới hạn luật (40h/tháng).
        $maxMonthlyOt = (float) config('plugins.restaurant-roster.general.scheduling.max_monthly_overtime_hours', 40);
        $monthlyOt = Shift::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$weekStart->copy()->startOfMonth()->toDateString(), $weekEnd->copy()->endOfMonth()->toDateString()])
            ->where('overtime_hours', '>', 0)
            ->where(fn ($q) => $q->whereNull('overtime_status')->orWhere('overtime_status', '!=', 'rejected'))
            ->selectRaw('employee_id, DATE_FORMAT(date, "%Y-%m") as ym, SUM(overtime_hours) as total')
            ->groupBy('employee_id', 'ym')
            ->get()
            ->groupBy('employee_id');
        $summary = ['shifts' => 0, 'drafts' => 0, 'conflicts' => 0, 'overtime' => 0];

        foreach ($employees as $employee) {
            $employeeShifts = $shifts->get($employee->getKey(), collect());
            $check = $this->checker->check($employee, $employeeShifts, $weekStart);

            foreach ($monthlyOt->get($employee->getKey(), collect()) as $month) {
                if ((float) $month->total > $maxMonthlyOt) {
                    $check['messages'][] = sprintf('Tăng ca tháng %s: %sh (vượt %sh/tháng)', $month->ym, round((float) $month->total, 1), $maxMonthlyOt);
                    $check['level'] = 'danger';
                }
            }

            $cells = [];

            foreach ($employeeShifts as $shift) {
                $key = $shift->date->toDateString();

                if (! isset($dayTotals[$key])) {
                    continue;
                }

                $cells[$key] = $shift;

                if ($shift->isWorking()) {
                    if (in_array('L', $shift->tags ?: [], true) || $shift->template?->support_station) {
                        $dayTotals[$key]['lobby']++;
                    }

                    $dayTotals[$key]['staff']++;
                    $dayTotals[$key]['hours'] += $shift->hours;
                    $summary['shifts']++;
                }

                if ($shift->status === Shift::STATUS_DRAFT) {
                    $summary['drafts']++;
                }
            }

            $summary['conflicts'] += count($check['cells']);
            $summary['overtime'] += $check['overtime'] > 0 ? 1 : 0;

            $rows[] = [
                'employee' => $employee,
                'cells' => $cells,
                'check' => $check,
            ];
        }

        $outletId = $request->input('outlet_id');

        // Thống kê trong bảng: số người đi làm theo ngày × outlet × ca (đối chiếu quy định).
        $statistics = app(\Botble\RestaurantRoster\Services\RosterStatistics::class);
        $employeesById = $employees->keyBy('id');
        $weekShifts = $shifts->flatten()
            ->filter(fn (Shift $shift) => $shift->date->betweenIncluded($weekStart, $weekEnd))
            ->each(fn (Shift $shift) => $shift->setRelation('employee', $employeesById->get($shift->employee_id)));
        $outlets = Outlet::query()->orderBy('order')->get(['id', 'name', 'code']);
        $parents = config('plugins.restaurant-roster.general.scheduling.outlet_parents', []);

        return [
            'stats' => [
                'onDuty' => $statistics->onDuty($weekStart->copy(), $outlets, $weekShifts),
                'rules' => $statistics->rules(),
                // Khu trực thuộc (Kid-Deline) hiện dưới outlet chủ quản (Gusto).
                'children' => $outlets->filter(fn ($o) => isset($parents[$o->code]))
                    ->groupBy(fn ($o) => $outlets->firstWhere('code', $parents[$o->code])?->id)->all(),
                'outlets' => $outlets->keyBy('id'),
            ],
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'weekLabel' => sprintf('Tuần %d · %s – %s', $weekStart->isoWeek(), $weekStart->format('d/m'), $weekEnd->format('d/m/Y')),
            'days' => $days,
            'rows' => $rows,
            'dayTotals' => $dayTotals,
            'summary' => $summary,
            'outletId' => $outletId,
            'leaveTypes' => config('plugins.restaurant-roster.general.leave_types', []),
            'shiftTags' => $this->assigner->tagDefinitions(),
            'outletCodes' => Outlet::query()->pluck('code', 'id')->all(),
            // Phương án tự động xếp lịch của tuần này đã chạy nhưng chưa áp dụng.
            'pendingRun' => Run::query()
                ->whereIn('status', ['feasible', 'partial', 'infeasible'])
                ->where('type', '!=', 'validate')
                ->whereDate('scope_from', '<=', $weekEnd)
                ->whereDate('scope_to', '>=', $weekStart)
                ->latest('id')
                ->first(),
            'rules' => config('plugins.restaurant-roster.general.rules', []),
        ];
    }

    protected function employeeQuery(Request $request): Builder
    {
        return Employee::query()
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->when($request->integer('outlet_id'), fn (Builder $query, int $outletId) => $query->where('outlet_id', $outletId));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function weekRange(Request $request): array
    {
        try {
            $date = Carbon::parse($request->input('week') ?: 'today');
        } catch (\Throwable) {
            $date = Carbon::today();
        }

        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return [$weekStart, $weekStart->copy()->addDays(6)];
    }

    protected function canManage(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('roster.manage');
    }
}
