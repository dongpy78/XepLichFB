<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Models\BaseModel;
use Botble\RestaurantRoster\Forms\AvailabilityRequestForm;
use Botble\RestaurantRoster\Http\Requests\AvailabilityRequestRequest;
use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Tables\AvailabilityRequestTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AvailabilityRequestController extends RosterCrudController
{
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
        return 'Đơn OFF & ngày bận';
    }

    /**
     * Tạo một đơn cho mỗi nhân viên được chọn (sự kiện tập thể chia đợt).
     */
    public function store()
    {
        $request = app(AvailabilityRequestRequest::class);
        $data = $request->validated();
        $ids = array_unique($data['employee_ids']);
        unset($data['employee_ids'], $data['employee_id']);

        DB::transaction(function () use ($ids, $data): void {
            foreach ($ids as $employeeId) {
                AvailabilityRequest::query()->create($this->prepare([...$data, 'employee_id' => $employeeId], null) + ['created_by' => Auth::id()]);
            }
        });

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.availability.index'))
            ->setNextUrl(route('roster.availability.index'))
            ->setMessage(sprintf('Đã tạo %d đơn.', count($ids)));
    }

    public function approve(int|string $id)
    {
        return $this->review($id, 'approved');
    }

    public function reject(int|string $id)
    {
        return $this->review($id, 'rejected');
    }

    protected function review(int|string $id, string $status)
    {
        $item = AvailabilityRequest::query()->findOrFail($id);
        $item->update(['status' => $status, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.availability.index'))
            ->setMessage($status === 'approved' ? 'Đã duyệt đơn.' : 'Đã từ chối đơn.');
    }

    /**
     * Ghi người duyệt / thời điểm duyệt khi trạng thái chuyển sang duyệt hoặc từ chối.
     * Chỉ người có quyền duyệt mới đổi được trạng thái; người khác chỉ tạo đơn chờ duyệt.
     */
    protected function prepare(array $data, ?BaseModel $model): array
    {
        $canApprove = (bool) Auth::user()?->hasPermission('roster.approve');

        if (! $canApprove) {
            $data['status'] = $model?->status ?? 'pending';
        }

        $statusChanged = ($model?->status ?? 'pending') !== $data['status'];

        if ($statusChanged && in_array($data['status'], ['approved', 'rejected'], true)) {
            $data['reviewed_by'] = Auth::id();
            $data['reviewed_at'] = now();
        }

        unset($data['employee_ids']);

        return $data;
    }
}
