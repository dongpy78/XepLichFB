<?php

namespace Botble\Restaurant\Http\Requests;

use Botble\Base\Rules\MediaImageRule;
use Botble\Restaurant\Enums\MenuStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class MenuItemRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'image' => ['nullable', 'string', new MediaImageRule()],
            'category_id' => ['required', 'integer', 'exists:restaurant_menu_categories,id'],
            'is_featured' => ['boolean'],
            'is_vegetarian' => ['boolean'],
            'is_vegan' => ['boolean'],
            'is_gluten_free' => ['boolean'],
            'calories' => ['nullable', 'string', 'max:50'],
            'ingredients' => ['nullable', 'string', 'max:1000'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(MenuStatusEnum::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'sale_price.lt' => trans('plugins/restaurant::menu.sale_price_must_be_less_than_price'),
        ];
    }
}
