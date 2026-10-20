<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\EmployeeSkillRequest;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\ShiftTemplate;

class EmployeeSkillForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->setupModel(new EmployeeSkill())
            ->setValidatorClass(EmployeeSkillRequest::class)
            ->columns()
            ->add('employee_id', SelectField::class, SelectFieldOption::make()->label('Nhân viên')->required()->searchable()->colspan(2)
                ->choices(Employee::query()->orderBy('outlet_id')->orderBy('code')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->code} – {$e->name}"])->all()))
            ->add('outlet_id', SelectField::class, SelectFieldOption::make()->label('Outlet')->required()->colspan(1)
                ->choices(Outlet::query()->orderBy('order')->pluck('name', 'id')->all()))
            ->add('shift_template_id', SelectField::class, SelectFieldOption::make()->label('Ca')->colspan(1)
                ->choices(['' => 'Mọi ca của outlet'] + ShiftTemplate::query()->orderBy('order')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->code} ({$t->time_range})"])->all()))
            ->add('level', SelectField::class, SelectFieldOption::make()->label('Mức')->required()->colspan(1)
                ->choices(['primary' => 'Chuyên trách – ưu tiên giữ ở ca/outlet này', 'eligible' => 'Đủ điều kiện thay thế'])
                ->helperText('Không có dòng năng lực = KHÔNG đủ điều kiện, thuật toán không bao giờ xếp.'))
            ->add('is_fixed', SelectField::class, SelectFieldOption::make()->label('Ca cứng')->colspan(1)->defaultValue(0)
                ->choices([0 => 'Không', 1 => 'Có – chỉ được xếp vào các ca cứng của mình (hoặc OFF)']))
            ->add('note', TextField::class, TextFieldOption::make()->label('Ghi chú')->maxLength(255)->colspan(2));
    }
}
