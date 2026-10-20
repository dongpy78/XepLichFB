<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Models\OutletDay;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class OutletDayTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(OutletDay::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.outlet-days.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.outlet-days.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.outlet-days.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('date')->title('Ngày')->width(130)
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->date?->format('d/m/Y')),
                FormattedColumn::make('outlet_id')->title('Outlet')->width(120)
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->outlet->code ?? '')),
                FormattedColumn::make('upper_floor_open')->title('Tầng trên')->width(130)
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->upper_floor_open ? '<span class="badge bg-info-lt">Mở</span>' : '—'),
                FormattedColumn::make('is_closed')->title('Hoạt động')->width(130)
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->is_closed ? '<span class="badge bg-danger-lt">Đóng cửa</span>' : 'Mở cửa'),
                FormattedColumn::make('note')->title('Ghi chú')->orderable(false),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('roster.settings'),
            ])
            ->queryUsing(function ($query): void {
                $query->with('outlet:id,code')->orderByDesc('date');
            });
    }
}
