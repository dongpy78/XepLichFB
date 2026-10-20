<?php

namespace Botble\Restaurant\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Restaurant\Facades\Currency;
use Botble\Restaurant\Forms\Settings\CurrencySettingForm;
use Botble\Restaurant\Http\Requests\Settings\CurrencySettingRequest;
use Botble\Restaurant\Services\StoreCurrenciesService;
use Exception;
use Illuminate\Support\Facades\Cache;

class CurrencyController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/restaurant::restaurant.menu_name'))
            ->add(trans('plugins/restaurant::currency.currencies'), route('restaurant.currencies'));
    }

    public function index()
    {
        $this->pageTitle(trans('plugins/restaurant::currency.currencies'));

        $form = CurrencySettingForm::create();

        return view('plugins/restaurant::settings.currency', compact('form'));
    }

    public function update(CurrencySettingRequest $request, StoreCurrenciesService $service, BaseHttpResponse $response)
    {
        $this->saveSettings($request->except([
            'currencies',
            'currencies_data',
            'deleted_currencies',
        ]));

        $currencies = json_decode($request->validated('currencies'), true) ?: [];

        if (! $currencies) {
            return $response
                ->setNextUrl(route('restaurant.currencies'))
                ->setError()
                ->setMessage(trans('plugins/restaurant::currency.require_at_least_one_currency'));
        }

        $deletedCurrencies = json_decode($request->input('deleted_currencies', []), true) ?: [];

        $service->execute($currencies, $deletedCurrencies);

        return $response
            ->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function updateCurrenciesFromExchangeApi(BaseHttpResponse $response)
    {
        try {
            $service = app(Currency::class)->getExchangeRateService();
            $service->getCurrentExchangeRate();

            return $response
                ->setMessage(trans('plugins/restaurant::currency.update_currency_rates_success'));
        } catch (Exception $e) {
            return $response
                ->setError()
                ->setMessage($e->getMessage());
        }
    }

    public function clearCacheCurrencyRates(BaseHttpResponse $response)
    {
        Cache::forget('restaurant_currency_exchange_rate');

        return $response
            ->setMessage(trans('plugins/restaurant::currency.clear_cache_rates_success'));
    }

    protected function saveSettings(array $data, string $prefix = 'restaurant_'): void
    {
        foreach ($data as $settingKey => $settingValue) {
            setting()->set($prefix . $settingKey, $settingValue);
        }

        setting()->save();
    }
}
