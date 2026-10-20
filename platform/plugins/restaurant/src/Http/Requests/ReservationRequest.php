<?php

namespace Botble\Restaurant\Http\Requests;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Rules\EmailRule;
use Botble\Restaurant\Enums\ReservationStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class ReservationRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'max:120', 'min:6', new EmailRule()],
            'phone' => ['nullable', ...BaseHelper::getPhoneValidationRule(true)],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'reservation_time' => ['required', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'number_of_guests' => ['required', 'integer', 'min:1', 'max:20'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(ReservationStatusEnum::values())],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reservation_time')) {
            $time = $this->input('reservation_time');
            if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $time, $matches)) {
                $this->merge([
                    'reservation_time' => sprintf('%02d:%02d', $matches[1], $matches[2]),
                ]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'reservation_date.after_or_equal' => trans('plugins/restaurant::reservation.date_must_be_today_or_future'),
            'number_of_guests.max' => trans('plugins/restaurant::reservation.max_guests_exceeded'),
        ];
    }
}
