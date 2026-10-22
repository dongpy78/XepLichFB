<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\ScheduleGroupRequest;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\ScheduleGroup;

class ScheduleGroupForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->setupModel(new ScheduleGroup())
            ->setValidatorClass(ScheduleGroupRequest::class)
            ->columns()
            ->add('name', TextField::class, NameFieldOption::make()->label('Tên hiển thị trên Bảng xếp ca')->placeholder('VD: Trainer')->required()->maxLength(120)->colspan(1))
            ->add('code', TextField::class, TextFieldOption::make()->label('Mã nhóm')->placeholder('VD: TRAINER')->required()->maxLength(20)->colspan(1)
                ->helperText('Chữ in hoa, số, gạch. Chỉ dùng để nhận diện – thuật toán xếp lịch không dựa vào mã nhóm.'))
            ->add('order', NumberField::class, NumberFieldOption::make()->label('Thứ tự xuất hiện')->defaultValue(0)->min(0)->colspan(1))
            ->add('is_active', SelectField::class, SelectFieldOption::make()->label('Trạng thái')->defaultValue(1)->colspan(1)
                ->choices([1 => 'Đang dùng – hiện trên Bảng xếp ca', 0 => 'Tắt – nhân viên hiện theo outlet chính']))
            ->add('outlet_id', SelectField::class, SelectFieldOption::make()->label('Outlet thống kê (tùy chọn)')->colspan(2)
                ->choices(['' => '— Không hiện dòng thống kê định biên —'] + Outlet::query()->orderBy('order')->pluck('name', 'id')->all())
                ->helperText('Dòng đếm người theo ca của outlet này hiện ở cuối nhóm. Nhóm hiển thị không đổi outlet làm việc của nhân viên.'))
            ->add('description', TextareaField::class, TextareaFieldOption::make()->label('Ghi chú')->rows(2)->colspan(2));
    }
}
