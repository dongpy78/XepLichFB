<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Forms\EmployeeForm;
use Botble\RestaurantRoster\Http\Requests\EmployeeRequest;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Tables\EmployeeTable;

class EmployeeController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Nhân viên', route('roster.employees.index'));
    }

    public function index(EmployeeTable $table)
    {
        $this->pageTitle('Nhân viên');

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle('Thêm nhân viên');

        return EmployeeForm::create()->renderForm();
    }

    public function store(EmployeeRequest $request)
    {
        $employee = Employee::query()->create($request->validated());

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.employees.index'))
            ->setNextUrl(route('roster.employees.edit', $employee->getKey()))
            ->withCreatedSuccessMessage();
    }

    public function edit(Employee $employee)
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $employee->name]));

        return EmployeeForm::createFromModel($employee)->renderForm();
    }

    public function update(Employee $employee, EmployeeRequest $request)
    {
        $watched = ['outlet_id', 'schedule_group_id', 'night_role', 'is_trainee', 'fixed_shift_template_id', 'auto_schedule'];
        $before = array_intersect_key($employee->getAttributes(), array_flip($watched));
        $employee->update($request->validated());
        $after = array_intersect_key($employee->fresh()->getAttributes(), array_flip($watched));

        // Nhật ký: đổi outlet / nhóm hiển thị / vai trò Night / thực tập sinh ảnh hưởng tới định biên và luân phiên.
        if ($changed = array_values(array_filter($watched, fn ($k) => ($before[$k] ?? null) != ($after[$k] ?? null)))) {
            \Botble\RestaurantRoster\Models\ChangeLog::query()->create([
                'employee_id' => $employee->getKey(), 'date' => now()->toDateString(), 'action' => 'profile',
                'before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)),
                'reason' => 'Sửa hồ sơ nhân viên: ' . implode(', ', $changed), 'user_id' => auth()->id(),
            ]);
        }

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.employees.index'))
            ->withUpdatedSuccessMessage();
    }

    public function destroy(Employee $employee)
    {
        return DeleteResourceAction::make($employee)->afterDeleting(function () use ($employee): void {
            $employee->shifts()->delete();
        });
    }
}
