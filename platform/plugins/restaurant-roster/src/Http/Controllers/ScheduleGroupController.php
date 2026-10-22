<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Forms\ScheduleGroupForm;
use Botble\RestaurantRoster\Http\Requests\ScheduleGroupRequest;
use Botble\RestaurantRoster\Models\ScheduleGroup;
use Botble\RestaurantRoster\Tables\ScheduleGroupTable;

/**
 * Nhóm hiển thị Bảng xếp ca (GUS, Villa, Night, Trainer…): thêm / sửa / sắp xếp / bật tắt mà không cần sửa code.
 */
class ScheduleGroupController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Nhóm hiển thị lịch', route('roster.schedule-groups.index'));
    }

    public function index(ScheduleGroupTable $table)
    {
        $this->pageTitle('Nhóm hiển thị lịch');

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle('Thêm nhóm hiển thị');

        return ScheduleGroupForm::create()->renderForm();
    }

    public function store(ScheduleGroupRequest $request)
    {
        $group = ScheduleGroup::query()->create($request->validated());

        return $this->httpResponse()
            ->setPreviousUrl(route('roster.schedule-groups.index'))
            ->setNextUrl(route('roster.schedule-groups.edit', $group->getKey()))
            ->withCreatedSuccessMessage();
    }

    public function edit(ScheduleGroup $group)
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $group->name]));

        return ScheduleGroupForm::createFromModel($group)->renderForm();
    }

    public function update(ScheduleGroup $group, ScheduleGroupRequest $request)
    {
        $group->update($request->validated());

        return $this->httpResponse()->setPreviousUrl(route('roster.schedule-groups.index'))->withUpdatedSuccessMessage();
    }

    public function destroy(ScheduleGroup $group)
    {
        if ($group->employees()->exists()) {
            return $this->httpResponse()->setError()
                ->setMessage('Không thể xóa: nhóm vẫn còn nhân viên. Chuyển nhân viên sang nhóm khác hoặc tắt nhóm (nhân viên sẽ hiện theo outlet chính).');
        }

        return DeleteResourceAction::make($group);
    }
}
