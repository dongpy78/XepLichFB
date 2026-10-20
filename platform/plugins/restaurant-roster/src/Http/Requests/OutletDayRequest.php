<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class OutletDayRequest extends Request
{
    public function rules(): array
    {
        return [
            'outlet_id' => ['required', 'integer', 'exists:roster_outlets,id'],
            'date' => [
                'required', 'date_format:Y-m-d',
                Rule::unique('roster_outlet_days')->where('outlet_id', $this->input('outlet_id'))->ignore($this->route('outlet_day')),
            ],
            'upper_floor_open' => ['required', 'boolean'],
            'is_closed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['outlet_id' => 'Outlet', 'date' => 'Ngày'];
    }

    public function messages(): array
    {
        return ['date.unique' => 'Outlet này đã có cấu hình cho ngày này.'];
    }
}
