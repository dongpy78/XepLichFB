<?php

namespace Botble\Restaurant\Facades;

use Botble\Restaurant\Supports\CurrencySupport;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void setApplicationCurrency(\Botble\Restaurant\Models\Currency $currency)
 * @method static \Botble\Restaurant\Models\Currency|null getApplicationCurrency()
 * @method static \Botble\Restaurant\Models\Currency|null getDefaultCurrency()
 * @method static \Illuminate\Support\Collection currencies()
 * @method static string|null detectedCurrencyCode()
 * @method static array countryCurrencies()
 * @method static array currencyCodes()
 * @method static \Botble\Restaurant\Models\Currency setCurrencyExchangeRate(\Botble\Restaurant\Models\Currency $currency)
 * @method static \Botble\Restaurant\Services\ExchangeRates\ExchangeRateInterface getExchangeRateService()
 * @method static float convertFromDefaultCurrency(float $amount, \Botble\Restaurant\Models\Currency $currency)
 * @method static float convertToDefaultCurrency(float $amount, \Botble\Restaurant\Models\Currency $currency)
 * @method static string formatPrice(float $amount, ?\Botble\Restaurant\Models\Currency $currency = null, bool $withCurrencySymbol = true, bool $useDefaultCurrency = false)
 *
 * @see \Botble\Restaurant\Supports\CurrencySupport
 */
class Currency extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CurrencySupport::class;
    }
}
