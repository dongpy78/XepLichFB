<?php

namespace Botble\Restaurant\Http\Requests\Settings;

use Botble\Support\Http\Requests\Request;

class CurrencySettingRequest extends Request
{
    public function rules(): array
    {
        return [
            'enable_auto_detect_visitor_currency' => ['nullable', 'boolean'],
            'currencies' => ['required', 'json'],
            'deleted_currencies' => ['nullable', 'json'],
        ];
    }
}
