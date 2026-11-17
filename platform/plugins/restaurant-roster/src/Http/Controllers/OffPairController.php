<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Http\Controllers\BaseController;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\OffPair;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cặp nghỉ chung (quản lý 17/11/2026) trên trang Cấu hình quy tắc xếp lịch. Quyền roster.settings.
 * Không đổi lịch đã xếp / đã công bố; xóa liên kết là xóa mềm (giữ lịch sử).
 */
class OffPairController extends BaseController
{
    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int|string $pair)
    {
        return $this->save($request, OffPair::query()->findOrFail($pair));
    }

    public function destroy(Request $request, int|string $pair)
    {
        $model = OffPair::query()->findOrFail($pair);

        DB::transaction(function () use ($model, $request): void {
            $model->update(['deleted_by' => $request->user()?->getKey()]);
            $model->delete();
        });

        return $this->back('Đã xóa cặp nghỉ chung. Lịch đã xếp giữ nguyên; các lần xếp tự động sau xếp OFF cho hai người độc lập.');
    }

    private function save(Request $request, ?OffPair $model)
    {
        $data = $request->validate([
            'employee_a_id' => ['required', 'integer', 'exists:roster_employees,id'],
            'employee_b_id' => ['required', 'integer', 'exists:roster_employees,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        [$a, $b] = [(int) $data['employee_a_id'], (int) $data['employee_b_id']];
        $active = $model ? $request->boolean('is_active') : true;

        if ($a === $b) {
            return $this->back('Hai nhân viên trong cặp phải khác nhau.', true);
        }

        [$a, $b] = [min($a, $b), max($a, $b)];
        $employees = Employee::query()->whereIn('id', [$a, $b])->get()->keyBy('id');

        if ($active && $employees->contains(fn (Employee $e) => $e->status?->getValue() !== BaseStatusEnum::PUBLISHED)) {
            return $this->back('Chỉ chọn nhân viên đang hoạt động.', true);
        }

        $others = OffPair::query()->when($model, fn ($q) => $q->whereKeyNot($model->getKey()));

        if ((clone $others)->where('employee_a_id', $a)->where('employee_b_id', $b)->exists()) {
            return $this->back('Cặp này đã có trong danh sách.', true);
        }

        if ($active && ($busy = (clone $others)->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('employee_a_id', [$a, $b])->orWhereIn('employee_b_id', [$a, $b]))->first())) {
            $taken = array_values(array_intersect([$a, $b], [(int) $busy->employee_a_id, (int) $busy->employee_b_id]));

            return $this->back(sprintf('%s đã thuộc một cặp nghỉ chung đang áp dụng. Mỗi nhân viên chỉ thuộc một cặp.',
                $employees->only($taken)->pluck('name')->implode(', ')), true);
        }

        $values = ['employee_a_id' => $a, 'employee_b_id' => $b, 'is_active' => $active, 'updated_by' => $request->user()?->getKey()];

        DB::transaction(fn () => $model ? $model->update($values) : OffPair::query()->create([...$values, 'created_by' => $request->user()?->getKey()]));

        return $this->back($model ? 'Đã cập nhật cặp nghỉ chung.' : 'Đã thêm cặp nghỉ chung. Áp dụng từ lần xếp tự động tiếp theo.');
    }

    private function back(string $message, bool $error = false)
    {
        $response = $this->httpResponse()->setNextUrl(route('roster.rules.index') . '#off-pairs')->setMessage($message);

        return $error ? $response->setError()->withInput() : $response;
    }
}
