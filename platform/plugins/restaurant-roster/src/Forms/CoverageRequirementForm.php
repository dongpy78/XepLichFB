<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\Fields\TimeField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\CoverageRequirementRequest;
use Botble\RestaurantRoster\Models\CoverageRequirement;

class CoverageRequirementForm extends FormAbstract
{
    public function setup(): void
    {
        $model = $this->getModel();
        $time = fn (string $label) => TextFieldOption::make()->label($label)->cssClass('form-control')->required()->colspan(1);

        $this
            ->setupModel(new CoverageRequirement())
            ->setValidatorClass(CoverageRequirementRequest::class)
            ->columns(4)
            ->add('start_time', TimeField::class, $time('Từ giờ'))
            ->add('end_time', TimeField::class, $time('Đến giờ'))
            ->add('shift_codes', TextField::class, TextFieldOption::make()->label('Chỉ tính ca')->placeholder('vd OH,M9')->maxLength(100)->colspan(4)
                ->helperText('Mã ca được tính là người đứng Lobby trong khung này, cách nhau bởi dấu phẩy (sáng: OH,M9 – OH(L) và M9; chiều: A2 – A2(L)). Để trống = mọi ca có (L) hoặc làm trọn ở Lobby.'))
            ->add('min_staff', NumberField::class, NumberFieldOption::make()->label('Tối thiểu (người)')->min(0)->max(20)->defaultValue(0)->required()->colspan(1))
            ->add('target_staff', NumberField::class, NumberFieldOption::make()->label('Mục tiêu (người)')->min(0)->max(20)->defaultValue(0)->required()->colspan(1))
            ->add('weekday', SelectField::class, SelectFieldOption::make()->label('Áp dụng cho thứ')->colspan(2)
                ->choices(['' => 'Mọi ngày'] + \Botble\RestaurantRoster\Models\StaffingRequirement::WEEKDAYS))
            ->add('date', TextField::class, TextFieldOption::make()->label('Hoặc ngày cụ thể')->placeholder('YYYY-MM-DD')->colspan(2)
                ->helperText('Ngày cụ thể ưu tiên hơn "theo thứ" (vd ngày có tiệc, khám sức khỏe).')
                ->value($model instanceof CoverageRequirement ? $model->date?->toDateString() : null))
            ->add('is_confirmed', SelectField::class, SelectFieldOption::make()->label('Trạng thái')->colspan(2)->defaultValue(1)
                ->choices([1 => 'Đã xác nhận', 0 => 'Gợi ý – chưa xác nhận']))
            ->add('note', TextField::class, TextFieldOption::make()->label('Ghi chú')->maxLength(255)->colspan(2));
    }
}
