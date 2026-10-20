<?php

namespace Botble\RestaurantRoster\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class EmployeeSkillRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge(['shift_template_id' => $this->input('shift_template_id') ?: null]);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:roster_employees,id'],
            'outlet_id' => ['required', 'integer', 'exists:roster_outlets,id'],
            'shift_template_id' => [
                'nullable', 'integer', 'exists:roster_shift_templates,id',
                Rule::unique('roster_employee_skills')
                    ->where('employee_id', $this->input('employee_id'))
                    ->where('outlet_id', $this->input('outlet_id'))
                    ->where('shift_template_id', $this->input('shift_template_id'))
                    ->ignore($this->route('skill')),
            ],
            'level' => ['required', Rule::in(['primary', 'eligible'])],
            'is_fixed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['employee_id' => 'Nhân viên', 'outlet_id' => 'Outlet', 'shift_template_id' => 'Ca', 'level' => 'Mức', 'is_fixed' => 'Ca cứng'];
    }

    public function messages(): array
    {
        return ['shift_template_id.unique' => 'Nhân viên này đã có dòng năng lực cho outlet và ca này.'];
    }
}
