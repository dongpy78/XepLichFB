<?php

namespace Botble\Restaurant\Forms\Settings;

use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Restaurant\Http\Requests\Settings\RestaurantSettingRequest;
use Botble\Setting\Forms\SettingForm;

class RestaurantSettingForm extends SettingForm
{
    public function setup(): void
    {
        parent::setup();

        $this
            ->setSectionTitle(trans('plugins/restaurant::restaurant.settings.title'))
            ->setSectionDescription(trans('plugins/restaurant::restaurant.settings.description'))
            ->setValidatorClass(RestaurantSettingRequest::class)
            ->add(
                'restaurant_reservation_time_format',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/restaurant::restaurant.settings.reservation_time_format'))
                    ->choices([
                        '12' => trans('plugins/restaurant::restaurant.settings.12_hours_format'),
                        '24' => trans('plugins/restaurant::restaurant.settings.24_hours_format'),
                    ])
                    ->selected(setting('restaurant_reservation_time_format', '12'))
                    ->helperText(trans('plugins/restaurant::restaurant.settings.reservation_time_format_helper'))
            );
    }
}
