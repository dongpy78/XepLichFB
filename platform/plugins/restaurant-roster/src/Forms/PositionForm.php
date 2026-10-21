<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\PositionRequest;
use Botble\RestaurantRoster\Models\Position;

class PositionForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->setupModel(new Position())
            ->setValidatorClass(PositionRequest::class)
            ->add('name', TextField::class, NameFieldOption::make()->label('Tên vị trí')->placeholder('VD: Captain, Waiter, Bartender')->required()->maxLength(120))
            ->add('is_manager', OnOffCheckboxField::class, OnOffFieldOption::make()
                ->label('Cấp quản lý (Junior Restaurant Captain trở lên)')
                ->defaultValue(false)
                ->helperText('Xếp lịch tự động: người ở vị trí này chỉ được xếp M6 / A2 tại outlet chính (không tự điều chuyển, không đi Lobby), và số quản lý ca sáng / chiều được cân bằng. OH / M9 hay đổi outlet do quản lý xếp tay.'))
            ->add(
                'description',
                TextareaField::class,
                TextareaFieldOption::make()->label('Mô tả')->rows(3)
            )
            ->add(
                'order',
                NumberField::class,
                NumberFieldOption::make()->label('Thứ tự hiển thị')->helperText('Nhân viên trên bảng xếp ca được sắp theo thứ tự vị trí.')->defaultValue(0)
            )
            ->add('status', SelectField::class, StatusFieldOption::make())
            ->setBreakFieldPoint('status');
    }
}
