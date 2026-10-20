<?php

namespace Botble\Restaurant\Tables;

use Botble\Base\Facades\Html;
use Botble\Restaurant\Models\MenuCategory;
use Botble\Restaurant\Models\MenuItem;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\NameBulkChange;
use Botble\Table\BulkChanges\SelectBulkChange;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\ImageColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\Columns\YesNoColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class MenuItemTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(MenuItem::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('restaurant.menu-items.create'))
            ->addActions([
                EditAction::make()->route('restaurant.menu-items.edit'),
                DeleteAction::make()->route('restaurant.menu-items.destroy'),
            ])
            ->addColumns([
                IdColumn::make(),
                ImageColumn::make(),
                NameColumn::make()->route('restaurant.menu-items.edit'),
                FormattedColumn::make('category_id')
                    ->title(trans('plugins/restaurant::menu.category'))
                    ->width(150)
                    ->getValueUsing(function (FormattedColumn $column) {
                        return $column->getItem()->category?->name ?: '—';
                    }),
                FormattedColumn::make('price')
                    ->title(trans('plugins/restaurant::menu.price'))
                    ->width(100)
                    ->getValueUsing(function (FormattedColumn $column) {
                        $item = $column->getItem();
                        $price = format_price($item->price);

                        if ($item->sale_price && $item->sale_price < $item->price) {
                            return Html::tag('span', format_price($item->sale_price), ['class' => 'text-success fw-bold']) .
                                   ' ' .
                                   Html::tag('del', $price, ['class' => 'text-muted small']);
                        }

                        return $price;
                    })
                    ->alignCenter(),
                YesNoColumn::make('is_featured')
                    ->title(trans('plugins/restaurant::menu.is_featured'))
                    ->width(100)
                    ->alignCenter(),
                YesNoColumn::make('is_vegetarian')
                    ->title(trans('plugins/restaurant::menu.is_vegetarian'))
                    ->width(100)
                    ->alignCenter(),
                FormattedColumn::make('order')
                    ->title(trans('core/base::tables.order'))
                    ->width(100)
                    ->alignCenter(),
                StatusColumn::make(),
                CreatedAtColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('restaurant.menu-items.destroy'),
            ])
            ->addBulkChanges([
                NameBulkChange::make(),
                SelectBulkChange::make()
                    ->name('category_id')
                    ->title(trans('plugins/restaurant::menu.category'))
                    ->choices(MenuCategory::query()->pluck('name', 'id')->all()),
                StatusBulkChange::make(),
            ])
            ->queryUsing(function ($query): void {
                $query->select([
                    'id',
                    'name',
                    'image',
                    'category_id',
                    'price',
                    'sale_price',
                    'is_featured',
                    'is_vegetarian',
                    'order',
                    'status',
                    'created_at',
                ])->with('category:id,name');
            });
    }
}
