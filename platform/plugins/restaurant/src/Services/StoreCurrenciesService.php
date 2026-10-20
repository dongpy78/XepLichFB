<?php

namespace Botble\Restaurant\Services;

use Botble\Restaurant\Models\Currency;
use Illuminate\Support\Facades\DB;

class StoreCurrenciesService
{
    public function execute(array $currencies, array $deletedCurrencies = []): void
    {
        DB::transaction(function () use ($currencies, $deletedCurrencies): void {
            // Handle deleted currencies
            if (! empty($deletedCurrencies)) {
                Currency::query()->whereIn('id', $deletedCurrencies)->delete();
            }

            // Reset all default flags
            Currency::query()->update(['is_default' => 0]);

            $hasDefault = false;

            foreach ($currencies as $item) {
                $currencyId = $item['id'] ?? 0;

                if ($currencyId) {
                    $currency = Currency::query()->find($currencyId);
                } else {
                    $currency = new Currency();
                }

                if ($currency) {
                    $currency->fill($item);

                    // Ensure only one default currency
                    if ($item['is_default'] ?? false) {
                        if (! $hasDefault) {
                            $currency->is_default = 1;
                            $hasDefault = true;
                        } else {
                            $currency->is_default = 0;
                        }
                    }

                    $currency->save();
                }
            }

            // If no default currency was set, make the first one default
            if (! $hasDefault && Currency::query()->exists()) {
                Currency::query()->first()->update(['is_default' => 1]);
            }
        });
    }
}
