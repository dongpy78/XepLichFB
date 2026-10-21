<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\RestaurantRoster\Forms\AvailabilityRequestForm;
use Botble\RestaurantRoster\Http\Requests\AvailabilityRequestRequest;
use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Services\OffRequestService;
use Botble\RestaurantRoster\Tables\AvailabilityRequestTable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * OFF của nhân viên do quản lý nhập (nhân viên nhắn qua nhóm Zalo).
 * Quản lý nhập = đã cho OFF: OFF được ghi vào bảng lịch và khóa ngay (xem OffRequestService).
 */
class AvailabilityRequestController extends RosterCrudController
{
    public function __construct(protected OffRequestService $offs)
    {
    }

    protected function model(): string
    {
        return AvailabilityRequest::class;
    }

    protected function form(): string
    {
        return AvailabilityRequestForm::class;
    }

    protected function table(): string
    {
        return AvailabilityRequestTable::class;
    }

    protected function request(): string
    {
        return AvailabilityRequestRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'roster.availability';
    }

    protected function title(): string
    {
        return 'OFF của nhân viên';
    }

    /**
     * Ghi OFF cho mỗi nhân viên được chọn (sự kiện tập thể chia đợt chọn được nhiều người).
     */
    public function store()
    {
        $request = app(AvailabilityRequestRequest::class);
        $data = $request->validated();
        $ids = array_map('intval', array_unique($data['employee_ids']));
        $override = (bool) ($data['override_locked'] ?? false);

        if ($error = $this->duplicateError($ids, $data['date_from'], $data['date_to'])) {
            return $this->httpResponse()->setError()->setMessage($error);
        }

        $result = $this->offs->record($ids, $this->fields($data), Auth::id(), $override);

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.availability.index'))
            ->setNextUrl(route('roster.availability.index'))
            ->setMessage($this->summary($result['written'], $result['kept'], count($result['requests'])));
    }

    /**
     * Sửa đơn: gỡ các ô OFF cũ của đơn rồi ghi lại theo ngày / loại mới. Chuyển "Đã hủy" = gỡ OFF khỏi lịch.
     */
    public function update(int|string $id)
    {
        $item = AvailabilityRequest::query()->findOrFail($id);
        $data = app(AvailabilityRequestRequest::class)->validated();
        $employeeId = (int) ($data['employee_id'] ?? $item->employee_id);
        $status = $data['status'] ?? 'approved';

        if ($status === 'approved' && ($error = $this->duplicateError([$employeeId], $data['date_from'], $data['date_to'], (int) $item->getKey()))) {
            return $this->httpResponse()->setError()->setMessage($error);
        }

        $written = 0;
        $kept = [];

        DB::transaction(function () use ($item, $data, $employeeId, $status, &$written, &$kept): void {
            $this->offs->withdraw($item, Auth::id(), "Sửa đơn OFF #{$item->getKey()}");
            $item->update([...$this->fields($data), 'employee_id' => $employeeId, 'status' => $status]);

            if ($status === 'approved') {
                $applied = $this->offs->apply($item->refresh(), Auth::id(), (bool) ($data['override_locked'] ?? false));
                $written = $applied['written'];
                $kept = $applied['kept'];
            }
        });

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.availability.index'))
            ->setMessage($status === 'approved' ? $this->summary($written, $kept, 1) : 'Đã hủy OFF và gỡ khỏi bảng lịch.');
    }

    public function destroy(int|string $id)
    {
        $item = AvailabilityRequest::query()->findOrFail($id);

        DB::transaction(function () use ($item): void {
            $this->offs->withdraw($item, Auth::id(), "Xóa đơn OFF #{$item->getKey()}");
            $item->delete();
        });

        return $this->httpResponse()->setMessage('Đã xóa đơn và gỡ OFF khỏi bảng lịch.');
    }

    /**
     * Đơn cũ còn "chờ duyệt" (trước quy trình mới): cho OFF = ghi vào lịch và khóa.
     */
    public function approve(int|string $id)
    {
        $item = AvailabilityRequest::query()->findOrFail($id);

        if ($error = $this->duplicateError([(int) $item->employee_id], $item->date_from->toDateString(), $item->date_to->toDateString(), (int) $item->getKey())) {
            return $this->httpResponse()->setError()->setMessage($error);
        }

        $applied = DB::transaction(function () use ($item) {
            $item->update(['status' => 'approved', 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);

            return $this->offs->apply($item, Auth::id());
        });

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.availability.index'))
            ->setMessage($this->summary($applied['written'], $applied['kept'], 1));
    }

    /**
     * Không cho OFF: đơn chuyển "Từ chối", các ô OFF của đơn (nếu đã ghi) được gỡ khỏi lịch.
     */
    public function reject(int|string $id)
    {
        $item = AvailabilityRequest::query()->findOrFail($id);

        DB::transaction(function () use ($item): void {
            $this->offs->withdraw($item, Auth::id(), "Không cho OFF – đơn #{$item->getKey()}");
            $item->update(['status' => 'rejected', 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
        });

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.availability.index'))
            ->setMessage('Đã ghi "Không cho OFF"; lịch không có OFF của đơn này.');
    }

    /**
     * Đơn trùng ngày với một đơn OFF khác của cùng nhân viên: báo để tránh dữ liệu mâu thuẫn.
     *
     * @param int[] $employeeIds
     */
    private function duplicateError(array $employeeIds, string $from, string $to, ?int $ignore = null): ?string
    {
        $duplicates = $this->offs->conflicts($employeeIds, $from, $to, $ignore)['duplicates'];

        if (! $duplicates) {
            return null;
        }

        return 'Trùng với OFF đã nhập: ' . implode('; ', array_map(fn ($d) => "{$d['employee']} {$d['range']} (đơn #{$d['request_id']})", $duplicates))
            . '. Sửa đơn cũ thay vì nhập thêm.';
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['date_from', 'date_to', 'type', 'group_label', 'reason', 'received_at', 'review_note']));
    }

    /**
     * @param string[] $kept
     */
    private function summary(int $written, array $kept, int $requests): string
    {
        $message = sprintf('Đã ghi %d ô OFF vào bảng lịch và khóa%s.', $written, $requests > 1 ? " ({$requests} nhân viên)" : '');

        if ($kept) {
            $message .= ' ⚠ Giữ nguyên ca đã khóa, đánh dấu "cần xem xét" để quản lý quyết định: ' . implode('; ', $kept) . '.';
        }

        return $message;
    }
}
