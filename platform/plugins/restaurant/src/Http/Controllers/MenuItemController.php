<?php

namespace Botble\Restaurant\Http\Controllers;

use Botble\Base\Events\BeforeEditContentEvent;
use Botble\Base\Events\CreatedContentEvent;
use Botble\Base\Events\DeletedContentEvent;
use Botble\Base\Events\UpdatedContentEvent;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Restaurant\Forms\MenuItemForm;
use Botble\Restaurant\Http\Requests\MenuItemRequest;
use Botble\Restaurant\Models\MenuItem;
use Botble\Restaurant\Tables\MenuItemTable;
use Exception;
use Illuminate\Http\Request;

class MenuItemController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/restaurant::restaurant.menu_name'))
            ->add(trans('plugins/restaurant::menu.menu_items'), route('restaurant.menu-items.index'));
    }

    public function index(MenuItemTable $table)
    {
        $this->pageTitle(trans('plugins/restaurant::menu.menu_items'));

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle(trans('plugins/restaurant::menu.create_menu_item'));

        return MenuItemForm::create()->renderForm();
    }

    public function store(MenuItemRequest $request, BaseHttpResponse $response)
    {
        $menuItem = MenuItem::query()->create($request->validated());

        event(new CreatedContentEvent(MENU_ITEM_MODULE_SCREEN_NAME, $request, $menuItem));

        return $response
            ->setPreviousUrl(route('restaurant.menu-items.index'))
            ->setNextUrl(route('restaurant.menu-items.edit', $menuItem->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function edit(int|string $id, Request $request)
    {
        $menuItem = MenuItem::query()->findOrFail($id);

        event(new BeforeEditContentEvent($request, $menuItem));

        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $menuItem->name]));

        return MenuItemForm::createFromModel($menuItem)->renderForm();
    }

    public function update(int|string $id, MenuItemRequest $request, BaseHttpResponse $response)
    {
        $menuItem = MenuItem::query()->findOrFail($id);

        $menuItem->update($request->validated());

        event(new UpdatedContentEvent(MENU_ITEM_MODULE_SCREEN_NAME, $request, $menuItem));

        return $response
            ->setPreviousUrl(route('restaurant.menu-items.index'))
            ->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function destroy(int|string $id, Request $request, BaseHttpResponse $response)
    {
        try {
            $menuItem = MenuItem::query()->findOrFail($id);

            $menuItem->delete();

            event(new DeletedContentEvent(MENU_ITEM_MODULE_SCREEN_NAME, $request, $menuItem));

            return $response->setMessage(trans('core/base::notices.delete_success_message'));
        } catch (Exception $exception) {
            return $response
                ->setError()
                ->setMessage($exception->getMessage());
        }
    }
}
