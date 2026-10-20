<?php

namespace Botble\Restaurant\Forms;

use Botble\Base\Forms\FieldOptions\DescriptionFieldOption;
use Botble\Base\Forms\FieldOptions\MediaImageFieldOption;
use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\Fields\MediaImageField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\OnOffField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\Restaurant\Http\Requests\MenuCategoryRequest;
use Botble\Restaurant\Models\MenuCategory;

class MenuCategoryForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->model(MenuCategory::class)
            ->setValidatorClass(MenuCategoryRequest::class)
            ->add('name', TextField::class, NameFieldOption::make()->required())
            ->add('description', TextareaField::class, DescriptionFieldOption::make())
            ->add('image', MediaImageField::class, MediaImageFieldOption::make())
            ->add(
                'order',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('core/base::forms.order'))
                    ->placeholder(trans('core/base::forms.order_by_placeholder'))
                    ->defaultValue(0)
            )
            ->add('status', SelectField::class, StatusFieldOption::make())
            ->add(
                'is_featured',
                OnOffField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/restaurant::menu.is_featured'))
                    ->defaultValue(false)
            )
            ->setBreakFieldPoint('status');
    }
}
