<?php

namespace Botble\RestaurantRoster\Forms;

use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\RestaurantRoster\Http\Requests\AvailabilityRequestRequest;
use Botble\RestaurantRoster\Models\AvailabilityRequest;
use Botble\RestaurantRoster\Models\Employee;

/**
 * Quản lý nhập OFF nhân viên nhắn qua nhóm Zalo. Lưu = đã cho OFF: ghi vào bảng lịch và khóa ngay.
 */
class AvailabilityRequestForm extends FormAbstract
{
    public const STATUSES = [
        'approved' => 'Đã cho OFF (đã ghi vào lịch)',
        'cancelled' => 'Đã hủy (gỡ khỏi lịch)',
        // Trạng thái của đơn cũ trước quy trình mới – chỉ để hiển thị.
        'pending' => 'Chờ xử lý (đơn cũ)',
        'rejected' => 'Không cho OFF',
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
                ->choices(config('plugins.restaurant-roster.general.availability_types', []))
                ->helperText('Nghỉ phép năm / nghỉ ốm ghi thành AL / SL; các loại khác ghi OFF.'))
            ->add('received_at', TextField::class, TextFieldOption::make()->label('Ngày nhận tin nhắn Zalo')->placeholder('YYYY-MM-DD')->colspan(1)
                ->value($editing ? $model->received_at?->toDateString() : now()->toDateString()))
            ->add('group_label', TextField::class, TextFieldOption::make()->label('Tên đợt / sự kiện')->placeholder('VD: Đi chơi đợt 2, Khám sức khỏe nhóm A')->maxLength(120)->colspan(1))
            ->add('reason', TextField::class, TextFieldOption::make()->label('Lý do')->maxLength(255)->colspan(1))
            ->add('review_note', TextField::class, TextFieldOption::make()->label('Ghi chú của quản lý')->maxLength(255)->colspan(2));

        if ($editing) {
            $this->add('status', SelectField::class, SelectFieldOption::make()->label('Trạng thái')->colspan(1)
                ->choices(array_intersect_key(self::STATUSES, array_flip(['approved', 'cancelled'])))
                ->selected($model->status === 'approved' ? 'approved' : 'cancelled')
                ->helperText('Hủy = gỡ các ô OFF của đơn khỏi bảng lịch.'));
        }

        $this->add('override_locked', OnOffCheckboxField::class, OnOffFieldOption::make()
            ->label('Cho OFF đè lên ca làm đã khóa (ca đã xác nhận / tiệc BQ)')
            ->defaultValue(false)
            ->colspan(2)
            ->helperText('Không chọn: ngày nào đang có ca đã khóa thì giữ ca và đánh dấu "cần xem xét" trên bảng lịch để quản lý quyết định.'));
    }
}
