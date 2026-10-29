<?php

namespace Botble\RestaurantRoster\Tables;

use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\SelectBulkChange;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\HeaderActions\CreateHeaderAction;

class EmployeeTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(Employee::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('roster.employees.create')->permission('roster.settings'))
            ->addActions([
                EditAction::make()->route('roster.employees.edit')->permission('roster.settings'),
                DeleteAction::make()->route('roster.employees.destroy')->permission('roster.settings'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('code')->title('Mã NV')->width(120),
                NameColumn::make()->title('Họ và tên')->route('roster.employees.edit')->permission('roster.settings'),
                FormattedColumn::make('outlet_id')
                    ->title('Nhà hàng')
                    ->getValueUsing(fn (FormattedColumn $column) => $column->getItem()->outlet->name ?: '&mdash;'),
                FormattedColumn::make('position_id')
                    ->title('Vị trí')
                    ->getValueUsing(fn (FormattedColumn $column) => $column->getItem()->position->name ?: '&mdash;'),
                FormattedColumn::make('fixed_shift_template_id')
                    ->title('Ca cứng')
                    ->width(90)
                    ->getValueUsing(fn (FormattedColumn $column) => $column->getItem()->fixedShift?->code
                        ? '<span class="badge bg-warning-lt">' . e($column->getItem()->fixedShift->code) . '</span>'
                        : '—'),
                FormattedColumn::make('room_service_fixed')
                    ->title('Night / Room Service')
                    ->orderable(false)
                    ->getValueUsing(fn (FormattedColumn $column) => implode(' ', array_filter([
                        $column->getItem()->isNightCore() ? '<span class="badge bg-dark-lt">N cố định</span>' : null,
                        $column->getItem()->room_service_fixed ? '<span class="badge bg-orange-lt">RS cố định</span>'
                            : ($column->getItem()->can_room_service ? '<span class="badge bg-orange-lt">RS hỗ trợ</span>' : null),
                    ])) ?: '&mdash;'),
                FormattedColumn::make('gender')
                    ->title('Giới tính')
                    ->width(100)
                    ->getValueUsing(fn (FormattedColumn $column) => match ($column->getItem()->gender) {
                        'male' => 'Nam',
                        'female' => 'Nữ',
                        default => '<span class="text-warning">Chưa khai báo</span>',
                    }),
                FormattedColumn::make('employment_type')
                    ->title('Hợp đồng')
                    ->width(140)
                    ->getValueUsing(fn (FormattedColumn $column) => $column->getItem()->employment_type_label),
                FormattedColumn::make('weekly_hours')
                    ->title('Giờ / tuần')
                    ->width(110)
                    ->alignCenter()
                    ->getValueUsing(fn (FormattedColumn $column) => rtrim(rtrim(number_format($column->getItem()->weekly_hours, 1), '0'), '.') . 'h'),
                StatusColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('roster.settings'),
            ])
            ->addBulkChanges([
                SelectBulkChange::make()
                    ->name('outlet_id')
                    ->title('Nhà hàng')
                    ->choices(fn () => Outlet::query()->pluck('name', 'id')->all()),
                SelectBulkChange::make()
                    ->name('fixed_shift_template_id')
                    ->title('Ca cứng')
                    ->choices(fn () => [0 => 'Không có ca cứng'] + ShiftTemplate::query()->orderBy('order')->pluck('code', 'id')->all()),
                SelectBulkChange::make()
                    ->name('residence')
                    ->title('Khu vực sinh sống')
                    ->choices(config('plugins.restaurant-roster.general.residences', [])),
                SelectBulkChange::make()
                    ->name('gender')
                    ->title('Giới tính')
                    ->choices(['male' => 'Nam', 'female' => 'Nữ']),
                SelectBulkChange::make()
                    ->name('days_off_per_week')
                    ->title('Số ngày OFF / tuần')
                    ->choices([0 => '0', 1 => '1', 2 => '2', 3 => '3']),
                StatusBulkChange::make(),
            ])
            ->queryUsing(function ($query): void {
                $query
                    ->select(['id', 'code', 'name', 'gender', 'fixed_shift_template_id', 'outlet_id', 'position_id', 'employment_type', 'weekly_hours', 'status', 'night_role', 'can_room_service', 'room_service_fixed'])
                    ->with(['outlet:id,name', 'position:id,name', 'fixedShift:id,code,start_time']);
            });
    }
}
