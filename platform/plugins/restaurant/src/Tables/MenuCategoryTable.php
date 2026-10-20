<?php

namespace Botble\Restaurant\Tables;

use Botble\Restaurant\Models\MenuCategory;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\NameBulkChange;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class MenuCategoryTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(MenuCategory::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('restaurant.menu-categories.create'))
            ->addActions([
                EditAction::make()->route('restaurant.menu-categories.edit'),
                DeleteAction::make()->route('restaurant.menu-categories.destroy'),
            ])
            ->addColumns([
                IdColumn::make(),
                NameColumn::make()->route('restaurant.menu-categories.edit'),
                FormattedColumn::make('menu_items_count')
                    ->title(trans('plugins/restaurant::menu.menu_items_count'))
                    ->width(100)
                    ->orderable(false)
                    ->searchable(false)
                    ->getValueUsing(function (FormattedColumn $column) {
                        return $column->getItem()->menuItems()->count();
                    })
                    ->alignCenter(),
                FormattedColumn::make('order')
                    ->title(trans('core/base::tables.order'))
                    ->width(100)
                    ->alignCenter(),
                StatusColumn::make(),
                CreatedAtColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('restaurant.menu-categories.destroy'),
            ])
            ->addBulkChanges([
                NameBulkChange::make(),
                StatusBulkChange::make(),
            ])
            ->queryUsing(function ($query): void {
                $query->select([
                    'id',
                    'name',
                    'order',
                    'status',
                    'created_at',
                ])->withCount('menuItems');
            });
    }
}
