<?php

namespace Botble\Restaurant\Forms\Fronts;

use Botble\Base\Forms\FieldOptions\ButtonFieldOption;
use Botble\Base\Forms\FieldOptions\HtmlFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Restaurant\Http\Requests\PublicReservationRequest;
use Botble\Restaurant\Models\Reservation;
use Botble\Theme\FormFront;

class ReservationForm extends FormFront
{
    protected string $errorBag = 'reservation';

    public static function formTitle(): string
    {
        return trans('plugins/restaurant::reservation.reservation_form');
    }

    public function setup(): void
    {
        $this
            ->contentOnly()
            ->model(Reservation::class)
            ->setUrl(route('public.restaurant.reservation'))
            ->setValidatorClass(PublicReservationRequest::class)
            ->setFormOption('class', 'reservation-form')
            ->add(
                'form_row_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="row clearfix">')
            )
            ->add(
                'name_column_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="form-group col-lg-6 col-md-6 col-sm-12">')
            )
            ->add(
                'name_field_inner_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="field-inner">')
            )
            ->add(
                'name',
                TextField::class,
                TextFieldOption::make()
                    ->label(false)
                    ->placeholder(__('Your Name'))
                    ->required()
                    ->cssClass('')
                    ->wrapperAttributes(false)
                    ->addAttribute('style', 'margin-bottom:0;')
            )
            ->add(
                'name_field_inner_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'name_column_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'phone_column_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="form-group col-lg-6 col-md-6 col-sm-12">')
            )
            ->add(
                'phone_field_inner_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="field-inner">')
            )
            ->add(
                'phone',
                TextField::class,
                TextFieldOption::make()
                    ->label(false)
                    ->placeholder(__('Phone Number'))
                    ->required()
                    ->cssClass('')
                    ->wrapperAttributes(false)
                    ->addAttribute('style', 'margin-bottom:0;')
            )
            ->add(
                'phone_field_inner_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'phone_column_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'email',
                'hidden',
                TextFieldOption::make()
                    ->value('noemail@example.com')
                    ->wrapperAttributes(false)
            )
            ->add(
                'number_of_guests_column_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="form-group col-lg-6 col-md-6 col-sm-12">')
            )
            ->add(
                'number_of_guests_field_inner_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="field-inner"><span class="alt-icon far fa-user"></span>')
            )
            ->add(
                'number_of_guests',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(false)
                    ->choices([
                        1 => __(':number Person', ['number' => 1]),
                        2 => __(':number Person', ['number' => 2]),
                        3 => __(':number Person', ['number' => 3]),
                        4 => __(':number Person', ['number' => 4]),
                        5 => __(':number Person', ['number' => 5]),
                        6 => __(':number Person', ['number' => 6]),
                        7 => __(':number Person', ['number' => 7]),
                        8 => __(':number Person', ['number' => 8]),
                        9 => __(':number Person', ['number' => 9]),
                        10 => __(':number Person', ['number' => 10]),
                    ])
                    ->required()
                    ->cssClass('l-icon')
                    ->wrapperAttributes(false)
                    ->addAttribute('style', 'margin-bottom:0;')
            )
            ->add(
                'number_of_guests_field_inner_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('<span class="arrow-icon far fa-angle-down"></span></div>')
            )
            ->add(
                'number_of_guests_column_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'reservation_date_column_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="form-group col-lg-6 col-md-6 col-sm-12">')
            )
            ->add(
                'reservation_date_field_inner_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="field-inner"><span class="alt-icon far fa-calendar"></span>')
            )
            ->add(
                'reservation_date',
                TextField::class,
                TextFieldOption::make()
                    ->label(false)
                    ->placeholder(__('DD-MM-YYYY'))
                    ->required()
                    ->cssClass('l-icon datepicker')
                    ->wrapperAttributes(false)
                    ->addAttribute('readonly', 'readonly')
                    ->addAttribute('style', 'margin-bottom:0;')
            )
            ->add(
                'reservation_date_field_inner_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('<span class="arrow-icon far fa-angle-down"></span></div>')
            )
            ->add(
                'reservation_date_column_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'reservation_time_column_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="form-group col-lg-12 col-md-12 col-sm-12">')
            )
            ->add(
                'reservation_time_field_inner_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="field-inner"><span class="alt-icon far fa-clock"></span>')
            )
            ->add(
                'reservation_time',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(false)
                    ->choices($this->getTimeChoices(setting('restaurant_reservation_time_format', '12')))
                    ->required()
                    ->cssClass('l-icon')
                    ->wrapperAttributes(false)
                    ->addAttribute('style', 'margin-bottom:0;')
            )
            ->add(
                'reservation_time_field_inner_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('<span class="arrow-icon far fa-angle-down"></span></div>')
            )
            ->add(
                'reservation_time_column_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'special_requests_column_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="form-group col-lg-12 col-md-12 col-sm-12">')
            )
            ->add(
                'special_requests_field_inner_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="field-inner">')
            )
            ->add(
                'special_requests',
                TextareaField::class,
                TextareaFieldOption::make()
                    ->label(false)
                    ->placeholder(__('Message'))
                    ->cssClass('')
                    ->wrapperAttributes(false)
                    ->addAttribute('style', 'margin-bottom:0;')
            )
            ->add(
                'special_requests_field_inner_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'special_requests_column_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'submit_column_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="form-group col-lg-12 col-md-12 col-sm-12">')
            )
            ->add(
                'submit_field_inner_start',
                HtmlField::class,
                HtmlFieldOption::make()->content('<div class="field-inner">')
            )
            ->add(
                'submit',
                'submit',
                ButtonFieldOption::make()
                    ->label('<span class="btn-wrap"><span class="text-one">' . __('book a table') . '</span><span class="text-two">' . __('book a table') . '</span></span>')
                    ->cssClass('theme-btn btn-style-one clearfix')
                    ->wrapperAttributes(false)
            )
            ->add(
                'submit_field_inner_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'submit_column_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            )
            ->add(
                'form_row_end',
                HtmlField::class,
                HtmlFieldOption::make()->content('</div>')
            );
    }

    protected function getTimeChoices(string $format): array
    {
        if ($format === '24') {
            return [
                '08:00' => '08:00',
                '09:00' => '09:00',
                '10:00' => '10:00',
                '11:00' => '11:00',
                '12:00' => '12:00',
                '13:00' => '13:00',
                '14:00' => '14:00',
                '15:00' => '15:00',
                '16:00' => '16:00',
                '17:00' => '17:00',
                '18:00' => '18:00',
                '19:00' => '19:00',
                '20:00' => '20:00',
                '21:00' => '21:00',
                '22:00' => '22:00',
            ];
        }

        return [
            '08:00' => '8:00 AM',
            '09:00' => '9:00 AM',
            '10:00' => '10:00 AM',
            '11:00' => '11:00 AM',
            '12:00' => '12:00 PM',
            '13:00' => '1:00 PM',
            '14:00' => '2:00 PM',
            '15:00' => '3:00 PM',
            '16:00' => '4:00 PM',
            '17:00' => '5:00 PM',
            '18:00' => '6:00 PM',
            '19:00' => '7:00 PM',
            '20:00' => '8:00 PM',
            '21:00' => '9:00 PM',
            '22:00' => '10:00 PM',
        ];
    }
}
