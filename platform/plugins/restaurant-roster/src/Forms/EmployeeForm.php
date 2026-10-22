<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\EmailFieldOption;
use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\EmailField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\EmployeeRequest;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ShiftTemplate;

class EmployeeForm extends FormAbstract
{
    public function setup(): void
    {
        $outlets = Outlet::query()->orderBy('order')->orderBy('name')->pluck('name', 'id')->all();
        $positions = Position::query()->orderBy('order')->orderBy('name')->pluck('name', 'id')->all();
        $groups = \Botble\RestaurantRoster\Models\ScheduleGroup::query()->orderBy('order')->get()
            ->mapWithKeys(fn ($g) => [$g->id => $g->name . ($g->is_active ? '' : ' (đang tắt)')])->all();
        $model = $this->getModel();
        $mentors = Employee::query()->where('is_trainee', false)->when($model instanceof Employee && $model->exists, fn ($q) => $q->whereKeyNot($model->getKey()))
            ->orderBy('name')->pluck('name', 'id')->all();

        $this
            ->setupModel(new Employee())
            ->setValidatorClass(EmployeeRequest::class)
            ->columns()
            ->add(
                'code',
                TextField::class,
                TextFieldOption::make()->label('Mã nhân viên')->placeholder('VD: NV-8804')->required()->maxLength(30)->colspan(1)
            )
            ->add('name', TextField::class, NameFieldOption::make()->label('Họ và tên')->required()->maxLength(120)->colspan(1))
            ->add(
                'outlet_id',
                SelectField::class,
                SelectFieldOption::make()->label('Nhà hàng')->choices($outlets)->required()->searchable()->colspan(1)
            )
            ->add(
                'schedule_group_id',
                SelectField::class,
                SelectFieldOption::make()->label('Nhóm hiển thị trên Bảng xếp ca')->choices(['' => '— Theo outlet chính —'] + $groups)->colspan(1)
                    ->helperText('Chỉ là khu vực hiển thị (GUS, Villa, Night, Trainer…) – không đổi outlet làm việc, không ảnh hưởng xếp lịch.')
            )
            ->add(
                'schedule_order',
                NumberField::class,
                NumberFieldOption::make()->label('Thứ tự trong nhóm')->min(0)->max(9999)->colspan(1)
                    ->helperText('Số nhỏ hiện trước. Để trống = theo vị trí rồi mã nhân viên.')
            )
            ->add(
                'position_id',
                SelectField::class,
                SelectFieldOption::make()->label('Vị trí')->choices(['' => '— Chưa chọn —'] + $positions)->searchable()->colspan(1)
            )
            ->add(
                'employment_type',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Loại hợp đồng')
                    ->choices(config('plugins.restaurant-roster.general.employment_types', []))
                    ->defaultValue('FT')
                    ->required()
                    ->colspan(1)
            )
            ->add(
                'weekly_hours',
                NumberField::class,
                NumberFieldOption::make()
                    ->label('Giờ chuẩn / tuần')
                    ->helperText('Vượt mức này sẽ tính là tăng ca (OT).')
                    ->defaultValue(48)
                    ->min(1)
                    ->max(72)
                    ->step(0.5)
                    ->required()
                    ->colspan(1)
            )
            ->add(
                'gender',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Giới tính')
                    ->choices(['' => '— Chưa khai báo —', 'male' => 'Nam', 'female' => 'Nữ'])
                    ->helperText('Cần cho định biên số nam (Gusto, Villa, Room).')
                    ->colspan(1)
            )
            ->add(
                'residence',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Khu vực sinh sống')
                    ->choices(['' => '— Chưa khai báo —'] + config('plugins.restaurant-roster.general.residences', []))
                    ->helperText('Ca có xe đưa đón (vd OH) chỉ xếp cho đúng khu vực.')
                    ->colspan(1)
            )
            ->add(
                'fixed_shift_template_id',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Ca cứng')
                    ->choices(['' => '— Không (xếp linh hoạt) —'] + ShiftTemplate::query()->orderBy('order')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->code} ({$t->time_range})"])->all())
                    ->helperText('Xếp tự động chỉ xếp người này vào ca cứng (hoặc OFF). Trên Bảng xếp ca quản lý vẫn đổi được ca; ô đổi khác ca cứng sẽ tự khóa.')
                    ->colspan(1)
            )
            ->add(
                'night_role',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Vai trò Night')
                    ->choices(['' => '— Không (nhân viên thường / luân phiên nếu có năng lực Night) —'] + Employee::NIGHT_ROLES)
                    ->helperText('Night cố định: chọn Ca cứng là ca đêm (N), không cần chọn ở đây. Night đa năng: làm Night, Room Service, hỗ trợ nhà hàng theo năng lực đã khai – được điều chuyển bù nam waiter.')
                    ->colspan(1)
            )
            ->add(
                'days_off_per_week',
                NumberField::class,
                NumberFieldOption::make()
                    ->label('Số ngày OFF / tuần')
                    ->helperText('Thuật toán không xếp quá 7 − số này ngày làm mỗi tuần.')
                    ->defaultValue(2)
                    ->min(0)
                    ->max(6)
                    ->required()
                    ->colspan(1)
            )
            ->add(
                'hire_date',
                TextField::class,
                TextFieldOption::make()->label('Ngày vào làm')->placeholder('YYYY-MM-DD')->colspan(1)
            )
            ->add(
                'auto_schedule',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Xếp lịch tự động')
                    ->choices([1 => 'Có – thuật toán được xếp ca cho người này', 0 => 'Không – chỉ xếp tay'])
                    ->defaultValue(1)
                    ->colspan(1)
            )
            ->add(
                'birthday',
                TextField::class,
                TextFieldOption::make()->label('Sinh nhật (ngày/tháng)')->placeholder('VD: 13/12')->maxLength(5)->colspan(1)
            )
            ->add(
                'is_trainee',
                SelectField::class,
                SelectFieldOption::make()->label('Thực tập sinh')->choices([0 => 'Không – nhân viên chính thức', 1 => 'Có – không tính vào định biên, không xếp tự động'])
                    ->defaultValue(0)->colspan(1)
            )
            ->add('mentor_id', SelectField::class, SelectFieldOption::make()->label('Người hướng dẫn')->choices(['' => '— Không —'] + $mentors)->searchable()->colspan(1))
            ->add('training_from', TextField::class, TextFieldOption::make()->label('Thực tập từ ngày')->placeholder('YYYY-MM-DD')->colspan(1)
                ->value($model instanceof Employee ? $model->training_from?->toDateString() : null))
            ->add('training_to', TextField::class, TextFieldOption::make()->label('Thực tập đến ngày')->placeholder('YYYY-MM-DD')->colspan(1)
                ->value($model instanceof Employee ? $model->training_to?->toDateString() : null))
            ->add('training_note', TextField::class, TextFieldOption::make()->label('Ghi chú thực tập')->maxLength(255)->colspan(2))
            ->add('phone', TextField::class, TextFieldOption::make()->label('Số điện thoại')->maxLength(30)->colspan(1))
            ->add('email', EmailField::class, EmailFieldOption::make()->required(false)->colspan(1))
            ->add('status', SelectField::class, StatusFieldOption::make()->label('Trạng thái (Ngưng hoạt động sẽ ẩn khỏi bảng xếp ca)'))
            ->setBreakFieldPoint('status');
    }
}
