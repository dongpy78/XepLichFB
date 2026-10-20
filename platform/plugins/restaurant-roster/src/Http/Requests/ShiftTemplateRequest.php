<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class ShiftTemplateRequest extends Request
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'different:start_time'],
            'split_start_time' => ['nullable', 'required_with:split_end_time', 'date_format:H:i,H:i:s'],
            'split_end_time' => ['nullable', 'required_with:split_start_time', 'date_format:H:i,H:i:s'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'color' => ['required', Rule::in(array_keys(config('plugins.restaurant-roster.general.colors', [])))],
            'support_station' => ['nullable', 'string', 'max:20'],
            'allowed_residence' => ['nullable', Rule::in(array_keys(config('plugins.restaurant-roster.general.residences', [])))],
            'allowed_gender' => ['nullable', Rule::in(['male', 'female'])],
            'allowed_position_id' => ['nullable', 'integer', 'exists:roster_positions,id'],
            'lobby_start_time' => ['nullable', 'required_with:lobby_end_time', 'date_format:H:i,H:i:s'],
            'lobby_end_time' => ['nullable', 'required_with:lobby_start_time', 'date_format:H:i,H:i:s'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => Rule::in(BaseStatusEnum::values()),
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['allowed_gender', 'allowed_position_id', 'support_station', 'allowed_residence', 'lobby_start_time', 'lobby_end_time', 'split_start_time', 'split_end_time'] as $field) {
            $this->merge([$field => $this->input($field) ?: null]);
        }
    }

    public function attributes(): array
    {
        return [
            'code' => 'Mã ca',
            'start_time' => 'Giờ bắt đầu',
            'end_time' => 'Giờ kết thúc',
            'split_start_time' => 'Bắt đầu đoạn 2',
            'split_end_time' => 'Kết thúc đoạn 2',
            'break_minutes' => 'Phút nghỉ',
        ];
    }
}
