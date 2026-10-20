<?php

namespace Botble\Restaurant\Tables;

use Botble\Restaurant\Enums\ReservationStatusEnum;
use Botble\Restaurant\Models\Reservation;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\Actions\ViewAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\SelectBulkChange;
use Botble\Table\Columns\Column;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\EmailColumn;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class ReservationTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(Reservation::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('restaurant.reservations.create'))
            ->addActions([
                ViewAction::make()->route('restaurant.reservations.show'),
                EditAction::make()->route('restaurant.reservations.edit'),
                DeleteAction::make()->route('restaurant.reservations.destroy'),
            ])
            ->addColumns([
                IdColumn::make(),
                NameColumn::make()->route('restaurant.reservations.show'),
                EmailColumn::make(),
                FormattedColumn::make('phone')
                    ->title(trans('plugins/restaurant::reservation.phone'))
                    ->width(120),
                FormattedColumn::make('reservation_datetime')
                    ->title(trans('plugins/restaurant::reservation.reservation_datetime'))
                    ->width(200)
                    ->orderable(false)
                    ->searchable(false)
                    ->getValueUsing(function (FormattedColumn $column) {
                        $item = $column->getItem();

                        return trans('plugins/restaurant::reservation.datetime_format', [
                            'date' => $item->reservation_date->format('M d, Y'),
                            'time' => $item->reservation_time,
                        ]);
                    }),
                Column::make('number_of_guests')
                    ->title(trans('plugins/restaurant::reservation.guests'))
                    ->width(100)
                    ->alignCenter(),
                StatusColumn::make(),
                CreatedAtColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('restaurant.reservations.destroy'),
            ])
            ->addBulkChanges([
                SelectBulkChange::make()
                    ->name('status')
                    ->title(trans('core/base::tables.status'))
                    ->choices(ReservationStatusEnum::labels()),
            ])
            ->queryUsing(function ($query): void {
                $query->select([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'reservation_date',
                    'reservation_time',
                    'number_of_guests',
                    'status',
                    'created_at',
                ])->orderBy('reservation_date', 'desc')
                  ->orderBy('reservation_time', 'desc');
            });
    }
}
