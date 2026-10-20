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

        return view('plugins/restaurant-roster::statistics.index', [
            'week' => $week,
            'outlets' => $outlets,
            'positions' => $positions,
            'headcount' => $headcount,
            'positionMatrix' => $this->positionMatrix($employees, $outlets, $positions),
            'onDuty' => $this->statistics->onDuty($week, $outlets),
            'rules' => $this->statistics->rules(),
        ]);
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
