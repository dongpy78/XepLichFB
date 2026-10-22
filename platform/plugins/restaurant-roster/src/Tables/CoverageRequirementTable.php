<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Models\CoverageRequirement;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\NumberBulkChange;
use Botble\Table\BulkChanges\SelectBulkChange;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class CoverageRequirementTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(CoverageRequirement::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.coverage.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.coverage.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.coverage.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('start_time')->title('Khung giờ Lobby')->width(150)
                    ->getValueUsing(fn (FormattedColumn $c) => '<span class="font-monospace">' . e($c->getItem()->window_label) . '</span>'),
                FormattedColumn::make('shift_codes')->title('Ca được tính')->width(130)
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->shift_codes ? str_replace(',', ', ', $c->getItem()->shift_codes) : 'Mọi ca')),
                FormattedColumn::make('weekday')->title('Áp dụng')
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->date
                        ? 'Ngày ' . $c->getItem()->date->format('d/m/Y')
                        : (\Botble\RestaurantRoster\Models\StaffingRequirement::WEEKDAYS[$c->getItem()->weekday] ?? 'Mọi ngày'))),
                FormattedColumn::make('min_staff')->title('Cùng lúc (tối thiểu / mục tiêu)')->width(210)->alignCenter()
                    ->getValueUsing(fn (FormattedColumn $c) => "<strong>{$c->getItem()->min_staff}</strong> / {$c->getItem()->target_staff}"
                        . ($c->getItem()->female_only ? ' <span class="text-muted small">nữ waiter</span>' : '')),
                FormattedColumn::make('is_confirmed')->title('Trạng thái')->width(170)
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->is_confirmed
                        ? '<span class="badge bg-success-lt">Đã xác nhận</span>'
                        : '<span class="badge bg-warning-lt">Gợi ý – chưa xác nhận</span>'),
                FormattedColumn::make('note')->title('Ghi chú')->orderable(false),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('roster.settings'),
            ])
            ->addBulkChanges([
                SelectBulkChange::make()->name('is_confirmed')->title('Trạng thái')->choices([1 => 'Đã xác nhận', 0 => 'Chưa xác nhận']),
                NumberBulkChange::make()->name('min_staff')->title('Tối thiểu (người)'),
                NumberBulkChange::make()->name('target_staff')->title('Mục tiêu (người)'),
            ])
            ->queryUsing(function ($query): void {
                $query->orderByRaw('date IS NULL DESC, weekday IS NULL DESC')->orderBy('weekday')->orderBy('start_time');
            });
    }
}
