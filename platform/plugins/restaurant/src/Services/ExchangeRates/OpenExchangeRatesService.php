<?php

namespace Botble\Restaurant\Services\ExchangeRates;

use Botble\Restaurant\Facades\Currency;
use Botble\Restaurant\Models\Currency as CurrencyModel;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OpenExchangeRatesService implements ExchangeRateInterface
{
    public function getCurrentExchangeRate(): Collection
    {
        if (! get_restaurant_setting('open_exchange_app_id')) {
            throw new Exception(trans('plugins/restaurant::currency.no_api_key'));
        }

        $rates = $this->cacheExchangeRates();

        $defaultCurrency = Currency::getDefaultCurrency();

        if ($defaultCurrency->exchange_rate != 1) {
            $defaultCurrency->update(['exchange_rate' => 1]);
        }

        $currencies = CurrencyModel::query()
            ->where('is_default', 0)
            ->get();

        foreach ($currencies as $currency) {
            if (! isset($rates[strtoupper($currency->title)])) {
                continue;
            }

            $currency->update(['exchange_rate' => number_format($rates[strtoupper($currency->title)], 8, '.', '')]);
        }

        return CurrencyModel::query()->get();
    }

    public function cacheExchangeRates(): array
    {
        $defaultCurrency = Currency::getDefaultCurrency();
        $currencies = Currency::currencies()->pluck('title')->all();

        $params = [
            'app_id' => get_restaurant_setting('open_exchange_app_id'),
            'base' => strtoupper($defaultCurrency->title),
        ];

        $rates = Cache::remember('restaurant_currency_exchange_rate', 86_400, function () use ($params, $currencies) {
            $data = $this->request($params);

            $result = [];
            if (isset($data['rates'])) {
                foreach ($currencies as $currency) {
                    if (isset($data['rates'][strtoupper($currency)])) {
                        $result[strtoupper($currency)] = $data['rates'][strtoupper($currency)];
                    }
                }
            }

            return $result;
        });

        $rates[$defaultCurrency->title] = 1;

        return $rates;
    }

    protected function request(array $params): array
    {
        $response = Http::baseUrl('https://openexchangerates.org')
            ->withoutVerifying()
            ->acceptJson()
            ->get('api/latest.json?' . http_build_query($params));

        if ($response->failed()) {
            throw new Exception($response->json('message') ?: $response->reason());
        }

        return $response->json();
    }
}
