<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Support\Http\Requests\Request;

class StaffingRequirementRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'weekday' => $this->input('weekday') ?: null,
            'date' => $this->input('date') ?: null,
            'upper_floor' => $this->input('upper_floor') === '' ? null : $this->input('upper_floor'),
            'max_staff' => $this->input('max_staff') === '' ? null : $this->input('max_staff'),
        ]);
    }

    public function rules(): array
    {
        return [
            'outlet_id' => ['required', 'integer', 'exists:roster_outlets,id'],
            'shift_template_id' => ['required', 'integer', 'exists:roster_shift_templates,id'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'upper_floor' => ['nullable', 'boolean'],
            'min_staff' => ['required', 'integer', 'min:0', 'max:50'],
            'target_staff' => ['required', 'integer', 'min:0', 'max:50', 'gte:min_staff'],
            'max_staff' => ['nullable', 'integer', 'min:0', 'max:50', 'gte:target_staff'],
            'min_male' => ['required', 'integer', 'min:0', 'max:50', 'lte:target_staff'],
            'target_male' => ['required', 'integer', 'min:0', 'max:50', 'gte:min_male', 'lte:target_staff'],
            'allow_reduce' => ['required', 'boolean'],
            'is_confirmed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'outlet_id' => 'Outlet', 'shift_template_id' => 'Ca', 'weekday' => 'Thứ', 'date' => 'Ngày cụ thể',
            'min_staff' => 'Tối thiểu', 'target_staff' => 'Mục tiêu', 'max_staff' => 'Tối đa',
            'min_male' => 'Nam tối thiểu', 'target_male' => 'Nam mục tiêu',
        ];
    }
}
