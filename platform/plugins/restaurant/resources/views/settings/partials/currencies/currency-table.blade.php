<div class="swatches-container">
    <div class="header">
        <div class="swatch-item">
            {{ trans('plugins/restaurant::currency.name') }}
        </div>
        <div class="swatch-item">
            {{ trans('plugins/restaurant::currency.symbol') }}
        </div>
        <div class="swatch-item swatch-decimals">
            {{ trans('plugins/restaurant::currency.number_of_decimals') }}
        </div>
        <div class="swatch-item swatch-exchange-rate">
            {{ trans('plugins/restaurant::currency.exchange_rate') }}
        </div>
        <div class="swatch-item swatch-is-prefix-symbol">
            {{ trans('plugins/restaurant::currency.is_prefix_symbol') }}
        </div>
        <div class="swatch-is-default">
            {{ trans('plugins/restaurant::currency.is_default') }}
        </div>
        <div class="remove-item">{{ trans('plugins/restaurant::currency.remove') }}</div>
    </div>

    <ul class="swatches-list"></ul>

    <div class="d-flex justify-content-between w-100 align-items-center">
        <a class="js-add-new-attribute" href="javascript:void(0)">
            {{ trans('plugins/restaurant::currency.new_currency') }}
        </a>
        <x-core::form.helper-text>
            {{ trans('plugins/restaurant::currency.instruction') }}
        </x-core::form.helper-text>
    </div>
</div>