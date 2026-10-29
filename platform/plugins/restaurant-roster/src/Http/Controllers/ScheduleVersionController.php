<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Models\ScheduleVersion;
use Botble\RestaurantRoster\Services\ScheduleExportService;
use Botble\RestaurantRoster\Services\ScheduleSnapshotService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Lịch tuần đã công bố: xem các phiên bản của tuần (phiên bản gần nhất, người / thời điểm công bố), xem lại một phiên bản,
 * tải PNG / Excel / PDF từ đúng phiên bản đó. "Bản nháp" (lịch đang chỉnh sửa) chỉ người có quyền sửa lịch mới xem / tải,
 * file ghi rõ BẢN NHÁP. Dùng chung khu, thứ tự, ký hiệu ca với Bảng xếp ca (RosterController::buildGrid).
 */
class ScheduleVersionController extends RosterController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()->add('Lịch tuần đã công bố', route('roster.schedule.index'));
    }

    public function show(Request $request)
    {
        $this->pageTitle('Lịch tuần đã công bố');
        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');
        [$weekStart, $weekEnd] = $this->weekRange($request);
        $scope = $this->scopeOutletId($request);
        $versions = ScheduleVersion::query()->whereDate('week_start', $weekStart->toDateString())->where('scope_outlet_id', $scope)
            ->orderByDesc('version')->get(['id', 'week_start', 'scope_outlet_id', 'version', 'employee_count', 'published_by_name', 'published_at']);
        $canDraft = $this->canDraft($request);
        [$snapshot, $selected] = $this->resolve($request, allowMissing: true);
        $grid = $this->buildGrid($request);

        return view('plugins/restaurant-roster::schedule.show', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'scope' => $scope,
            'versions' => $versions,
            'selected' => $selected,
            'snapshot' => $snapshot,
            'status' => $grid['publication']['status'],
            'statusLabels' => ScheduleSnapshotService::STATUS_LABELS,
            'canDraft' => $canDraft,
            'snapshots' => app(ScheduleSnapshotService::class),
        ]);
    }

    public function export(Request $request)
    {
        $request->validate(['format' => ['required', 'in:png,xlsx,pdf']]);

        try {
            [$snapshot] = $this->resolve($request);
            $exporter = app(ScheduleExportService::class);
            $snapshots = app(ScheduleSnapshotService::class);

            [$content, $mime, $name] = match ($request->input('format')) {
                'xlsx' => [$exporter->xlsx($snapshot), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $snapshots->filename($snapshot, 'xlsx')],
                'pdf' => [$exporter->pdf($snapshot), 'application/pdf', $snapshots->filename($snapshot, 'pdf')],
                'png' => [$exporter->png($snapshot), 'image/png', $snapshots->filename($snapshot, 'png')],
            };
        } catch (RuntimeException $e) {
            return $this->httpResponse()->setError()->setMessage($e->getMessage())->setNextUrl(route('roster.schedule.index', $request->only('week', 'outlet_id')));
        }

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition . '; filename="' . $name . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Bản chụp cần dùng: ?version=N (phiên bản N), "draft" (lịch đang chỉnh sửa – cần quyền sửa lịch), mặc định phiên bản gần nhất.
     *
     * @return array{0: ?array, 1: ?ScheduleVersion}
     */
    private function resolve(Request $request, bool $allowMissing = false): array
    {
        [$weekStart] = $this->weekRange($request);
        $scope = $this->scopeOutletId($request);
        $version = (string) $request->input('version', '');

        if ($version === 'draft') {
            if (! $this->canDraft($request)) {
                abort(403, 'Chỉ người có quyền sửa lịch mới xem / tải bản nháp.');
            }

            return [$this->buildGrid($request)['snapshot'], null];
        }

        $query = ScheduleVersion::query()->whereDate('week_start', $weekStart->toDateString())->where('scope_outlet_id', $scope);
        $row = $version !== '' ? $query->where('version', (int) $version)->first() : $query->orderByDesc('version')->first();

        if (! $row) {
            if ($allowMissing) {
                return [null, null];
            }

            throw new RuntimeException('Tuần này chưa có lịch đã công bố – bấm "Công bố lịch tuần" trên Bảng xếp ca trước (hoặc tải bản nháp).');
        }

        return [$row->snapshot, $row];
    }

    private function canDraft(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('roster.manage');
    }
}
