<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\StaffingRequirementRequest;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;

class StaffingRequirementForm extends FormAbstract
{
    public const WEEKDAYS = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ nhật'];

    public function setup(): void
    {
        $number = fn (string $label) => NumberFieldOption::make()->label($label)->min(0)->max(50)->defaultValue(0)->colspan(1);
        $model = $this->getModel();

        $this
            ->setupModel(new StaffingRequirement())
            ->setValidatorClass(StaffingRequirementRequest::class)
            ->columns(4)
            ->add('outlet_id', SelectField::class, SelectFieldOption::make()->label('Outlet')->required()->colspan(2)
                ->choices(Outlet::query()->orderBy('order')->pluck('name', 'id')->all()))
            ->add('shift_template_id', SelectField::class, SelectFieldOption::make()->label('Ca')->required()->colspan(2)
                ->choices(ShiftTemplate::query()->orderBy('order')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->code} ({$t->time_range})"])->all()))
            ->add('weekday', SelectField::class, SelectFieldOption::make()->label('Áp dụng cho thứ')->colspan(1)
                ->choices(['' => 'Mọi ngày'] + self::WEEKDAYS))
            ->add('date', TextField::class, TextFieldOption::make()->label('Hoặc ngày cụ thể')->placeholder('YYYY-MM-DD')->colspan(1)
                ->helperText('Ngày cụ thể được ưu tiên hơn "theo thứ" – dùng khi chỉnh định biên giữa kỳ.')
                ->value($model instanceof StaffingRequirement ? $model->date?->toDateString() : null))
            ->add('upper_floor', SelectField::class, SelectFieldOption::make()->label('Tầng trên (Villa)')->colspan(2)
                ->choices(['' => 'Áp dụng mọi trường hợp', 0 => 'Chỉ khi KHÔNG mở tầng trên', 1 => 'Chỉ khi MỞ tầng trên']))
            ->add('min_staff', NumberField::class, $number('Tối thiểu (người)')->required())
            ->add('target_staff', NumberField::class, $number('Mục tiêu (người)')->required())
            ->add('max_staff', NumberField::class, $number('Tối đa (để trống = mục tiêu)')->defaultValue(null))
            ->add('allow_reduce', SelectField::class, SelectFieldOption::make()->label('Cho giảm về tối thiểu')->colspan(1)->defaultValue(1)
                ->choices([1 => 'Có', 0 => 'Không']))
            ->add('min_male', NumberField::class, $number('Nam tối thiểu')->required())
            ->add('target_male', NumberField::class, $number('Nam mục tiêu')->required())
            ->add('is_confirmed', SelectField::class, SelectFieldOption::make()->label('Trạng thái')->colspan(2)->defaultValue(1)
                ->choices([1 => 'Đã xác nhận', 0 => 'Gợi ý – chưa xác nhận']))
            ->add('note', TextField::class, TextFieldOption::make()->label('Ghi chú')->maxLength(255)->colspan(4));
    }
}
