<?php

namespace Botble\Restaurant\Forms;

use Botble\Base\Forms\FieldOptions\DatePickerFieldOption;
use Botble\Base\Forms\FieldOptions\EmailFieldOption;
use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\DatePickerField;
use Botble\Base\Forms\Fields\EmailField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\Fields\TimeField;
use Botble\Base\Forms\FormAbstract;
use Botble\Restaurant\Enums\ReservationStatusEnum;
use Botble\Restaurant\Http\Requests\ReservationRequest;
use Botble\Restaurant\Models\Reservation;
use Carbon\Carbon;

class ReservationForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->model(Reservation::class)
            ->setValidatorClass(ReservationRequest::class)
            ->add('name', TextField::class, NameFieldOption::make()->required())
            ->add('email', EmailField::class, EmailFieldOption::make()->required())
            ->add(
                'phone',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/restaurant::reservation.phone'))
                    ->placeholder(trans('plugins/restaurant::reservation.phone_placeholder'))
            )
            ->add(
                'reservation_date',
                DatePickerField::class,
                DatePickerFieldOption::make()
                    ->label(trans('plugins/restaurant::reservation.reservation_date'))
                    ->required()
                    ->addAttribute('data-options', json_encode([
                        'minDate' => Carbon::now()->format('Y-m-d'),
                        'dateFormat' => 'Y-m-d',
                    ]))
            )
            ->add(
                'reservation_time',
                TimeField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/restaurant::reservation.reservation_time'))
                    ->required()
                    ->placeholder('HH:MM')
            )
            ->add(
                'number_of_guests',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/restaurant::reservation.number_of_guests'))
                    ->required()
                    ->min(1)
                    ->max(20)
                    ->placeholder('2')
            )
            ->add(
                'special_requests',
                TextareaField::class,
                TextareaFieldOption::make()
                    ->label(trans('plugins/restaurant::reservation.special_requests'))
                    ->placeholder(trans('plugins/restaurant::reservation.special_requests_placeholder'))
                    ->rows(3)
            )
            ->add(
                'status',
                SelectField::class,
                StatusFieldOption::make()
                    ->choices(ReservationStatusEnum::labels())
            )
            ->add(
                'admin_notes',
                TextareaField::class,
                TextareaFieldOption::make()
                    ->label(trans('plugins/restaurant::reservation.admin_notes'))
                    ->placeholder(trans('plugins/restaurant::reservation.admin_notes_placeholder'))
                    ->rows(3)
            )
            ->setBreakFieldPoint('status');
    }
}
