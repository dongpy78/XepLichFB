<?php

namespace Botble\Restaurant\Tables;

use Botble\Restaurant\Models\Chef;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\CreatedAtBulkChange;
use Botble\Table\BulkChanges\NameBulkChange;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\ImageColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class ChefTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(Chef::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('restaurant.chefs.create'))
            ->addActions([
                EditAction::make()->route('restaurant.chefs.edit'),
                DeleteAction::make()->route('restaurant.chefs.destroy'),
            ])
            ->addColumns([
                IdColumn::make(),
                ImageColumn::make(),
                NameColumn::make()->route('restaurant.chefs.edit'),
                FormattedColumn::make('title')
                    ->title(trans('plugins/restaurant::chef.forms.title'))
                    ->getValueUsing(function (FormattedColumn $column) {
                        return $column->getItem()->title ?: '&mdash;';
                    }),
                FormattedColumn::make('experience_years')
                    ->title(trans('plugins/restaurant::chef.forms.experience_years'))
                    ->getValueUsing(function (FormattedColumn $column) {
                        $years = $column->getItem()->experience_years;

                        return $years ? trans('plugins/restaurant::chef.years_of_experience', ['years' => $years]) : '&mdash;';
                    }),
                FormattedColumn::make('order')
                    ->title(trans('core/base::forms.order'))
                    ->width(100)
                    ->alignCenter(),
                StatusColumn::make(),
                CreatedAtColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('restaurant.chefs.destroy'),
            ])
            ->addBulkChanges([
                NameBulkChange::make(),
                StatusBulkChange::make(),
                CreatedAtBulkChange::make(),
            ])
            ->queryUsing(function ($query): void {
                $query->select([
                    'id',
                    'name',
                    'title',
                    'image',
                    'experience_years',
                    'order',
                    'status',
                    'created_at',
                ]);
            });
    }
}
