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
        $employee->update($request->validated());

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
