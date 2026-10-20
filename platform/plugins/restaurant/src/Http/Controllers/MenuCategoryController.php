<?php

namespace Botble\Restaurant\Http\Controllers;

use Botble\Base\Events\BeforeEditContentEvent;
use Botble\Base\Events\CreatedContentEvent;
use Botble\Base\Events\DeletedContentEvent;
use Botble\Base\Events\UpdatedContentEvent;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Restaurant\Forms\MenuCategoryForm;
use Botble\Restaurant\Http\Requests\MenuCategoryRequest;
use Botble\Restaurant\Models\MenuCategory;
use Botble\Restaurant\Tables\MenuCategoryTable;
use Exception;
use Illuminate\Http\Request;

class MenuCategoryController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/restaurant::restaurant.menu_name'))
            ->add(trans('plugins/restaurant::menu.menu_categories'), route('restaurant.menu-categories.index'));
    }

    public function index(MenuCategoryTable $table)
    {
        $this->pageTitle(trans('plugins/restaurant::menu.menu_categories'));

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle(trans('plugins/restaurant::menu.create_menu_category'));

        return MenuCategoryForm::create()->renderForm();
    }

    public function store(MenuCategoryRequest $request, BaseHttpResponse $response)
    {
        $menuCategory = MenuCategory::query()->create($request->validated());

        event(new CreatedContentEvent(MENU_CATEGORY_MODULE_SCREEN_NAME, $request, $menuCategory));

        return $response
            ->setPreviousUrl(route('restaurant.menu-categories.index'))
            ->setNextUrl(route('restaurant.menu-categories.edit', $menuCategory->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function edit(int|string $id, Request $request)
    {
        $menuCategory = MenuCategory::query()->findOrFail($id);

        event(new BeforeEditContentEvent($request, $menuCategory));

        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $menuCategory->name]));

        return MenuCategoryForm::createFromModel($menuCategory)->renderForm();
    }

    public function update(int|string $id, MenuCategoryRequest $request, BaseHttpResponse $response)
    {
        $menuCategory = MenuCategory::query()->findOrFail($id);

        $menuCategory->update($request->validated());

        event(new UpdatedContentEvent(MENU_CATEGORY_MODULE_SCREEN_NAME, $request, $menuCategory));

        return $response
            ->setPreviousUrl(route('restaurant.menu-categories.index'))
            ->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function destroy(int|string $id, Request $request, BaseHttpResponse $response)
    {
        try {
            $menuCategory = MenuCategory::query()->findOrFail($id);

            $menuCategory->delete();

            event(new DeletedContentEvent(MENU_CATEGORY_MODULE_SCREEN_NAME, $request, $menuCategory));

            return $response->setMessage(trans('core/base::notices.delete_success_message'));
        } catch (Exception $exception) {
            return $response
                ->setError()
                ->setMessage($exception->getMessage());
        }
    }
}
