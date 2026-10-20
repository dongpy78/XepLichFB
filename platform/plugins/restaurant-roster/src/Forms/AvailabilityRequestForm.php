<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\AvailabilityRequestRequest;
use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Models\Employee;

class AvailabilityRequestForm extends FormAbstract
{
    public const STATUSES = [
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Từ chối',
        'cancelled' => 'Đã hủy',
    ];

    public function setup(): void
    {
        $model = $this->getModel();
        $editing = $model instanceof AvailabilityRequest && $model->exists;
        $employees = Employee::query()->orderBy('outlet_id')->orderBy('code')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->code} – {$e->name}"])->all();

        $this
            ->setupModel(new AvailabilityRequest())
            ->setValidatorClass(AvailabilityRequestRequest::class)
            ->columns();

        if ($editing) {
            $this->add('employee_id', SelectField::class, SelectFieldOption::make()->label('Nhân viên')->required()->searchable()->colspan(2)->choices($employees));
        } else {
            $this->add('employee_ids[]', SelectField::class, SelectFieldOption::make()->label('Nhân viên (chọn được nhiều người cho sự kiện tập thể)')
                ->required()->multiple()->searchable()->colspan(2)->choices($employees));
        }

        $this
            ->add('date_from', TextField::class, TextFieldOption::make()->label('Từ ngày')->placeholder('YYYY-MM-DD')->required()->colspan(1)
                ->value($editing ? $model->date_from?->toDateString() : null))
            ->add('date_to', TextField::class, TextFieldOption::make()->label('Đến ngày')->placeholder('YYYY-MM-DD')->required()->colspan(1)
                ->value($editing ? $model->date_to?->toDateString() : null))
            ->add('type', SelectField::class, SelectFieldOption::make()->label('Loại')->required()->colspan(1)
                ->choices(config('plugins.restaurant-roster.general.availability_types', [])))
            ->add('group_label', TextField::class, TextFieldOption::make()->label('Tên đợt / sự kiện')->placeholder('VD: Đi chơi đợt 2, Khám sức khỏe nhóm A')->maxLength(120)->colspan(1))
            ->add('reason', TextField::class, TextFieldOption::make()->label('Lý do')->maxLength(255)->colspan(2))
            ->add('status', SelectField::class, SelectFieldOption::make()->label('Trạng thái duyệt')->required()->colspan(1)->defaultValue('pending')
                ->choices(self::STATUSES)
                ->helperText('Chỉ đơn "Đã duyệt" mới chặn xếp ca. Đơn chờ duyệt: thuật toán cố tránh nhưng vẫn có thể xếp khi thiếu người.'))
            ->add('review_note', TextField::class, TextFieldOption::make()->label('Ghi chú của người duyệt')->maxLength(255)->colspan(1));
    }
}
