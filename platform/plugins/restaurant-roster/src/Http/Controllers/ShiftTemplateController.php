<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Forms\ShiftTemplateForm;
use Botble\RestaurantRoster\Http\Requests\ShiftTemplateRequest;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Tables\ShiftTemplateTable;

class ShiftTemplateController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Mẫu ca', route('roster.shift-templates.index'));
    }

    public function index(ShiftTemplateTable $table)
    {
        $this->pageTitle('Mẫu ca làm việc');

        Assets::addStylesDirectly('vendor/core/plugins/restaurant-roster/css/roster.css');

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle('Thêm mẫu ca');

        return ShiftTemplateForm::create()->renderForm();
    }

    public function store(ShiftTemplateRequest $request)
    {
        $template = ShiftTemplate::query()->create($request->validated());

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.shift-templates.index'))
            ->setNextUrl(route('roster.shift-templates.edit', $template->getKey()))
            ->withCreatedSuccessMessage();
    }

    public function edit(ShiftTemplate $shiftTemplate)
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $shiftTemplate->name]));

        return ShiftTemplateForm::createFromModel($shiftTemplate)->renderForm();
    }

    public function update(ShiftTemplate $shiftTemplate, ShiftTemplateRequest $request)
    {
        // Ca đã xếp giữ nguyên giờ cũ; mẫu mới chỉ áp dụng cho các lần gán sau.
        $shiftTemplate->update($request->validated());

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.shift-templates.index'))
            ->withUpdatedSuccessMessage();
    }

    public function destroy(ShiftTemplate $shiftTemplate)
    {
        return DeleteResourceAction::make($shiftTemplate);
    }
}
