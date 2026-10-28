<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Models\StaffingRequirementPosition;
use Botble\RestaurantRoster\Services\StaffingRequirementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Định biên nhân sự: tổng quan, ma trận tuần, danh sách có lọc, form theo nhóm nhân sự, xem trước ảnh hưởng, lịch sử.
 * Nghiệp vụ nằm ở StaffingRequirementService; quyền kiểm tra ở route (roster.settings).
 */
class StaffingRequirementController extends BaseController
{
    public function __construct(protected StaffingRequirementService $service)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Định biên nhân sự', route('roster.requirements.index'));
    }

    public function index(Request $request)
    {
        $this->pageTitle('Định biên nhân sự');
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        $week = CarbonImmutable::parse($request->input('week') ?: CarbonImmutable::today()->addWeek()->toDateString())->startOfWeek();
        $matrix = $this->service->matrix($week);
        $filters = $request->only(['outlet_id', 'shift_template_id', 'status', 'on_date', 'q']);

        $rows = StaffingRequirement::query()
            ->with(['outlet:id,code,name,order', 'template:id,code,order', 'positions.position:id,name'])
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['shift_template_id'] ?? null, fn ($q, $v) => $q->where('shift_template_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'needs_review'
                ? $q->where('status', StaffingRequirement::STATUS_ACTIVE)->where('is_confirmed', false)
                : $q->where('status', $v))
            ->when($filters['on_date'] ?? null, function ($q, $date): void {
                $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;
                $q->where(fn ($w) => $w->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                    ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                    ->where(fn ($w) => $w->whereDate('date', $date)->orWhere(fn ($x) => $x->whereNull('date')->where(fn ($y) => $y->whereNull('weekday')->orWhere('weekday', $weekday))));
            })
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('note', 'like', '%' . $v . '%'))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->orderBy('outlet_id')->orderBy('shift_template_id')
            ->orderByRaw('date IS NULL DESC, weekday IS NULL DESC')->orderBy('weekday')->orderBy('date')
            ->paginate(25)
            ->withQueryString();

        return view('plugins/restaurant-roster::requirements.index', [
            'week' => $week,
            'matrix' => $matrix,
            'overview' => $this->service->overview($matrix),
            'rows' => $rows,
            'filters' => $filters,
            'outlets' => Outlet::query()->orderBy('order')->get(['id', 'code', 'name']),
            'templates' => ShiftTemplate::query()->orderBy('order')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(Request $request)
    {
        $source = $request->integer('copy') ? StaffingRequirement::query()->with('positions')->find($request->integer('copy')) : null;
        $model = new StaffingRequirement($source ? [...$source->only($source->getFillable()), 'status' => StaffingRequirement::STATUS_DRAFT, 'note' => trim('Sao chép từ #' . $source->id . '. ' . $source->note)]
            : ['status' => StaffingRequirement::STATUS_ACTIVE, 'is_confirmed' => true, 'allow_reduce' => true, 'outlet_id' => $request->integer('outlet_id') ?: null,
                'shift_template_id' => $request->integer('shift_template_id') ?: null]);

        if ($source) {
            $model->setRelation('positions', $source->positions->map(fn ($p) => $p->replicate(['requirement_id'])));
        }

        $this->pageTitle($source ? "Sao chép định biên #{$source->id}" : 'Thêm định biên');

        return $this->form($model, $source);
    }

    public function edit(int|string $requirement)
    {
        $model = StaffingRequirement::query()->with('positions')->findOrFail($requirement);
        $this->pageTitle("Sửa định biên #{$model->id}");

        return $this->form($model);
    }

    public function store(Request $request)
    {
        [$data, $positions] = $this->input($request);
        $model = $this->service->save(null, $data, $positions, $request->input('reason'), $request->user()?->getKey());

        return $this->saved($request, $model, 'Đã thêm định biên #' . $model->id . '.');
    }

    public function update(int|string $requirement, Request $request)
    {
        $model = StaffingRequirement::query()->with('positions')->findOrFail($requirement);
        [$data, $positions] = $this->input($request);
        $this->service->save($model, $data, $positions, $request->input('reason'), $request->user()?->getKey());

        return $this->saved($request, $model, 'Đã lưu định biên #' . $model->id . '.');
    }

    /**
     * Xem trước ảnh hưởng (JSON): ngày bị đổi định biên trong IMPACT_WEEKS tuần tới, ngày đã có lịch / đã công bố, ngày sẽ thiếu tối thiểu.
     */
    public function preview(Request $request)
    {
        [$data, $positions] = $this->input($request);
        $model = $request->integer('id') ? StaffingRequirement::query()->with('positions')->find($request->integer('id')) : null;

        if ($errors = $this->service->validate($data, $positions, $model?->getKey())) {
            return response()->json(['error' => true, 'errors' => $errors, 'message' => 'Dữ liệu chưa hợp lệ – xem các trường được đánh dấu.'], 422);
        }

        return response()->json(['error' => false, 'data' => $this->service->impact($model, $data, $positions)]);
    }

    public function status(int|string $requirement, Request $request)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(StaffingRequirement::STATUSES))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $model = StaffingRequirement::query()->findOrFail($requirement);
        $this->service->setStatus($model, $data['status'], $data['reason'] ?? null, $request->user()?->getKey());

        return $this->httpResponse()
            ->setPreviousUrl(route('roster.requirements.index'))
            ->setMessage(sprintf('Định biên #%d: %s.', $model->id, mb_strtolower(StaffingRequirement::STATUSES[$data['status']])));
    }

    public function destroy(int|string $requirement, Request $request)
    {
        $model = StaffingRequirement::query()->findOrFail($requirement);
        $deleted = $this->service->delete($model, $request->user()?->getKey());

        return $this->httpResponse()
            ->setPreviousUrl(route('roster.requirements.index'))
            ->setMessage($deleted ? "Đã xóa bản nháp #{$model->id}." : "Định biên #{$model->id} đã từng áp dụng nên được chuyển sang ngừng hiệu lực (giữ lịch sử).");
    }

    public function history(int|string $requirement)
    {
        $model = StaffingRequirement::query()->with(['outlet:id,code', 'template:id,code', 'revisions.user'])->findOrFail($requirement);
        $this->pageTitle("Lịch sử định biên #{$model->id}");

        return view('plugins/restaurant-roster::requirements.history', [
            'model' => $model,
            'positionNames' => Position::query()->pluck('name', 'id'),
        ]);
    }

    private function form(StaffingRequirement $model, ?StaffingRequirement $source = null)
    {
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        return view('plugins/restaurant-roster::requirements.form', [
            'model' => $model,
            'source' => $source,
            'outlets' => $this->editableOutlets($model->outlet_id ? (int) $model->outlet_id : null),
            'templates' => ShiftTemplate::query()->wherePublished()->orderBy('order')->get(),
            'positions' => Position::query()->wherePublished()->orderBy('order')->get(['id', 'name', 'is_manager']),
        ]);
    }

    /**
     * Chế độ "balance": nhà hàng thông thường không còn định biên – chỉ cho khai định biên Night, Room Service, khu trực thuộc (Kid-Deline).
     * Dòng cũ của nhà hàng vẫn mở sửa / ngừng được (outlet đang chọn luôn có trong danh sách).
     */
    private function editableOutlets(?int $current)
    {
        $outlets = Outlet::query()->wherePublished()->orderBy('order')->get(['id', 'code', 'name']);

        if (config('plugins.restaurant-roster.general.scheduling.staffing_mode', 'balance') !== 'balance') {
            return $outlets;
        }

        $kept = app(\Botble\RestaurantRoster\Services\ProblemBuilder::class)->staffedOutletIds();

        return $outlets->filter(fn ($o) => in_array((int) $o->id, $kept, true) || (int) $o->id === $current)->values();
    }

    private function saved(Request $request, StaffingRequirement $model, string $message)
    {
        $next = $request->input('after') === 'reoptimize'
            ? route('roster.auto.index', ['from' => CarbonImmutable::today()->toDateString(), 'to' => CarbonImmutable::today()->addWeeks(2)->toDateString()])
            : route('roster.requirements.index');

        return $this->httpResponse()->setNextUrl($next)->setPreviousUrl($next)->setMessage($message);
    }

    /**
     * Đọc form: kiểu dữ liệu ở đây (Laravel validation), quy tắc nghiệp vụ ở service. Ô trống = null (không phải 0).
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function input(Request $request): array
    {
        $number = ['nullable', 'integer', 'min:0', 'max:50'];
        $raw = $request->validate([
            'outlet_id' => ['required', 'integer', 'exists:roster_outlets,id'],
            'shift_template_id' => ['required', 'integer', 'exists:roster_shift_templates,id'],
            'status' => ['required', Rule::in([StaffingRequirement::STATUS_ACTIVE, StaffingRequirement::STATUS_DRAFT])],
            'apply_mode' => ['required', Rule::in(['all', 'weekday', 'date'])],
            'weekday' => ['nullable', 'required_if:apply_mode,weekday', 'integer', 'between:1,7'],
            'date' => ['nullable', 'required_if:apply_mode,date', 'date_format:Y-m-d'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'upper_floor' => ['nullable', Rule::in(['', '0', '1'])],
            'count_start_time' => ['nullable', 'date_format:H:i'],
            'count_end_time' => ['nullable', 'date_format:H:i'],
            'min_staff' => $number, 'target_staff' => $number, 'max_staff' => $number,
            'min_male' => $number, 'target_male' => $number, 'min_female' => $number, 'target_female' => $number,
            'allow_reduce' => ['nullable', 'boolean'],
            'is_confirmed' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            'positions' => ['nullable', 'array', 'max:20'],
            'positions.*.kind' => ['required', Rule::in([StaffingRequirementPosition::KIND_POSITION, StaffingRequirementPosition::KIND_MANAGERS])],
            'positions.*.position_id' => ['nullable', 'integer', 'exists:roster_positions,id'],
            'positions.*.applies' => ['nullable', 'boolean'],
            'positions.*.min_staff' => $number,
            'positions.*.target_staff' => $number,
            'positions.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $int = fn ($v) => $v === null || $v === '' ? null : (int) $v;
        $data = [
            'outlet_id' => (int) $raw['outlet_id'],
            'shift_template_id' => (int) $raw['shift_template_id'],
            'status' => $raw['status'],
            'weekday' => $raw['apply_mode'] === 'weekday' ? $int($raw['weekday'] ?? null) : null,
            'date' => $raw['apply_mode'] === 'date' ? ($raw['date'] ?? null) : null,
            'effective_from' => $raw['effective_from'] ?? null,
            'effective_to' => $raw['effective_to'] ?? null,
            'upper_floor' => ($raw['upper_floor'] ?? '') === '' ? null : (bool) $raw['upper_floor'],
            'count_start_time' => $raw['count_start_time'] ?? null,
            'count_end_time' => $raw['count_end_time'] ?? null,
            'allow_reduce' => (bool) ($raw['allow_reduce'] ?? false),
            'is_confirmed' => (bool) ($raw['is_confirmed'] ?? false),
            'note' => $raw['note'] ?? null,
        ];

        foreach (['min_staff', 'target_staff', 'max_staff', 'min_male', 'target_male', 'min_female', 'target_female'] as $field) {
            $data[$field] = $int($raw[$field] ?? null);
        }

        // Nam / nữ waiter không đánh dấu "áp dụng" = không áp dụng (null), kể cả khi trình duyệt vẫn gửi số.
        foreach (['male', 'female'] as $group) {
            if ($request->has("applies_{$group}") ? ! $request->boolean("applies_{$group}") : ($data["min_{$group}"] === null && $data["target_{$group}"] === null)) {
                $data["min_{$group}"] = null;
                $data["target_{$group}"] = null;
            }
        }


        $positions = array_values(array_map(fn ($p) => [
            'kind' => $p['kind'],
            'position_id' => $int($p['position_id'] ?? null),
            'applies' => (bool) ($p['applies'] ?? false),
            'min_staff' => $int($p['min_staff'] ?? null),
            'target_staff' => $int($p['target_staff'] ?? null),
            'note' => $p['note'] ?? null,
        ], $raw['positions'] ?? []));

        return [$data, $positions];
    }
}
