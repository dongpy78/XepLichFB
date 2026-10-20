<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Forms\StaffingRequirementForm;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\NumberBulkChange;
use Botble\Table\BulkChanges\SelectBulkChange;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class StaffingRequirementTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(StaffingRequirement::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.requirements.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.requirements.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.requirements.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('outlet_id')->title('Outlet')->width(90)
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->outlet->code ?? '')),
                FormattedColumn::make('shift_template_id')->title('Ca')->width(70)
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->template->code ?? '')),
                FormattedColumn::make('weekday')->title('Áp dụng')
                    ->getValueUsing(function (FormattedColumn $c) {
                        $item = $c->getItem();
                        $when = $item->date ? 'Ngày ' . $item->date->format('d/m/Y') : (StaffingRequirementForm::WEEKDAYS[$item->weekday] ?? 'Mọi ngày');

                        return e($when) . match (true) {
                            $item->upper_floor === null => '',
                            (bool) $item->upper_floor => ' <span class="badge bg-info-lt">Mở tầng trên</span>',
                            default => ' <span class="badge bg-secondary-lt">Không mở tầng trên</span>',
                        };
                    }),
                FormattedColumn::make('min_staff')->title('Người (tối thiểu / mục tiêu)')->width(170)->alignCenter()
                    ->getValueUsing(fn (FormattedColumn $c) => "<strong>{$c->getItem()->min_staff}</strong> / {$c->getItem()->target_staff}"),
                FormattedColumn::make('min_male')->title('Nam (tối thiểu / mục tiêu)')->width(170)->alignCenter()
                    ->getValueUsing(fn (FormattedColumn $c) => "<strong>{$c->getItem()->min_male}</strong> / {$c->getItem()->target_male}"),
                FormattedColumn::make('is_confirmed')->title('Trạng thái')->width(150)
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
                NumberBulkChange::make()->name('min_male')->title('Nam tối thiểu'),
                NumberBulkChange::make()->name('target_male')->title('Nam mục tiêu'),
            ])
            ->queryUsing(function ($query): void {
                $query
                    ->with(['outlet:id,code,order', 'template:id,code,order'])
                    ->orderBy('outlet_id')
                    ->orderBy('shift_template_id')
                    ->orderByRaw('date IS NULL DESC, weekday IS NULL DESC')
                    ->orderBy('weekday')
                    ->orderBy('upper_floor');
            });
    }
}
