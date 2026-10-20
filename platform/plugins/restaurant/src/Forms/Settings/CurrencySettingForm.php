<?php

namespace Botble\Restaurant\Forms\Settings;

use Botble\Base\Facades\Assets;
use Botble\Base\Forms\FieldOptions\AlertFieldOption;
use Botble\Base\Forms\Fields\AlertField;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Restaurant\Http\Requests\Settings\CurrencySettingRequest;
use Botble\Restaurant\Models\Currency;
use Botble\Setting\Forms\SettingForm;

class CurrencySettingForm extends SettingForm
{
    public function setup(): void
    {
        parent::setup();

        Assets::addScripts(['jquery-ui'])
            ->addScriptsDirectly('vendor/core/plugins/restaurant/js/currencies.js')
            ->addStylesDirectly('vendor/core/plugins/restaurant/css/currencies.css');

        $currencies = Currency::query()
            ->oldest('order')
            ->get();

        $this
            ->setSectionTitle(trans('plugins/restaurant::currency.currencies'))
            ->setSectionDescription(trans('plugins/restaurant::currency.setting_description'))
            ->setFormOptions([
                'class' => 'currency-setting-form',
            ])
            ->contentOnly()
            ->setValidatorClass(CurrencySettingRequest::class)
            ->add('enable_auto_detect_visitor_currency', OnOffCheckboxField::class, [
                'label' => trans('plugins/restaurant::currency.enable_auto_detect_visitor_currency'),
                'value' => get_restaurant_setting('enable_auto_detect_visitor_currency', false),
                'help_block' => [
                    'text' => trans(
                        'plugins/restaurant::currency.auto_detect_visitor_currency_description'
                    ),
                ],
            ])
            ->add('add_space_between_price_and_currency', OnOffCheckboxField::class, [
                'label' => trans('plugins/restaurant::currency.add_space_between_price_and_currency'),
                'value' => get_restaurant_setting('add_space_between_price_and_currency', false),
            ])
            ->add('thousands_separator', SelectField::class, [
                'label' => trans('plugins/restaurant::currency.thousands_separator'),
                'selected' => get_restaurant_setting('thousands_separator', ','),
                'choices' => [
                    ',' => trans('plugins/restaurant::currency.separator_comma'),
                    '.' => trans('plugins/restaurant::currency.separator_period'),
                    'space' => trans('plugins/restaurant::currency.separator_space'),
                ],
            ])
            ->add('decimal_separator', SelectField::class, [
                'label' => trans('plugins/restaurant::currency.decimal_separator'),
                'selected' => get_restaurant_setting('decimal_separator', '.'),
                'choices' => [
                    ',' => trans('plugins/restaurant::currency.separator_comma'),
                    '.' => trans('plugins/restaurant::currency.separator_period'),
                    'space' => trans('plugins/restaurant::currency.separator_space'),
                ],
            ])
            ->add('api_provider_field', HtmlField::class, [
                'html' => view('plugins/restaurant::settings.partials.currencies.api-provider-fields'),
            ])
            ->add('use_exchange_rate_from_api', 'onOffCheckbox', [
                'label' => trans('plugins/restaurant::currency.use_exchange_rate_from_api'),
                'value' => get_restaurant_setting('use_exchange_rate_from_api', false),
            ])
            ->add('data_currencies', HtmlField::class, [
                'html' => view(
                    'plugins/restaurant::settings.partials.currencies.data-currency-fields',
                    compact('currencies')
                ),
            ])
            ->add('currency_table', HtmlField::class, [
                'html' => view('plugins/restaurant::settings.partials.currencies.currency-table'),
            ])
            ->add(
                'default_currency_warning',
                AlertField::class,
                AlertFieldOption::make()->type('warning')->content(
                    trans('plugins/restaurant::currency.default_currency_warning')
                )
            );
    }
}
