<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class ShiftTemplateTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(ShiftTemplate::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.shift-templates.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.shift-templates.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.shift-templates.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('code')
                    ->title('Mã ca')
                    ->width(110)
                    ->getValueUsing(function (FormattedColumn $column) {
                        $item = $column->getItem();

                        return sprintf('<span class="roster-chip roster-chip--%s">%s</span>', e($item->color), e($item->code));
                    }),
                NameColumn::make()->title('Tên ca')->route('roster.shift-templates.edit')->permission('roster.settings'),
                FormattedColumn::make('start_time')
                    ->title('Khung giờ')
                    ->getValueUsing(fn (FormattedColumn $column) => '<span class="font-monospace">' . e($column->getItem()->time_range) . '</span>'),
                FormattedColumn::make('break_minutes')
                    ->title('Giờ công')
                    ->width(110)
                    ->alignCenter()
                    ->orderable(false)
                    ->getValueUsing(fn (FormattedColumn $column) => number_format($column->getItem()->hours, 1) . 'h'),
                FormattedColumn::make('order')->title('Thứ tự')->width(90)->alignCenter(),
                StatusColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('roster.settings'),
            ])
            ->addBulkChanges([
                StatusBulkChange::make(),
            ])
            ->queryUsing(function ($query): void {
                $query
                    ->select(['id', 'code', 'name', 'start_time', 'end_time', 'split_start_time', 'split_end_time', 'break_minutes', 'color', 'order', 'status'])
                    ->orderBy('order');
            });
    }
}
