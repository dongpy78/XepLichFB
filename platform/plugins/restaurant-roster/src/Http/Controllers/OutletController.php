<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\RestaurantRoster\Forms\OutletForm;
use Botble\RestaurantRoster\Http\Requests\OutletRequest;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Tables\OutletTable;

class OutletController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add('Nhà hàng / Outlet', route('roster.outlets.index'));
    }

    public function index(OutletTable $table)
    {
        $this->pageTitle('Nhà hàng / Outlet');

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle('Thêm nhà hàng');

        return OutletForm::create()->renderForm();
    }

    public function store(OutletRequest $request)
    {
        $outlet = Outlet::query()->create($request->validated());

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.outlets.index'))
            ->setNextUrl(route('roster.outlets.edit', $outlet->getKey()))
            ->withCreatedSuccessMessage();
    }

    public function edit(Outlet $outlet)
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $outlet->name]));

        return OutletForm::createFromModel($outlet)->renderForm();
    }

    public function update(Outlet $outlet, OutletRequest $request)
    {
        $outlet->update($request->validated());

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('roster.outlets.index'))
            ->withUpdatedSuccessMessage();
    }

    public function destroy(Outlet $outlet)
    {
        if ($outlet->employees()->exists()) {
            return $this
                ->httpResponse()
                ->setError()
                ->setMessage('Không thể xóa: nhà hàng này vẫn còn nhân viên. Hãy chuyển nhân viên sang nhà hàng khác trước.');
        }

        return DeleteResourceAction::make($outlet);
    }
}
