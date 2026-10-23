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

        // Mặc định tuần hiện tại; trượt tuần bằng ‹ › hoặc nút "Tuần hiện tại".
        $week = CarbonImmutable::parse($request->input('week') ?: CarbonImmutable::today()->toDateString())->startOfWeek();
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
            'resetScope' => $this->service->resetScope($week),
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

    /**
     * Reset lịch Night của tuần đang chọn (để chạy lại thuật toán): chỉ ca đêm do thuật toán xếp, còn nháp, chưa khóa;
     * danh sách luân phiên đề xuất (và đã duyệt nếu tích xác nhận). Lịch đã khóa / đã công bố / xếp tay được giữ.
     */
    public function reset(Request $request)
    {
        $data = $request->validate(['week' => ['required', 'date'], 'confirm' => ['accepted'], 'include_approved' => ['nullable', 'boolean']]);
        $week = CarbonImmutable::parse($data['week']);

        try {
            $result = $this->service->resetWeek($week, Auth::id(), (bool) ($data['include_approved'] ?? false));
        } catch (\Throwable $e) {
            return $this->httpResponse()->setError()->setNextUrl(route('roster.night.index', ['week' => $data['week']]))
                ->setMessage('Reset không thành công, dữ liệu giữ nguyên: ' . $e->getMessage());
        }

        $kept = array_filter($result['kept']);
        $labels = ['locked' => 'ca đã khóa', 'published' => 'ca đã công bố', 'manual' => 'ca xếp tay', 'approved' => 'người luân phiên đã duyệt', 'published_rotation' => 'người luân phiên đã công bố'];

        return $this->httpResponse()->setNextUrl(route('roster.night.index', ['week' => $data['week']]))->setMessage(sprintf(
            'Đã reset lịch Night tuần %s: xóa %d ca đêm do thuật toán xếp, %d dòng luân phiên.%s Có thể chạy lại "Tạo lịch tự động".',
            $week->startOfWeek()->format('d/m'), $result['deleted_cells'], $result['deleted_rotation'],
            $kept ? ' Giữ nguyên: ' . implode(', ', array_map(fn ($k, $n) => "{$n} {$labels[$k]}", array_keys($kept), $kept)) . '.' : ''));
    }

    /** Bước 1 – Đề xuất nhân sự Night: thuật toán chọn người cho tuần, lưu ở trạng thái "đề xuất" để quản lý xem / sửa rồi duyệt. */
    public function propose(Request $request)
    {
        $data = $request->validate(['week' => ['required', 'date']]);

        try {
            $ids = $this->service->storeProposal(CarbonImmutable::parse($data['week']), Auth::id());
        } catch (InvalidArgumentException $e) {
            return $this->httpResponse()->setError()->setNextUrl(route('roster.night.index', ['week' => $data['week']]))->setMessage($e->getMessage());
        }

        return $this->httpResponse()->setNextUrl(route('roster.night.index', ['week' => $data['week']]))
            ->setMessage(sprintf('Đã đề xuất %d người luân phiên Night – kiểm tra, đổi người nếu cần rồi bấm "Duyệt".', count($ids)));
    }

    /** Hủy duyệt: danh sách về "đề xuất" để quản lý chọn lại người khác. */
    public function unapprove(Request $request)
    {
        $data = $request->validate(['week' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:255']]);
        $count = $this->service->unapprove(CarbonImmutable::parse($data['week']), Auth::id(), $data['reason'] ?? null);

        return $this->httpResponse()->setNextUrl(route('roster.night.index', ['week' => $data['week']]))
            ->setMessage($count ? "Đã hủy duyệt {$count} người – chọn lại người rồi bấm \"Duyệt\"." : 'Tuần này chưa có danh sách đã duyệt.');
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
