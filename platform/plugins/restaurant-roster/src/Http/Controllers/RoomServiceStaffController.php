<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Facades\Assets;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\RoomServiceStaff;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Services\RoomServiceStaffService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Nhân sự Room Service: người chuyên trách từng ca, dự phòng, người đã xác nhận kỹ năng thay thế; ca cần thay do OFF; lịch sử.
 */
class RoomServiceStaffController extends BaseController
{
    public function __construct(private readonly RoomServiceStaffService $service)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Nhân sự Room Service', route('roster.room-service.index'));
    }

    public function index(Request $request)
    {
        $this->pageTitle('Nhân sự Room Service');
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        $from = CarbonImmutable::parse($request->input('from') ?: CarbonImmutable::today()->toDateString());
        $room = $this->service->outletId();
        $current = $this->service->effectiveRows(CarbonImmutable::today());
        $configured = $current->pluck('employee_id')->all();

        return view('plugins/restaurant-roster::room-service.index', [
            'from' => $from,
            'room' => $room,
            'current' => $current,
            'skillOnly' => $room ? Employee::query()->whereIn('id', EmployeeSkill::query()->where('outlet_id', $room)->pluck('employee_id'))
                ->whereNotIn('id', $configured)->with('outlet:id,code')->orderBy('name')->get() : collect(),
            'history' => RoomServiceStaff::query()->with(['employee:id,name', 'template:id,code'])->latest('id')->limit(50)->get(),
            'logs' => ChangeLog::query()->where('action', 'room_service')->with('employee:id,name')->latest('id')->limit(30)->get(),
            'daily' => $this->service->daily($from, 14),
            'employees' => Employee::query()->where('status', BaseStatusEnum::PUBLISHED)->with('outlet:id,code')->orderBy('name')->get(),
            'templates' => ShiftTemplate::query()->orderBy('order')->get(['id', 'code', 'name']),
            'affected' => session('room_service_affected', []),
            'canEdit' => Auth::user()->hasPermission('roster.settings'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:roster_employees,id'],
            'role' => ['required', Rule::in(array_keys(RoomServiceStaff::ROLES))],
            'shift_template_id' => ['nullable', 'required_if:role,lead', 'integer', 'exists:roster_shift_templates,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->save($data, Auth::id());
        } catch (InvalidArgumentException $e) {
            return $this->httpResponse()->setError()->setNextUrl(route('roster.room-service.index'))->withInput()->setMessage($e->getMessage());
        }

        session()->flash('room_service_affected', $result['affected']);
        $message = 'Đã lưu ' . mb_strtolower($result['row']->roleLabel()) . ': ' . $result['row']->employee?->name . '.'
            . ($result['closed'] ? ' Người chuyên trách cũ kết thúc hiệu lực ngày ' . $result['closed']->effective_to?->format('d/m/Y') . '.' : '')
            . ($result['affected'] ? ' Có ' . count($result['affected']) . ' ca đã xếp cần xem lại (lịch chưa bị sửa).' : '');

        return $this->httpResponse()->setNextUrl(route('roster.room-service.index'))->setMessage($message);
    }

    public function end(Request $request, RoomServiceStaff $row)
    {
        $this->service->end($row, Auth::id(), $request->input('reason'));

        return $this->httpResponse()->setNextUrl(route('roster.room-service.index'))->setMessage('Đã kết thúc hiệu lực (lịch sử vẫn được giữ).');
    }
}
