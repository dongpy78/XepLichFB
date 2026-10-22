<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class ScheduleGroupRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'outlet_id' => $this->input('outlet_id') ?: null,
            'is_active' => (bool) $this->input('is_active', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('roster_schedule_groups', 'code')->ignore($this->route('group'))],
            'name' => ['required', 'string', 'max:120'],
            'outlet_id' => ['nullable', 'integer', 'exists:roster_outlets,id'],
            'order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:400'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'Mã nhóm', 'name' => 'Tên hiển thị', 'outlet_id' => 'Outlet thống kê', 'order' => 'Thứ tự'];
    }
}
