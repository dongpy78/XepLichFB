<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\SelectBulkChange;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class EmployeeSkillTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(EmployeeSkill::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.skills.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.skills.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.skills.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('employee_id')
                    ->title('Nhân viên')
                    ->getValueUsing(fn (FormattedColumn $c) => e(($c->getItem()->employee->code ?? '') . ' – ' . ($c->getItem()->employee->name ?? ''))),
                FormattedColumn::make('outlet_id')
                    ->title('Outlet')
                    ->width(120)
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->outlet->code ?? '')),
                FormattedColumn::make('shift_template_id')
                    ->title('Ca')
                    ->width(110)
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->template->code ?? 'Mọi ca')),
                FormattedColumn::make('level')
                    ->title('Mức')
                    ->width(170)
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->level === 'primary'
                        ? '<span class="badge bg-primary-lt">Chuyên trách</span>'
                        : '<span class="badge bg-secondary-lt">Thay thế</span>'),
                FormattedColumn::make('is_fixed')
                    ->title('Ca cứng')
                    ->width(90)
                    ->alignCenter()
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->is_fixed ? '<span class="badge bg-warning-lt">Cứng</span>' : '—'),
                FormattedColumn::make('note')->title('Ghi chú')->orderable(false),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('roster.settings'),
            ])
            ->addBulkChanges([
                SelectBulkChange::make()->name('level')->title('Mức')->choices(['primary' => 'Chuyên trách', 'eligible' => 'Thay thế']),
                SelectBulkChange::make()->name('is_fixed')->title('Ca cứng')->choices([0 => 'Không', 1 => 'Có']),
            ])
            ->queryUsing(function ($query): void {
                $query
                    ->select(['id', 'employee_id', 'outlet_id', 'shift_template_id', 'level', 'is_fixed', 'note'])
                    ->with(['employee:id,code,name', 'outlet:id,code', 'template:id,code'])
                    ->orderBy('employee_id');
            });
    }
}
