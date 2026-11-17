<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Services\RosterStatistics;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Thống kê nhân sự cho quản lý: cơ cấu nhân viên theo outlet (nam/nữ, vị trí, nơi ở, ca cứng, ngày OFF)
 * và số người đi làm theo ngày × outlet × ca của một tuần (đối chiếu quy định: có nam, Hostess, quản lý mỗi ca).
 */
class StatisticsController extends BaseController
{
    public function __construct(protected RosterStatistics $statistics)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Thống kê nhân sự', route('roster.statistics'));
    }

    public function index(Request $request)
    {
        $this->pageTitle('Thống kê nhân sự');
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        $week = Carbon::parse($request->query('week', Carbon::today()->toDateString()))->startOfWeek(Carbon::MONDAY);
        $outlets = Outlet::query()->orderBy('order')->get(['id', 'name', 'code']);
        $positions = Position::query()->orderBy('order')->get(['id', 'name']);
        $employees = Employee::query()->wherePublished()->with(['position:id,name', 'fixedShift:id,code'])->get();

        $headcount = [];

        foreach ($outlets as $outlet) {
            $list = $employees->where('outlet_id', $outlet->id);

            if ($list->isNotEmpty()) {
                $headcount[] = ['outlet' => $outlet] + $this->statistics->summarize($list);
            }
        }

        $headcount[] = ['outlet' => null] + $this->statistics->summarize($employees);

        // Thống kê theo ngày: cùng khối "Thống kê nhân sự" cuối Bảng xếp ca (RosterStatistics::board) – số liệu hai nơi luôn khớp.
        $dates = array_map(fn ($d) => $week->copy()->addDays($d)->toDateString(), range(0, 6));
        $shifts = \Botble\RestaurantRoster\Models\Shift::query()
            ->whereBetween('date', [$dates[0], $dates[6]])
            ->with(['employee:id,name,gender,position_id,outlet_id,is_trainee,status', 'template:id,code,start_time,support_station'])
            ->get();
        $onDuty = $this->statistics->onDuty($week->copy(), $outlets, $shifts);
        $restaurantIds = $outlets->whereIn('code', (array) config('plugins.restaurant-roster.general.scheduling.transfer_outlets', ['GUSTO', 'VILLA']))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $closed = app(\Botble\RestaurantRoster\Services\ProblemBuilder::class)->closedRestaurantDays($restaurantIds, $dates);
        $status = collect($restaurantIds)->mapWithKeys(fn ($id) => [$id => ['days' => collect($dates)->mapWithKeys(fn ($d) => [$d => ['closed' => isset($closed[$id][$d])]])->all()]])->all();

        return view('plugins/restaurant-roster::statistics.index', [
            'week' => $week,
            'outlets' => $outlets,
            'positions' => $positions,
            'headcount' => $headcount,
            'positionMatrix' => $this->positionMatrix($employees, $outlets, $positions),
            'rules' => $this->statistics->rules(),
            'grid' => [
                'days' => array_map(fn ($d) => Carbon::parse($d), $dates),
                'board' => $this->statistics->board($shifts, $outlets, $dates, $onDuty, $status),
                'stats' => ['onDuty' => $onDuty],
            ],
            'groups' => $this->groups($employees),
        ]);
    }

    /**
     * Số người theo 4 nhóm xếp lịch (Incharge, Hostess, Waiter nữ, Waiter nam) và quản lý cấp trên – cùng định nghĩa với thuật toán.
     *
     * @return array<string, int>
     */
    private function groups(Collection $employees): array
    {
        $rules = $this->statistics->rules();
        $names = fn (string $key) => array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) config("plugins.restaurant-roster.general.scheduling.{$key}", []));
        $hostess = $names('balance_positions');
        $position = fn ($e) => mb_strtolower(trim((string) $e->position?->name));
        $active = $employees->reject(fn ($e) => $e->is_trainee);

        return [
            'incharge' => $active->filter(fn ($e) => in_array((int) $e->position_id, $rules['inChargeIds'], true))->count(),
            'managers' => $active->filter(fn ($e) => in_array((int) $e->position_id, $rules['leaderIds'], true) && ! in_array((int) $e->position_id, $rules['inChargeIds'], true))->count(),
            'hostess' => $active->filter(fn ($e) => in_array($position($e), $hostess, true))->count(),
            'waiter_female' => $active->filter(fn ($e) => $e->gender === 'female' && in_array((int) $e->position_id, $rules['femaleStaffIds'], true))->count(),
            'waiter_male' => $active->filter(fn ($e) => $e->gender === 'male' && in_array((int) $e->position_id, $rules['maleStaffIds'], true))->count(),
            'trainee' => $employees->filter(fn ($e) => $e->is_trainee)->count(),
        ];
    }

    /**
     * Vị trí × outlet: số người, tách nam / nữ.
     */
    private function positionMatrix(Collection $employees, Collection $outlets, Collection $positions): array
    {
        $count = fn (Collection $list) => [
            'total' => $list->count(),
            'male' => $list->where('gender', 'male')->count(),
            'female' => $list->where('gender', 'female')->count(),
        ];
        $matrix = [];

        foreach ($positions as $position) {
            $byPosition = $employees->where('position_id', $position->id);

            if ($byPosition->isEmpty()) {
                continue;
            }

            foreach ($outlets as $outlet) {
                $matrix[$position->id][$outlet->id] = $count($byPosition->where('outlet_id', $outlet->id));
            }

            $matrix[$position->id]['all'] = $count($byPosition);
        }

        return $matrix;
    }
}
