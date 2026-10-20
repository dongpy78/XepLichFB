<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Forms\PositionForm;
use Botble\RestaurantRoster\Http\Requests\PositionRequest;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Tables\PositionTable;

class PositionController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Vị trí', route('roster.positions.index'));
    }

    public function index(PositionTable $table)
    {
        $this->pageTitle('Vị trí công việc');

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle('Thêm vị trí');

        return PositionForm::create()->renderForm();
    }

    public function store(PositionRequest $request)
    {
        $position = Position::query()->create($request->validated());

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.positions.index'))
            ->setNextUrl(route('roster.positions.edit', $position->getKey()))
            ->withCreatedSuccessMessage();
    }

    public function edit(Position $position)
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $position->name]));

        return PositionForm::createFromModel($position)->renderForm();
    }

    public function update(Position $position, PositionRequest $request)
    {
        $position->update($request->validated());

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.positions.index'))
            ->withUpdatedSuccessMessage();
    }

    public function destroy(Position $position)
    {
        return DeleteResourceAction::make($position)->afterDeleting(function () use ($position): void {
            Employee::query()->where('position_id', $position->getKey())->update(['position_id' => null]);
        });
    }
}
