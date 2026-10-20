<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\OutletDayRequest;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\OutletDay;

class OutletDayForm extends FormAbstract
{
    public function setup(): void
    {
        $model = $this->getModel();

        $this
            ->setupModel(new OutletDay())
            ->setValidatorClass(OutletDayRequest::class)
            ->columns()
            ->add('outlet_id', SelectField::class, SelectFieldOption::make()->label('Outlet')->required()->colspan(1)
                ->choices(Outlet::query()->orderBy('order')->pluck('name', 'id')->all()))
            ->add('date', TextField::class, TextFieldOption::make()->label('Ngày')->placeholder('YYYY-MM-DD')->required()->colspan(1)
                ->value($model instanceof OutletDay ? $model->date?->toDateString() : null))
            ->add('upper_floor_open', SelectField::class, SelectFieldOption::make()->label('Tầng trên (Villa)')->colspan(1)->defaultValue(0)
                ->choices([0 => 'Không mở', 1 => 'Mở tầng trên']))
            ->add('is_closed', SelectField::class, SelectFieldOption::make()->label('Hoạt động')->colspan(1)->defaultValue(0)
                ->choices([0 => 'Mở cửa bình thường', 1 => 'Đóng cửa – không cần nhân sự']))
            ->add('note', TextField::class, TextFieldOption::make()->label('Ghi chú')->placeholder('VD: Tiệc MICE 450 khách')->maxLength(255)->colspan(2));
    }
}
