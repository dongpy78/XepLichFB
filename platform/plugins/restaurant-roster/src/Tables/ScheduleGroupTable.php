<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Models\ScheduleGroup;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class ScheduleGroupTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(ScheduleGroup::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.schedule-groups.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.schedule-groups.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.schedule-groups.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                NameColumn::make()->title('Nhóm hiển thị')->route('roster.schedule-groups.edit')->permission('roster.settings'),
                FormattedColumn::make('code')->title('Mã')->width(120),
                FormattedColumn::make('outlet_id')->title('Outlet thống kê')->width(160)->orderable(false)
                    ->getValueUsing(fn (FormattedColumn $c) => e($c->getItem()->outlet?->code ?: '—')),
                FormattedColumn::make('employees_count')->title('Số nhân viên')->width(120)->alignCenter()->orderable(false)->searchable(false)
                    ->getValueUsing(fn (FormattedColumn $c) => (string) (int) $c->getItem()->employees_count),
                FormattedColumn::make('order')->title('Thứ tự')->width(90)->alignCenter(),
                FormattedColumn::make('is_active')->title('Trạng thái')->width(130)
                    ->getValueUsing(fn (FormattedColumn $c) => $c->getItem()->is_active
                        ? '<span class="badge bg-success text-white">Đang dùng</span>' : '<span class="badge bg-secondary text-white">Tắt</span>'),
            ])
            ->queryUsing(fn ($query) => $query->select(['id', 'name', 'code', 'outlet_id', 'order', 'is_active'])->with('outlet:id,code')->withCount('employees')->orderBy('order'));
    }
}
