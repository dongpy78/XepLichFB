<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\HtmlFieldOption;
use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\EmployeeRequest;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ScheduleGroup;
use Botble\RestaurantRoster\Models\ShiftTemplate;

class EmployeeForm extends FormAbstract
{
    public function setup(): void
    {
        $model = $this->getModel();
        $employee = $model instanceof Employee && $model->exists ? $model : null;
        $groups = ScheduleGroup::query()->orderBy('order')->get();
        // Khu vực trên Bảng xếp ca: đang bật (giữ khu vực hiện tại của nhân viên dù đã tắt). Khu vực không gắn outlet (TRAINEE) phải chọn nhà hàng làm việc.
        $areas = $groups->filter(fn (ScheduleGroup $g) => $g->is_active || (int) $g->getKey() === (int) $employee?->schedule_group_id);
        $restaurantCodes = (array) config('plugins.restaurant-roster.general.scheduling.transfer_outlets', ['GUSTO', 'VILLA']);
        $restaurants = Outlet::query()->whereIn('code', $restaurantCodes)->orderBy('order')->pluck('name', 'id')->all();
        // Dữ liệu cũ chưa có khu vực: chọn sẵn khu vực của outlet hiện tại.
        $currentArea = $employee?->schedule_group_id ?: $groups->firstWhere('outlet_id', $employee?->outlet_id)?->getKey();
        $noOutletAreas = array_values($areas->whereNull('outlet_id')->modelKeys());

        $this
            ->setupModel(new Employee())
            ->setValidatorClass(EmployeeRequest::class)
            ->columns()
            ->add(
                'code',
                TextField::class,
                $employee
                    ? TextFieldOption::make()->label('Mã nhân viên')->required()->maxLength(30)->colspan(1)
                    : TextFieldOption::make()->label('Mã nhân viên')->placeholder('Tự tạo: ' . Employee::nextCode())->maxLength(30)->colspan(1)
                        ->helperText('Để trống – hệ thống tự tạo mã kế tiếp, không trùng.')
            )
            ->add('name', TextField::class, NameFieldOption::make()->label('Họ và tên')->required()->maxLength(120)->colspan(1))
            ->add(
                'gender',
                SelectField::class,
                SelectFieldOption::make()->label('Giới tính')->choices(['' => '— Chọn —', 'female' => 'Nữ', 'male' => 'Nam'])->required()->colspan(1)
                    ->helperText('Dùng để cân bằng nam / nữ mỗi ca và xếp Lobby.')
            )
            ->add(
                'birthday',
                TextField::class,
                TextFieldOption::make()->label('Sinh nhật (ngày/tháng)')->placeholder('VD: 13/12')->maxLength(5)->colspan(1)
                    ->helperText('Tự động OFF đúng ngày sinh nhật.')
            )
            ->add(
                'position_id',
                SelectField::class,
                SelectFieldOption::make()->label('Vị trí')->choices(['' => '— Chọn —'] + Position::query()->orderBy('order')->orderBy('name')->pluck('name', 'id')->all())
                    ->required()->searchable()->colspan(1)
            )
            ->add(
                'schedule_group_id',
                SelectField::class,
                SelectFieldOption::make()->label('Khu vực')->choices(['' => '— Chọn —'] + $areas->mapWithKeys(fn (ScheduleGroup $g) => [$g->getKey() => $g->name . ($g->is_active ? '' : ' (đang tắt)')])->all())
                    ->selected($currentArea)->required()->colspan(1)
                    ->helperText('Khu vực hiển thị trên Bảng xếp ca. TRAINEE = thực tập sinh: được xếp lịch tự động nhưng không tính vào định biên.')
            )
            ->add(
                'outlet_id',
                SelectField::class,
                SelectFieldOption::make()->label('Nhà hàng làm việc')->choices(['' => '— Chọn —'] + $restaurants)->colspan(1)
                    ->helperText('Chỉ cần chọn khi Khu vực là TRAINEE.')
            )
            ->add(
                'residence',
                SelectField::class,
                SelectFieldOption::make()->label('Khu vực sinh sống')->choices(['' => '— Chọn —'] + config('plugins.restaurant-roster.general.residences', []))
                    ->required()->colspan(1)
                    ->helperText('Ca có xe đưa đón chỉ xếp đúng khu vực: OH – Nha Trang, M9 – Cam Ranh.')
            )
            ->add(
                'days_off_per_week',
                NumberField::class,
                NumberFieldOption::make()->label('Số ngày OFF / tuần')->defaultValue(2)->min(0)->max(6)->required()->colspan(1)
            )
            ->add(
                'fixed_shift_template_id',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Ca cứng')
                    ->choices(['' => '— Không (xếp linh hoạt) —'] + ShiftTemplate::query()->orderBy('order')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->code} ({$t->time_range})"])->all())
                    ->helperText('Xếp tự động chỉ xếp người này vào ca cứng (hoặc OFF). Trên Bảng xếp ca vẫn đổi được ca.')
                    ->colspan(1)
            )
            ->add(
                'auto_schedule',
                SelectField::class,
                SelectFieldOption::make()->label('Xếp lịch tự động')->choices([1 => 'Có', 0 => 'Không – chỉ xếp tay'])->defaultValue(1)->colspan(1)
            )
            ->add(
                'night_role',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Night cố định')
                    ->choices(['' => 'Không', Employee::NIGHT_CORE => 'Có – tự động xếp ca đêm (N)'])
                    ->helperText('Night luân phiên: quản lý xếp tay ca N trên Bảng xếp ca.')
                    ->colspan(1)
            )
            ->add(
                'room_service',
                SelectField::class,
                SelectFieldOption::make()
                    ->label('Room Service')
                    ->choices(['none' => 'Không', 'support' => 'Hỗ trợ – thay khi Room Service thiếu người', 'fixed' => 'Cố định – luôn làm Room Service'])
                    ->selected($employee?->room_service_fixed ? 'fixed' : ($employee?->can_room_service ? 'support' : 'none'))
                    ->colspan(1)
            )
            ->add('status', SelectField::class, StatusFieldOption::make()->label('Trạng thái (Ngưng hoạt động sẽ ẩn khỏi bảng xếp ca)'))
            ->add(
                'area_script',
                HtmlField::class,
                // Chỉ hiện "Nhà hàng làm việc" cho khu vực không gắn outlet (TRAINEE); ẩn Night / Room Service với thực tập sinh. Lỗi JS thì các ô vẫn hiện – máy chủ vẫn kiểm tra.
                HtmlFieldOption::make()->content('<script>document.addEventListener("DOMContentLoaded", function () {
                    var area = document.querySelector("[name=schedule_group_id]"), noOutlet = ' . json_encode(array_map('strval', $noOutletAreas)) . ';
                    if (!area) { return; }
                    var wrap = function (name) { var el = document.querySelector("[name=" + name + "]"); return el && (el.closest("[class*=col-]") || el.parentElement); };
                    var sync = function () {
                        var free = noOutlet.indexOf(area.value) !== -1;
                        [["outlet_id", free], ["night_role", !free], ["room_service", !free]].forEach(function (f) { var w = wrap(f[0]); if (w) { w.style.display = f[1] ? "" : "none"; } });
                    };
                    area.addEventListener("change", sync);
                    if (window.jQuery) { window.jQuery(area).on("change", sync); }
                    sync();
                });</script>')
            )
            ->setBreakFieldPoint('status');
    }
}
