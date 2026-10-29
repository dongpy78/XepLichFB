<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class EmployeeRequest extends Request
{
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('roster_employees', 'code')->ignore($employee),
            ],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'birthday' => ['nullable', 'regex:/^(0?[1-9]|[12]\d|3[01])\/(0?[1-9]|1[0-2])$/'],
            'outlet_id' => ['required', 'integer', 'exists:roster_outlets,id'],
            'schedule_group_id' => ['nullable', 'integer', 'exists:roster_schedule_groups,id'],
            'schedule_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'night_role' => ['nullable', Rule::in(array_keys(\Botble\RestaurantRoster\Models\Employee::NIGHT_ROLES))],
            'is_trainee' => ['nullable', 'boolean'],
            'can_room_service' => ['nullable', 'boolean'],
            'room_service_fixed' => ['nullable', 'boolean'],
            'mentor_id' => ['nullable', 'integer', 'exists:roster_employees,id'],
            'training_from' => ['nullable', 'date_format:Y-m-d'],
            'training_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:training_from'],
            'training_note' => ['nullable', 'string', 'max:255'],
            'position_id' => ['nullable', 'integer', 'exists:roster_positions,id'],
            'employment_type' => ['required', Rule::in(array_keys(config('plugins.restaurant-roster.general.employment_types', [])))],
            'weekly_hours' => ['required', 'numeric', 'min:1', 'max:72'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'fixed_shift_template_id' => ['nullable', 'integer', 'exists:roster_shift_templates,id'],
            'residence' => ['nullable', Rule::in(array_keys(config('plugins.restaurant-roster.general.residences', [])))],
            'days_off_per_week' => ['required', 'integer', 'min:0', 'max:6'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'auto_schedule' => ['nullable', 'boolean'],
            'status' => Rule::in(BaseStatusEnum::values()),
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'Mã nhân viên',
            'outlet_id' => 'Nhà hàng',
            'position_id' => 'Vị trí',
            'weekly_hours' => 'Giờ chuẩn / tuần',
            'birthday' => 'Sinh nhật',
            'gender' => 'Giới tính',
            'days_off_per_week' => 'Số ngày OFF / tuần',
            'hire_date' => 'Ngày vào làm',
            'schedule_group_id' => 'Nhóm hiển thị',
            'night_role' => 'Vai trò Night',
            'can_room_service' => 'Có thể hỗ trợ Room Service',
            'room_service_fixed' => 'Nhân viên Room Service cố định',
            'mentor_id' => 'Người hướng dẫn',
            'training_from' => 'Thực tập từ ngày',
            'training_to' => 'Thực tập đến ngày',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gender' => $this->input('gender') ?: null,
            'hire_date' => $this->input('hire_date') ?: null,
            'fixed_shift_template_id' => $this->input('fixed_shift_template_id') ?: null,
            'residence' => $this->input('residence') ?: null,
            'schedule_group_id' => $this->input('schedule_group_id') ?: null,
            'schedule_order' => $this->input('schedule_order') === '' ? null : $this->input('schedule_order'),
            'night_role' => $this->input('night_role') ?: null,
            'mentor_id' => $this->input('mentor_id') ?: null,
            'training_from' => $this->input('training_from') ?: null,
            'training_to' => $this->input('training_to') ?: null,
        ]);
    }

    public function messages(): array
    {
        return [
            'birthday.regex' => 'Sinh nhật nhập theo dạng ngày/tháng, ví dụ 13/12.',
        ];
    }
}
