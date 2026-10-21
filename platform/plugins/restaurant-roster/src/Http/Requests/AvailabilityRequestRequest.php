<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class AvailabilityRequestRequest extends Request
{
    public function rules(): array
    {
        $creating = ! $this->route('availability');

        return [
            // Tạo mới: chọn được nhiều nhân viên (sự kiện tập thể, khám sức khỏe theo đợt).
            // Từng nhân viên được kiểm tra ở withValidator() (chỉ phía server): quy tắc dạng employee_ids.* bị bộ kiểm tra
            // trên trình duyệt của Botble báo sai với ô chọn nhiều người ("The employee_ids.* must be an integer").
            'employee_ids' => [$creating ? 'required' : 'nullable', 'array', 'min:1'],
            'employee_id' => [$creating ? 'nullable' : 'required', 'integer', 'exists:roster_employees,id'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['required', Rule::in(array_keys(config('plugins.restaurant-roster.general.availability_types', [])))],
            'group_label' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:255'],
            // Quản lý nhập = đã cho OFF; khi sửa chỉ chọn giữ OFF hoặc hủy (gỡ khỏi lịch).
            'status' => ['nullable', Rule::in(['approved', 'cancelled'])],
            'received_at' => ['nullable', 'date_format:Y-m-d'],
            'review_note' => ['nullable', 'string', 'max:255'],
            // Cho OFF đè lên ca làm đã khóa (đã xác nhận / BQ). Không chọn: giữ ca và đánh dấu "cần xem xét".
            'override_locked' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $ids = (array) $this->input('employee_ids', []);

            if (! $ids) {
                return;
            }

            $valid = array_filter($ids, fn ($id) => is_numeric($id) && (int) $id == $id);
            $found = \Botble\RestaurantRoster\Models\Employee::query()->whereIn('id', array_map('intval', $valid))->count();

            if (count($valid) !== count($ids) || $found !== count(array_unique(array_map('intval', $valid)))) {
                $validator->errors()->add('employee_ids', 'Danh sách nhân viên có người không hợp lệ hoặc đã bị xóa. Chọn lại nhân viên.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'employee_ids' => 'Nhân viên', 'employee_id' => 'Nhân viên', 'date_from' => 'Từ ngày', 'date_to' => 'Đến ngày',
            'type' => 'Loại', 'group_label' => 'Tên đợt / sự kiện', 'status' => 'Trạng thái', 'received_at' => 'Ngày tiếp nhận',
        ];
    }
}
