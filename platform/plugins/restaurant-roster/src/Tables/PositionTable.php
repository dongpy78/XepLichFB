<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Models\Position;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\NameBulkChange;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class PositionTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(Position::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.positions.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.positions.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.positions.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                NameColumn::make()->title('Tên vị trí')->route('roster.positions.edit')->permission('roster.settings'),
                FormattedColumn::make('employees_count')
                    ->title('Số nhân viên')
                    ->width(130)
                    ->alignCenter()
                    ->orderable(false)
                    ->searchable(false)
                    ->getValueUsing(fn (FormattedColumn $column) => (string) (int) $column->getItem()->employees_count),
                FormattedColumn::make('order')->title('Thứ tự')->width(100)->alignCenter(),
                StatusColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('roster.settings'),
            ])
            ->addBulkChanges([
                NameBulkChange::make(),
                StatusBulkChange::make(),
            ])
            ->queryUsing(function ($query): void {
                $query
                    ->select(['id', 'name', 'order', 'status'])
                    ->withCount('employees')
                    ->orderBy('order');
            });
    }
}
