<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Models\BaseModel;
use Botble\Base\Supports\Breadcrumb;
use Botble\Support\Http\Requests\Request;

/**
 * CRUD dùng chung cho các danh mục cấu hình xếp lịch (năng lực, định biên, ngày đặc biệt, đơn OFF).
 */
abstract class RosterCrudController extends BaseController
{
    /** @return class-string<BaseModel> */
    abstract protected function model(): string;

    /** @return class-string<\Botble\Base\Forms\FormAbstract> */
    abstract protected function form(): string;

    /** @return class-string<\Botble\Table\Abstracts\TableAbstract> */
    abstract protected function table(): string;

    /** @return class-string<Request> */
    abstract protected function request(): string;

    /** Tiền tố route, ví dụ "roster.skills". */
    abstract protected function routePrefix(): string;

    abstract protected function title(): string;

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add('Xếp lịch nhà hàng', route('roster.index'))
            ->add($this->title(), route($this->routePrefix() . '.index'));
    }

    public function index()
    {
        $this->pageTitle($this->title());

        return app($this->table())->renderTable();
    }

    public function create()
    {
        $this->pageTitle('Thêm – ' . $this->title());

        return $this->form()::create()->renderForm();
    }

    public function store()
    {
        $request = app($this->request());
        $model = $this->model()::query()->create($this->prepare($request->validated(), null));

        return $this
            ->httpResponse()
            ->setPreviousUrl(route($this->routePrefix() . '.index'))
            ->setNextUrl(route($this->routePrefix() . '.edit', $model->getKey()))
            ->withCreatedSuccessMessage();
    }

    public function edit(int|string $id)
    {
        $model = $this->model()::query()->findOrFail($id);
        $this->pageTitle('Sửa – ' . $this->title());

        return $this->form()::createFromModel($model)->renderForm();
    }

    public function update(int|string $id)
    {
        $model = $this->model()::query()->findOrFail($id);
        $request = app($this->request());
        $model->update($this->prepare($request->validated(), $model));

        return $this
            ->httpResponse()
            ->setPreviousUrl(route($this->routePrefix() . '.index'))
            ->withUpdatedSuccessMessage();
    }

    public function destroy(int|string $id)
    {
        return DeleteResourceAction::make($this->model()::query()->findOrFail($id));
    }

    /**
     * Chuẩn hóa dữ liệu trước khi lưu (ghi người duyệt, ô trống → null...).
     */
    protected function prepare(array $data, ?BaseModel $model): array
    {
        return $data;
    }
}
