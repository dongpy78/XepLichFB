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
            'employee_ids' => [$creating ? 'required' : 'nullable', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:roster_employees,id'],
            'employee_id' => [$creating ? 'nullable' : 'required', 'integer', 'exists:roster_employees,id'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['required', Rule::in(array_keys(config('plugins.restaurant-roster.general.availability_types', [])))],
            'group_label' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'review_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_ids' => 'Nhân viên', 'employee_id' => 'Nhân viên', 'date_from' => 'Từ ngày', 'date_to' => 'Đến ngày',
            'type' => 'Loại', 'group_label' => 'Tên đợt / sự kiện', 'status' => 'Trạng thái',
        ];
    }
}
