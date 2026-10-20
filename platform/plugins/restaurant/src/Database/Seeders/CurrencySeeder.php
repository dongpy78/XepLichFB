<?php

namespace Botble\Restaurant\Database\Seeders;

use Botble\Restaurant\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::query()->truncate();

        $currencies = [
            [
                'title' => 'USD',
                'symbol' => '$',
                'is_prefix_symbol' => 1,
                'decimals' => 2,
                'order' => 0,
                'is_default' => 1,
                'exchange_rate' => 1,
            ],
            [
                'title' => 'EUR',
                'symbol' => '€',
                'is_prefix_symbol' => 1,
                'decimals' => 2,
                'order' => 1,
                'is_default' => 0,
                'exchange_rate' => 0.85,
            ],
            [
                'title' => 'GBP',
                'symbol' => '£',
                'is_prefix_symbol' => 1,
                'decimals' => 2,
                'order' => 2,
                'is_default' => 0,
                'exchange_rate' => 0.73,
            ],
            [
                'title' => 'JPY',
                'symbol' => '¥',
                'is_prefix_symbol' => 1,
                'decimals' => 0,
                'order' => 3,
                'is_default' => 0,
                'exchange_rate' => 110.14,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->create($currency);
        }
    }
}
