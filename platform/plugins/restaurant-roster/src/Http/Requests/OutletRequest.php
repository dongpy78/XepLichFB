<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class OutletRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:400'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => Rule::in(BaseStatusEnum::values()),
        ];
    }
}
