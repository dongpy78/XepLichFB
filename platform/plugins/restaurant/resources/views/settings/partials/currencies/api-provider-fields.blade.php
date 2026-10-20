<div class="row">
    <x-core-setting::radio
        class="switch_api_provider"
        name="exchange_rate_api_provider"
        :label="trans('plugins/restaurant::currency.exchange_rate_api_provider')"
        :options="[
            'none' => trans('plugins/restaurant::currency.exchange_rate_none'),
            'api_layer' => trans('plugins/restaurant::currency.exchange_rate_api_layer'),
            'open_exchange_rate' => trans(
                'plugins/restaurant::currency.exchange_rate_open_exchange_rate',
            ),
        ]"
        :value="get_restaurant_setting('exchange_rate_api_provider', 'none')"
        data-bb-toggle="collapse"
        data-bb-target=".api-provider-settings"
    />
</div>

<x-core::form.fieldset
    class="api-provider-settings"
    data-bb-value="api_layer"
    @style(['display: none' => get_restaurant_setting('exchange_rate_api_provider') !== 'api_layer'])
>
    <x-core::form.text-input
        name="api_layer_api_key"
        :label="trans('plugins/restaurant::currency.api_key')"
        :value="get_restaurant_setting('api_layer_api_key')"
        placeholder="********"
        :helperText="trans('plugins/restaurant::currency.api_key_helper', [
            'link' => Html::link(
                'https://apilayer.com/marketplace/exchangerates_data-api',
                attributes: ['target' => '_blank'],
            ),
        ])"
    />

    @if (
        get_restaurant_setting('exchange_rate_api_provider') === 'api_layer'
        && get_restaurant_setting('api_layer_api_key')
    )

        <div class="btn-list">
            <x-core::button
                type="button"
                id="btn-update-currencies"
                data-url="{{ route('restaurant.currencies.update-from-exchange-api') }}"
            >
                <x-core::icon name="ti ti-download" />
                {{ trans('plugins/restaurant::currency.update_currency_rates') }}
            </x-core::button>

            <x-core::button
                type="button"
                id="btn-clear-cache-rates"
                data-url="{{ route('restaurant.currencies.clear-cache-rates') }}"
            >
                <x-core::icon name="ti ti-refresh" />
                {{ trans('plugins/restaurant::currency.clear_cache_rates') }}
            </x-core::button>
        </div>
    @endif
</x-core::form.fieldset>

<x-core::form.fieldset
    class="api-provider-settings"
    data-bb-value="open_exchange_rate"
    @style(['display: none' => get_restaurant_setting('exchange_rate_api_provider', 'none') !== 'open_exchange_rate'])
>
    <x-core::form.text-input
        name="open_exchange_app_id"
        :label="trans('plugins/restaurant::currency.open_exchange_app_id')"
        :value="get_restaurant_setting('open_exchange_app_id')"
        placeholder="********"
        :helperText="trans('plugins/restaurant::currency.api_key_helper', [
            'link' => Html::link('https://openexchangerates.org/', attributes: ['target' => '_blank']),
        ])"
    />

    @if (
        get_restaurant_setting('exchange_rate_api_provider', 'none') == 'open_exchange_rate'
        && get_restaurant_setting('open_exchange_app_id')
    )

        <div class="btn-list">
            <x-core::button
                type="button"
                id="btn-update-currencies"
                data-url="{{ route('restaurant.currencies.update-from-exchange-api') }}"
            >
                <x-core::icon name="ti ti-download" />
                {{ trans('plugins/restaurant::currency.update_currency_rates') }}
            </x-core::button>

            <x-core::button
                type="button"
                id="btn-clear-cache-rates"
                data-url="{{ route('restaurant.currencies.clear-cache-rates') }}"
            >
                <x-core::icon name="ti ti-refresh" />
                {{ trans('plugins/restaurant::currency.clear_cache_rates') }}
            </x-core::button>
        </div>
    @endif
</x-core::form.fieldset>