<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Run;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Models\Period;
use Botble\RestaurantRoster\Services\OffReadinessService;
use Botble\RestaurantRoster\Services\RosterScheduler;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class AutoScheduleController extends BaseController
{
    public function __construct(protected RosterScheduler $scheduler)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Tự động xếp lịch', route('roster.auto.index'));
    }

    public function index(Request $request)
    {
        $this->pageTitle('Tự động xếp lịch');
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        // Xếp lịch theo tuần: mặc định tuần đang xem ở Bảng xếp ca (lưu trong phiên), trượt sang tuần trước / tuần sau.
        try {
            $week = Carbon::parse($request->input('week') ?: ($request->input('from') ?: (session('roster.week') ?: 'today')))->startOfWeek(Carbon::MONDAY)->startOfDay();
        } catch (\Throwable) {
            $week = Carbon::today()->startOfWeek(Carbon::MONDAY);
        }

        session(['roster.week' => $week->toDateString()]);

        return view('plugins/restaurant-roster::auto.index', [
            'outlets' => Outlet::query()->wherePublished()->orderBy('order')->get(['id', 'name', 'code']),
            'runs' => Run::query()->latest()->limit(20)->get(),
            'week' => $week,
            'currentWeek' => Carbon::today()->startOfWeek(Carbon::MONDAY)->startOfDay(),
            'health' => $this->dataHealth(),
        ]);
    }

    public function run(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['integer', 'exists:roster_outlets,id'],
            'type' => ['required', Rule::in(['generate', 'reoptimize', 'validate'])],
            'history_weeks' => ['nullable', 'integer', Rule::in([4, 8])],
        ]);

        $overrides = isset($data['history_weeks']) ? ['history_weeks' => (int) $data['history_weeks']] : [];

        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        $data['outlet_ids'] = isset($data['outlet_ids']) ? array_map('intval', $data['outlet_ids']) : null;

        if ($from->diffInDays($to) > 62) {
            return $this->httpResponse()->setError()->setMessage('Phạm vi tối đa 2 tháng mỗi lần chạy.');
        }

        // Quy trình: chỉ xếp tự động khi quản lý đã xác nhận nhập xong OFF cho kỳ lịch bao trọn phạm vi.
        if ($data['type'] !== 'validate' && config('plugins.restaurant-roster.general.scheduling.require_off_confirmation', true)
            && ! app(OffReadinessService::class)->confirmedPeriod($from, $to)) {
            return $this->httpResponse()->setError()->setMessage(sprintf(
                'Chưa xác nhận "đã nhập xong OFF" cho %s – %s. Vào Kỳ lịch & OFF, tạo kỳ bao trọn phạm vi này, kiểm tra OFF rồi bấm xác nhận.',
                $from->format('d/m'), $to->format('d/m/Y')
            ));
        }

        try {
            $run = $data['type'] === 'validate'
                ? $this->scheduler->validate($from, $to, $data['outlet_ids'] ?? null, $request->user()?->getKey())
                : $this->scheduler->run($from, $to, $data['outlet_ids'] ?? null, $data['type'], $request->user()?->getKey(), $overrides);
        } catch (RuntimeException $exception) {
            return $this->httpResponse()->setError()->setMessage($exception->getMessage());
        }

        return $this
            ->httpResponse()
            ->setNextUrl(route('roster.auto.show', $run->getKey()))
            ->setPreviousUrl(route('roster.auto.show', $run->getKey()))
            ->setMessage('Đã chạy xong – xem kết quả bên dưới.');
    }

    public function show(int|string $id)
    {
        $run = Run::query()->findOrFail($id);
        $this->pageTitle('Kết quả xếp lịch #' . $run->getKey());
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        $proposal = $run->proposal ?? [];
        $employees = Employee::query()->with('outlet:id,code')->get()->keyBy('id');
        $templates = ShiftTemplate::query()->get()->keyBy('id');
        $outlets = Outlet::query()->pluck('code', 'id');

        return view('plugins/restaurant-roster::auto.show', [
            'run' => $run,
            'proposal' => $proposal,
            'employees' => $employees,
            'templates' => $templates,
            'outlets' => $outlets,
            'diff' => $this->diff($run, $proposal, $templates, $outlets),
            'canApply' => in_array($run->status, ['feasible', 'partial', 'infeasible'], true) && $run->type !== 'validate',
        ]);
    }

    public function apply(int|string $id, Request $request)
    {
        $run = Run::query()->findOrFail($id);

        try {
            $result = $this->scheduler->apply($run, $request->user()?->getKey());
        } catch (RuntimeException $exception) {
            return $this->httpResponse()->setError()->setMessage($exception->getMessage());
        }

        return $this
            ->httpResponse()
            ->setNextUrl(route('roster.index', ['week' => $run->scope_from->toDateString()]))
            ->setPreviousUrl(route('roster.index', ['week' => $run->scope_from->toDateString()]))
            ->setMessage("Đã áp dụng vào lịch ở trạng thái NHÁP: {$result['changed']} ô thay đổi, {$result['kept']} ô giữ nguyên. Kiểm tra rồi bấm \"Công bố lịch tuần\" khi sẵn sàng.");
    }

    public function discard(int|string $id)
    {
        $run = Run::query()->findOrFail($id);

        if ($run->status !== 'applied') {
            $run->update(['status' => 'discarded']);
        }

        return $this->httpResponse()->setNextUrl(route('roster.auto.index'))->setPreviousUrl(route('roster.auto.index'))->setMessage('Đã bỏ qua phương án.');
    }

    /**
     * So sánh phương án với lịch hiện tại: chỉ liệt kê ô thay đổi.
     */
    private function diff(Run $run, array $proposal, $templates, $outlets): array
    {
        if (! $proposal || $run->type === 'validate') {
            return [];
        }

        $current = Shift::query()
            ->with('template:id,code')
            ->whereBetween('date', [$run->scope_from->toDateString(), $run->scope_to->toDateString()])
            ->get()
            ->keyBy(fn (Shift $s) => $s->employee_id . '|' . $s->date->toDateString());

        $rows = [];

        foreach ($proposal['assignments'] ?? [] as $row) {
            $before = $current[$row['employee_id'] . '|' . $row['date']] ?? null;

            // Ô trống / OFF vẫn là OFF: không phải thay đổi thực sự.
            if ($row['kind'] !== 'shift' && (! $before || $before->kind !== Shift::KIND_SHIFT)) {
                continue;
            }

            $after = $row['kind'] === 'shift'
                ? ($templates[$row['shift_id']]->code ?? '?') . ' @ ' . ($outlets[$row['outlet_id']] ?? '?')
                : 'OFF';
            $beforeText = $before ? ($before->kind === Shift::KIND_SHIFT ? $before->notation . ' @ ' . ($outlets[$before->outlet_id] ?? '?') : $before->notation) : '—';

            if ($beforeText === $after) {
                continue;
            }

            $rows[] = ['employee_id' => $row['employee_id'], 'date' => $row['date'], 'before' => $beforeText, 'after' => $after,
                'reason' => $row['unavailable_reason'] ?? ($row['off_home_outlet'] ? 'Điều chuyển ngoài outlet chính' : null)];
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['employee_id']] <=> [$b['date'], $b['employee_id']]);

        return $rows;
    }

    /**
     * Kiểm tra dữ liệu đầu vào trước khi chạy.
     */
    private function dataHealth(): array
    {
        return [
            'no_gender' => Employee::query()->wherePublished()->whereNull('gender')->count(),
            // Năng lực tự suy từ hồ sơ: người chưa có outlet chính mới không được xếp.
            'no_skills' => config('plugins.restaurant-roster.general.scheduling.skills_from_profile', true)
                ? Employee::query()->wherePublished()->where('auto_schedule', true)->whereNull('outlet_id')->whereDoesntHave('skills')->pluck('name')->all()
                : Employee::query()->wherePublished()->where('auto_schedule', true)->whereDoesntHave('skills')->pluck('name')->all(),
            'unconfirmed_requirements' => StaffingRequirement::query()->where('is_confirmed', false)->count(),
            'requirements' => StaffingRequirement::query()->count(),
            'pending_requests' => AvailabilityRequest::query()->where('status', 'pending')->whereDate('date_to', '>=', today())->count(),
        ];
    }
}
