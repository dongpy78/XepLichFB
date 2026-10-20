<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\OutletRequest;
use Botble\RestaurantRoster\Models\Outlet;

class OutletForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->setupModel(new Outlet())
            ->setValidatorClass(OutletRequest::class)
            ->add('name', TextField::class, NameFieldOption::make()->label('Tên nhà hàng / outlet')->required()->maxLength(120))
            ->add(
                'code',
                TextField::class,
                TextFieldOption::make()->label('Mã viết tắt')->placeholder('VD: GUSTO')->maxLength(20)
            )
            ->add(
                'description',
                TextareaField::class,
                TextareaFieldOption::make()->label('Mô tả')->rows(3)
            )
            ->add(
                'order',
                NumberField::class,
                NumberFieldOption::make()->label('Thứ tự hiển thị')->defaultValue(0)
            )
            ->add('status', SelectField::class, StatusFieldOption::make())
            ->setBreakFieldPoint('status');
    }
}
