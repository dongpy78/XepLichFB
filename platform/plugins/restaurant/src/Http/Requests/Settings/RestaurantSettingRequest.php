<?php

namespace Botble\Restaurant\Http\Requests\Settings;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class RestaurantSettingRequest extends Request
{
    public function rules(): array
    {
        return [
            'restaurant_reservation_time_format' => [
                'nullable',
                'string',
                Rule::in(['12', '24']),
            ],
        ];
    }
}
