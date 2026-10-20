<?php

use Botble\Restaurant\Facades\Currency;
use Botble\Restaurant\Models\Currency as CurrencyModel;
use Illuminate\Support\Collection;

if (! function_exists('get_restaurant_setting')) {
    function get_restaurant_setting(string $key, mixed $default = null): mixed
    {
        return setting('restaurant_' . $key, $default);
    }
}

if (! function_exists('format_price')) {
    function format_price($price, mixed $currency = null, bool $withoutCurrency = false): string
    {
        if (is_null($price) || $price === '') {
            $price = 0;
        }

        $price = (float) $price;

        // If currency is a string (old behavior), handle it
        if (is_string($currency)) {
            return $currency . number_format($price, 2);
        }

        // New behavior with Currency model
        if (! $currency instanceof CurrencyModel) {
            $currency = Currency::getApplicationCurrency();
        }

        if (! $currency) {
            return '$' . number_format($price, 2);
        }

        $price = $price * $currency->exchange_rate;

        // Get separator settings
        $decimalSeparator = get_restaurant_setting('decimal_separator', '.');
        if ($decimalSeparator == 'space') {
            $decimalSeparator = ' ';
        }

        $thousandsSeparator = get_restaurant_setting('thousands_separator', ',');
        if ($thousandsSeparator == 'space') {
            $thousandsSeparator = ' ';
        }

        $formatted = number_format(
            $price,
            $currency->decimals,
            $decimalSeparator,
            $thousandsSeparator
        );

        if ($withoutCurrency) {
            return $formatted;
        }

        // Check if space should be added between price and currency
        $addSpace = get_restaurant_setting('add_space_between_price_and_currency', 0) == 1;
        $space = $addSpace ? ' ' : '';

        if ($currency->is_prefix_symbol) {
            return $currency->symbol . $space . $formatted;
        }

        return $formatted . $space . $currency->symbol;
    }
}

if (! function_exists('get_all_currencies')) {
    function get_all_currencies(): Collection
    {
        return Currency::currencies();
    }
}

if (! function_exists('get_default_currency')) {
    function get_default_currency(): ?CurrencyModel
    {
        return Currency::getDefaultCurrency();
    }
}

if (! function_exists('get_application_currency')) {
    function get_application_currency(): ?CurrencyModel
    {
        return Currency::getApplicationCurrency();
    }
}
