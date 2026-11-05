<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\ScheduleGroup;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class EmployeeRequest extends Request
{
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'code' => [
                // Thêm mới: để trống = hệ thống tự tạo (prepareForValidation) – không "required", vì Botble dựng kiểm tra phía trình duyệt
                // từ các quy tắc này và sẽ chặn form trước khi kịp tự tạo. Sửa: bắt buộc.
                $employee ? 'required' : 'nullable',
                'string',
                'max:30',
                Rule::unique('roster_employees', 'code')->ignore($employee),
            ],
            'name' => ['required', 'string', 'max:120'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'birthday' => ['nullable', 'regex:/^(0?[1-9]|[12]\d|3[01])\/(0?[1-9]|1[0-2])$/'],
            'position_id' => ['required', 'integer', 'exists:roster_positions,id'],
            'schedule_group_id' => ['required', 'integer', 'exists:roster_schedule_groups,id'],
            // Bắt buộc có giá trị sau khi suy từ khu vực (xem prepareForValidation, withValidator).
            'outlet_id' => ['nullable', 'integer', 'exists:roster_outlets,id'],
            'is_trainee' => ['nullable', 'boolean'],
            'residence' => ['required', Rule::in(array_keys(config('plugins.restaurant-roster.general.residences', [])))],
            'days_off_per_week' => ['required', 'integer', 'min:0', 'max:6'],
            'fixed_shift_template_id' => ['nullable', 'integer', 'exists:roster_shift_templates,id'],
            'auto_schedule' => ['nullable', 'boolean'],
            'night_role' => ['nullable', Rule::in(array_keys(\Botble\RestaurantRoster\Models\Employee::NIGHT_ROLES))],
            'can_room_service' => ['nullable', 'boolean'],
            'room_service_fixed' => ['nullable', 'boolean'],
            'status' => Rule::in(BaseStatusEnum::values()),
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'Mã nhân viên',
            'gender' => 'Giới tính',
            'birthday' => 'Sinh nhật',
            'position_id' => 'Vị trí',
            'schedule_group_id' => 'Khu vực',
            'outlet_id' => 'Nhà hàng làm việc',
            'residence' => 'Khu vực sinh sống',
            'days_off_per_week' => 'Số ngày OFF / tuần',
            'night_role' => 'Night cố định',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Thêm mới mà để trống mã → tự tạo mã kế tiếp, không trùng (EmployeeController::store tạo lại nếu bị trùng lúc lưu).
        if (! $this->route('employee') && trim((string) $this->input('code')) === '') {
            $this->merge(['code' => \Botble\RestaurantRoster\Models\Employee::nextCode(), 'code_auto' => true]);
        }

        $this->merge([
            'gender' => $this->input('gender') ?: null,
            'position_id' => $this->input('position_id') ?: null,
            'residence' => $this->input('residence') ?: null,
            'fixed_shift_template_id' => $this->input('fixed_shift_template_id') ?: null,
            'schedule_group_id' => $this->input('schedule_group_id') ?: null,
            'night_role' => $this->input('night_role') ?: null,
        ]);

        // Room Service: một ô chọn trên form → hai cờ của hồ sơ.
        if ($this->has('room_service')) {
            $this->merge(['can_room_service' => $this->input('room_service') === 'support', 'room_service_fixed' => $this->input('room_service') === 'fixed']);
        }

        $group = $this->input('schedule_group_id') ? ScheduleGroup::query()->find($this->input('schedule_group_id')) : null;

        if (! $group) {
            return;
        }

        $employee = $this->route('employee');
        $unchanged = $employee && (int) $employee->schedule_group_id === (int) $group->getKey();
        $outletId = match (true) {
            // Khu vực gắn outlet (GUSTO, VILLA, NIGHT): outlet theo khu vực; không đổi khu vực thì giữ outlet hiện có (dữ liệu cũ, Đổi Waiter Gusto ⇄ Villa).
            (bool) $group->outlet_id => $unchanged ? $employee->outlet_id : $group->outlet_id,
            // Khu vực không gắn outlet (TRAINEE): nhà hàng làm việc do quản lý chọn.
            default => $this->input('outlet_id') ?: ($unchanged ? $employee->outlet_id : null),
        };

        $this->merge(['outlet_id' => $outletId, 'is_trainee' => $group->isTraineeArea()]);

        // Thực tập sinh không tự xếp Night / Room Service.
        if ($group->isTraineeArea()) {
            $this->merge(['night_role' => null, 'can_room_service' => false, 'room_service_fixed' => false]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->has('schedule_group_id')) {
                return;
            }

            $group = ScheduleGroup::query()->find($this->input('schedule_group_id'));

            if (! $group || $group->outlet_id) {
                return;
            }

            $employee = $this->route('employee');
            $codes = (array) config('plugins.restaurant-roster.general.scheduling.transfer_outlets', ['GUSTO', 'VILLA']);
            $isRestaurant = Outlet::query()->whereKey($this->input('outlet_id'))->whereIn('code', $codes)->exists();

            // Dữ liệu cũ đang ở outlet khác vẫn lưu được nếu không đổi.
            if (! $isRestaurant && ! ($employee && (int) $employee->outlet_id === (int) $this->input('outlet_id'))) {
                $validator->errors()->add('outlet_id', 'Chọn nhà hàng làm việc (' . implode(' hoặc ', $codes) . ') cho khu vực ' . $group->name . '.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'birthday.regex' => 'Sinh nhật nhập theo dạng ngày/tháng, ví dụ 13/12.',
        ];
    }
}
