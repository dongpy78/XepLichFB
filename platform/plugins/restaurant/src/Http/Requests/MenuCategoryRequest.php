<?php

namespace Botble\Restaurant\Http\Requests;

use Botble\Base\Rules\MediaImageRule;
use Botble\Base\Rules\OnOffRule;
use Botble\Restaurant\Enums\MenuStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class MenuCategoryRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:1000'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(MenuStatusEnum::values())],
            'is_featured' => [new OnOffRule()],
            'image' => ['nullable', 'string', new MediaImageRule()],
        ];
    }
}
