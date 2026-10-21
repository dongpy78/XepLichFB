<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\Period;
use Botble\RestaurantRoster\Services\OffReadinessService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Kỳ lịch: tạo kỳ → quản lý nhập OFF (từ Zalo) → xem tổng hợp OFF & nguy cơ thiếu người → xác nhận "đã nhập xong OFF"
 * → mới được chạy xếp lịch tự động cho kỳ đó.
 */
class PeriodController extends BaseController
{
    public function __construct(protected OffReadinessService $readiness)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Kỳ lịch & OFF', route('roster.periods.index'));
    }

    public function index()
    {
        $this->pageTitle('Kỳ lịch & OFF');
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        $nextMonth = Carbon::today()->addMonthNoOverflow()->startOfMonth();

        return view('plugins/restaurant-roster::periods.index', [
            'periods' => Period::query()->latest('start_date')->limit(24)->get(),
            'suggested' => [
                'name' => 'Tháng ' . $nextMonth->format('m/Y'),
                'start' => $nextMonth->toDateString(),
                'end' => $nextMonth->copy()->endOfMonth()->toDateString(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        if (Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 62) {
            return $this->httpResponse()->setError()->setMessage('Một kỳ lịch tối đa 2 tháng.');
        }

        $period = Period::query()->create([...$data, 'status' => 'draft', 'created_by' => $request->user()?->getKey()]);

        return $this->httpResponse()
            ->setNextUrl(route('roster.periods.show', $period->getKey()))
            ->setPreviousUrl(route('roster.periods.index'))
            ->setMessage('Đã tạo kỳ lịch. Nhập OFF của nhân viên rồi xác nhận "đã nhập xong OFF" để chạy xếp lịch tự động.');
    }

    public function show(int|string $id)
    {
        $period = Period::query()->findOrFail($id);
        $this->pageTitle('Kỳ lịch: ' . $period->name);
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        return view('plugins/restaurant-roster::periods.show', [
            'period' => $period,
            'summary' => $this->readiness->summary($period->start_date, $period->end_date),
        ]);
    }

    public function confirmOff(int|string $id, Request $request)
    {
        $period = Period::query()->findOrFail($id);
        $period->update(['off_confirmed_at' => now(), 'off_confirmed_by' => $request->user()?->getKey(), 'status' => 'review']);

        return $this->httpResponse()
            ->setNextUrl(route('roster.auto.index', ['from' => $period->start_date->toDateString(), 'to' => $period->end_date->toDateString()]))
            ->setPreviousUrl(route('roster.periods.show', $period->getKey()))
            ->setMessage('Đã xác nhận nhập xong OFF – có thể chạy "Tạo lịch tự động" cho kỳ này. OFF nhập thêm sau vẫn được ghi và khóa; chạy "Tối ưu lại" để cập nhật.');
    }

    public function reopenOff(int|string $id)
    {
        $period = Period::query()->findOrFail($id);
        $period->update(['off_confirmed_at' => null, 'off_confirmed_by' => null, 'status' => 'draft']);

        return $this->httpResponse()
            ->setPreviousUrl(route('roster.periods.show', $period->getKey()))
            ->setMessage('Đã mở lại bước nhập OFF. Xếp lịch tự động tạm khóa cho tới khi xác nhận lại.');
    }

    public function destroy(int|string $id)
    {
        Period::query()->findOrFail($id)->delete();

        return $this->httpResponse()->setNextUrl(route('roster.periods.index'))->setMessage('Đã xóa kỳ lịch (lịch làm việc không bị xóa).');
    }
}
