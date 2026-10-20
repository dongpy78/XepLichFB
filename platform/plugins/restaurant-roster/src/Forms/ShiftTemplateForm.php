<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\Fields\TimeField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\ShiftTemplateRequest;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ShiftTemplate;

class ShiftTemplateForm extends FormAbstract
{
    public function setup(): void
    {
        $time = fn (string $label) => TextFieldOption::make()->label($label)->cssClass('form-control')->colspan(1);

        $this
            ->setupModel(new ShiftTemplate())
            ->setValidatorClass(ShiftTemplateRequest::class)
            ->columns()
            ->add(
                'code',
                TextField::class,
                TextFieldOption::make()->label('Mã ca')->placeholder('VD: S1, C1, T1, G1')->required()->maxLength(20)->colspan(1)
            )
            ->add('name', TextField::class, NameFieldOption::make()->label('Tên ca')->placeholder('VD: Ca sáng')->required()->maxLength(120)->colspan(1))
            ->add('start_time', TimeField::class, $time('Giờ bắt đầu')->required())
            ->add(
                'end_time',
                TimeField::class,
                $time('Giờ kết thúc')->required()->helperText('Nếu giờ kết thúc nhỏ hơn giờ bắt đầu, ca được tính qua đêm.')
            )
            ->add(
                'split_start_time',
                TimeField::class,
                $time('Ca gãy – bắt đầu đoạn 2')->helperText('Chỉ điền nếu là ca gãy (VD: 10:30–14:30 & 17:30–21:30).')
            )
            ->add('split_end_time', TimeField::class, $time('Ca gãy – kết thúc đoạn 2'))
            ->add(
                'break_minutes',
                NumberField::class,
                NumberFieldOption::make()->label('Thời gian nghỉ giữa ca (phút)')->helperText('Được trừ khỏi giờ công.')->defaultValue(0)->min(0)->max(240)->colspan(1)
            )
            ->add(
                'color',
                SelectField::class,
                SelectFieldOption::make()->label('Màu hiển thị')->choices(config('plugins.restaurant-roster.general.colors', []))->defaultValue('sky')->required()->colspan(1)
            )
            ->add(
                'support_station',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Làm trọn ca tại quầy bar Lobby')
                    ->choices(['' => 'Không', config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY') => 'Có (vd OH, M9) – ca này tính cho Lobby'])
                    ->colspan(1)
            )
            ->add(
                'allowed_residence',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Chỉ dành cho nhân viên ở')
                    ->choices(['' => 'Mọi khu vực'] + config('plugins.restaurant-roster.general.residences', []))
                    ->helperText('Vd OH chỉ dành cho nhân viên ở Nha Trang vì xe đưa đón.')
                    ->colspan(1)
            )
            ->add(
                'allowed_gender',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Chỉ dành cho giới tính')
                    ->choices(['' => 'Mọi giới tính', 'female' => 'Nữ', 'male' => 'Nam'])
                    ->colspan(1)
            )
            ->add(
                'allowed_position_id',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Chỉ dành cho vị trí')
                    ->choices(['' => 'Mọi vị trí'] + Position::query()->orderBy('order')->pluck('name', 'id')->all())
                    ->helperText('Vd M9: nữ, Waiter, ở Cam Ranh.')
                    ->colspan(1)
            )
            ->add('lobby_start_time', TimeField::class, $time('Ký hiệu (L): lên Lobby từ')->helperText('Vd M6(L) 09:00–14:00, A2(L) 14:00–17:00. Để trống nếu ca này không có (L).'))
            ->add('lobby_end_time', TimeField::class, $time('Ký hiệu (L): đến'))
            ->add(
                'order',
                NumberField::class,
                NumberFieldOption::make()->label('Thứ tự hiển thị')->defaultValue(0)->colspan(1)
            )
            ->add('status', SelectField::class, StatusFieldOption::make())
            ->setBreakFieldPoint('status');
    }
}
