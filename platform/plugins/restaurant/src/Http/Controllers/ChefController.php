<?php

namespace Botble\Restaurant\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Breadcrumb;
use Botble\Restaurant\Forms\ChefForm;
use Botble\Restaurant\Http\Requests\ChefRequest;
use Botble\Restaurant\Models\Chef;
use Botble\Restaurant\Tables\ChefTable;

class ChefController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/restaurant::restaurant.menu_name'))
            ->add(trans('plugins/restaurant::chef.name'), route('restaurant.chefs.index'));
    }

    public function index(ChefTable $table)
    {
        $this->pageTitle(trans('plugins/restaurant::chef.name'));

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle(trans('plugins/restaurant::chef.create'));

        return ChefForm::create()->renderForm();
    }

    public function store(ChefRequest $request)
    {
        $data = $request->validated();

        if (isset($data['social_links'])) {
            $data['social_links'] = array_filter($data['social_links']);
        }

        $chef = Chef::query()->create($data);

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('restaurant.chefs.index'))
            ->setNextUrl(route('restaurant.chefs.edit', $chef->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function edit(Chef $chef)
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $chef->name]));

        return ChefForm::createFromModel($chef)->renderForm();
    }

    public function update(Chef $chef, ChefRequest $request)
    {
        $data = $request->validated();

        if (isset($data['social_links'])) {
            $data['social_links'] = array_filter($data['social_links']);
        }

        $chef->update($data);

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('restaurant.chefs.index'))
            ->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function destroy(Chef $chef)
    {
        return DeleteResourceAction::make($chef);
    }
}
