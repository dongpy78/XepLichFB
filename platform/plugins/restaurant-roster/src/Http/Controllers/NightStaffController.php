<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\NightRotation;
use Botble\RestaurantRoster\Services\NightStaffService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Quản lý nhân sự Night: Night cứng, nam waiter luân phiên theo tuần (đề xuất → xem trước → duyệt → công bố),
 * bật / tắt tư cách luân phiên, lịch sử tuần Night, định biên Night từng ngày.
 */
class NightStaffController extends BaseController
{
    public function __construct(private readonly NightStaffService $service)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Nhân sự Night', route('roster.night.index'));
    }

    public function index(Request $request, ?array $preview = null)
    {
        $this->pageTitle('Nhân sự Night');
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        $week = CarbonImmutable::parse($request->input('week') ?: CarbonImmutable::today()->addWeek()->toDateString())->startOfWeek();
        $groups = $this->service->groups($week);
        $saved = NightRotation::query()->whereDate('week_start', $week->toDateString())->with(['employee.outlet'])->get();
        $upcoming = collect(range(-2, 5))->map(fn ($n) => $week->addWeeks($n))->mapWithKeys(fn ($w) => [$w->toDateString() => [
            'week' => $w,
            'rows' => NightRotation::query()->whereDate('week_start', $w->toDateString())->with('employee:id,name')->get(),
        ]]);

        return view('plugins/restaurant-roster::night.index', [
            'week' => $week,
            'groups' => $groups,
            'proposal' => $this->service->propose($week),
            'saved' => $saved,
            'history' => $this->service->history([...$groups['rotation']->modelKeys(), ...$groups['disabled']->modelKeys()], $week),
            'staffing' => $this->service->dailyStaffing($week),
            'upcoming' => $upcoming,
            'perWeek' => $this->service->perWeek(),
            'preview' => $preview,
            'canEdit' => Auth::user()->hasPermission('roster.settings'),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'week' => ['required', 'date'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer'],
            'status' => ['required', 'in:preview,approved,published'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $week = CarbonImmutable::parse($data['week']);
        $ids = $data['employee_ids'] ?? [];

        // Xem trước: kiểm tra lại định biên Night, outlet nguồn và điều kiện từng người – chưa lưu gì.
        if ($data['status'] === 'preview') {
            return $this->index($request, ['ids' => array_map('intval', $ids), 'warnings' => $this->service->check($week, $ids)]);
        }

        try {
            $result = $this->service->save($week, $ids, $data['status'], Auth::id(), $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->httpResponse()->setError()->setPreviousUrl(route('roster.night.index', ['week' => $data['week']]))
                ->setNextUrl(route('roster.night.index', ['week' => $data['week']]))->withInput()->setMessage($e->getMessage());
        }

        $message = sprintf('Đã %s %d người luân phiên Night tuần %s.', $data['status'] === 'published' ? 'công bố' : 'duyệt', count($result['ids']), $result['week_start']->format('d/m'))
            . ($result['exceptions'] ? ' Có ngoại lệ đã ghi nhật ký.' : '');

        return $this->httpResponse()->setNextUrl(route('roster.night.index', ['week' => $data['week']]))->setMessage($message);
    }

    /** Bật / tắt tư cách luân phiên Night của một nhân viên. */
    public function toggle(Request $request, Employee $employee)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'week' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:255']]);
        $this->service->setRotationEnabled($employee, (bool) $data['enabled'], Auth::id(), $data['reason'] ?? null);

        return $this->httpResponse()->setNextUrl(route('roster.night.index', ['week' => $data['week'] ?? null]))
            ->setMessage(($data['enabled'] ? 'Đã bật' : 'Đã tắt') . " luân phiên Night cho {$employee->name}.");
    }
}
