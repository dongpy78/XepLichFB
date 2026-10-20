<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Forms\AvailabilityRequestForm;
use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\Action;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class AvailabilityRequestTable extends TableAbstract
{
    public function setup(): void
    {
        $types = config('plugins.restaurant-roster.general.availability_types', []);
        $badges = ['pending' => 'bg-warning-lt', 'approved' => 'bg-success-lt', 'rejected' => 'bg-danger-lt', 'cancelled' => 'bg-secondary-lt'];

        $this
            ->model(AvailabilityRequest::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.availability.create')->permission('roster.index'))
            ->addActions([
                Action::make('approve')->label('Duyệt')->icon('ti ti-check')->color('success')
                    ->route('roster.availability.approve')->action('POST')->permission('roster.approve'),
                Action::make('reject')->label('Từ chối')->icon('ti ti-x')->color('warning')
                    ->route('roster.availability.reject')->action('POST')->permission('roster.approve'),
                EditAction::make()->route('roster.availability.edit')->permission('roster.index'),
                DeleteAction::make()->route('roster.availability.destroy')->permission('roster.approve'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('employee_id')->title('Nhân viên')
                    ->getValueUsing(fn (FormattedColumn $c) => e(($c->getItem()->employee->code ?? '') . ' – ' . ($c->getItem()->employee->name ?? ''))),
                FormattedColumn::make('date_from')->title('Thời gian')->width(190)
                    ->getValueUsing(function (FormattedColumn $c) {
                        $item = $c->getItem();

                        return $item->date_from->equalTo($item->date_to)
                            ? $item->date_from->format('d/m/Y')
                            : $item->date_from->format('d/m') . ' → ' . $item->date_to->format('d/m/Y');
                    }),
                FormattedColumn::make('type')->title('Loại')->width(150)
                    ->getValueUsing(fn (FormattedColumn $c) => e($types[$c->getItem()->type] ?? $c->getItem()->type)),
                FormattedColumn::make('group_label')->title('Đợt / sự kiện')
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->group_label ?: ($c->getItem()->reason ?: '—'))),
                FormattedColumn::make('status')->title('Trạng thái')->width(120)
                    ->getValueUsing(fn (FormattedColumn $c) => sprintf('<span class="badge %s">%s</span>',
                        $badges[$c->getItem()->status] ?? '', e(AvailabilityRequestForm::STATUSES[$c->getItem()->status] ?? $c->getItem()->status))),
                FormattedColumn::make('reviewed_at')->title('Duyệt lúc')->width(140)
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->reviewed_at?->format('d/m H:i') ?? '—'),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('roster.approve'),
            ])
            ->queryUsing(function ($query): void {
                $query
                    ->with('employee:id,code,name')
                    ->orderByRaw("status = 'pending' DESC")
                    ->orderByDesc('date_from');
            });
    }
}
