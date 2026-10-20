<?php

namespace Botble\Restaurant\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Rules\MediaImageRule;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class ChefRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'image' => ['nullable', 'string', new MediaImageRule()],
            'badge' => ['nullable', 'string', 'max:255'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => Rule::in(BaseStatusEnum::values()),
        ];
    }
}
