<?php

namespace Botble\Restaurant\Forms;

use Botble\Base\Forms\FieldOptions\MediaImageFieldOption;
use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\MediaImageField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\Restaurant\Http\Requests\ChefRequest;
use Botble\Restaurant\Models\Chef;

class ChefForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->setupModel(new Chef())
            ->setValidatorClass(ChefRequest::class)
            ->withCustomFields()
            ->add('name', TextField::class, NameFieldOption::make()->required()->maxLength(120))
            ->add(
                'title',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/restaurant::chef.forms.title'))
                    ->placeholder(trans('plugins/restaurant::chef.forms.title_placeholder'))
                    ->maxLength(120)
            )
            ->add(
                'bio',
                TextareaField::class,
                TextareaFieldOption::make()
                    ->label(trans('plugins/restaurant::chef.forms.bio'))
                    ->placeholder(trans('plugins/restaurant::chef.forms.bio_placeholder'))
                    ->rows(4)
            )
            ->add(
                'experience_years',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/restaurant::chef.forms.experience_years'))
                    ->placeholder(trans('plugins/restaurant::chef.forms.experience_years_placeholder'))
                    ->min(0)
                    ->max(50)
            )
            ->add('image', MediaImageField::class, MediaImageFieldOption::make())
            ->add(
                'badge',
                MediaImageField::class,
                MediaImageFieldOption::make()->label(trans('plugins/restaurant::chef.forms.badge'))
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
            ->addMetaBoxes([
                'social_links' => [
                    'title' => trans('plugins/restaurant::chef.forms.social_links'),
                    'content' => view('plugins/restaurant::forms.social-links', [
                        'model' => $this->getModel(),
                    ]),
                    'priority' => 1,
                ],
            ])
            ->setBreakFieldPoint('status');
    }
}
