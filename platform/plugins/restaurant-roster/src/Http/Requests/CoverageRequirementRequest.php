<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Support\Http\Requests\Request;

class CoverageRequirementRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'weekday' => $this->input('weekday') ?: null,
            'date' => $this->input('date') ?: null,
            'shift_codes' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('shift_codes'))) ?: null,
            'station' => $this->input('station') ?: config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY'),
        ]);
    }

    public function rules(): array
    {
        return [
            'station' => ['required', 'string', 'max:20'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            'shift_codes' => ['nullable', 'string', 'max:100', 'regex:/^[A-Z0-9]+(,[A-Z0-9]+)*$/'],
            'min_staff' => ['required', 'integer', 'min:0', 'max:20'],
            'target_staff' => ['required', 'integer', 'min:0', 'max:20', 'gte:min_staff'],
            'is_confirmed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'start_time' => 'Từ giờ', 'end_time' => 'Đến giờ', 'min_staff' => 'Tối thiểu', 'target_staff' => 'Mục tiêu',
            'weekday' => 'Thứ', 'date' => 'Ngày cụ thể', 'shift_codes' => 'Chỉ tính ca',
        ];
    }
}
