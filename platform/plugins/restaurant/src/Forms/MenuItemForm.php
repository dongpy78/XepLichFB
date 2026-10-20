<?php

namespace Botble\Restaurant\Forms;

use Botble\Base\Forms\FieldOptions\DescriptionFieldOption;
use Botble\Base\Forms\FieldOptions\MediaImageFieldOption;
use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\MediaImageField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\OnOffField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\Restaurant\Http\Requests\MenuItemRequest;
use Botble\Restaurant\Models\MenuCategory;
use Botble\Restaurant\Models\MenuItem;

class MenuItemForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->model(MenuItem::class)
            ->setValidatorClass(MenuItemRequest::class)
            ->add('name', TextField::class, NameFieldOption::make()->required())
            ->add('description', TextareaField::class, DescriptionFieldOption::make())
            ->add(
                'category_id',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.category'))
                    ->choices(
                        MenuCategory::query()
                            ->oldest('order')
                            ->oldest('name')
                            ->pluck('name', 'id')
                    )
                    ->required()
                    ->searchable()
            )
            ->add(
                'price',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.price'))
                    ->required()
                    ->placeholder('0.00')
                    ->min(0)
                    ->step(0.01)
            )
            ->add(
                'sale_price',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.sale_price'))
                    ->placeholder('0.00')
                    ->min(0)
                    ->step(0.01)
                    ->helperText(trans('plugins/restaurant::menu.sale_price_help'))
            )
            ->add('image', MediaImageField::class, MediaImageFieldOption::make())
            ->add(
                'is_featured',
                OnOffField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.is_featured'))
                    ->defaultValue(false)
            )
            ->add(
                'is_vegetarian',
                OnOffField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.is_vegetarian'))
                    ->defaultValue(false)
            )
            ->add(
                'is_vegan',
                OnOffField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.is_vegan'))
                    ->defaultValue(false)
            )
            ->add(
                'is_gluten_free',
                OnOffField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.is_gluten_free'))
                    ->defaultValue(false)
            )
            ->add(
                'calories',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.calories'))
                    ->placeholder(trans('plugins/restaurant::menu.calories_placeholder'))
            )
            ->add(
                'ingredients',
                TextareaField::class,
                TextareaFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.ingredients'))
                    ->placeholder(trans('plugins/restaurant::menu.ingredients_placeholder'))
                    ->rows(3)
            )
            ->add(
                'order',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('core/base::forms.order'))
                    ->placeholder(trans('core/base::forms.order_by_placeholder'))
                    ->defaultValue(0)
            )
            ->add('status', SelectField::class, StatusFieldOption::make())
            ->setBreakFieldPoint('status');
    }
}
