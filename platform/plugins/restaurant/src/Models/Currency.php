<?php

namespace Botble\Restaurant\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Support\Facades\Cache;

class Currency extends BaseModel
{
    protected $table = 'restaurant_currencies';

    protected $fillable = [
        'title',
        'symbol',
        'is_prefix_symbol',
        'order',
        'decimals',
        'is_default',
        'exchange_rate',
    ];

    protected $casts = [
        'is_prefix_symbol' => 'boolean',
        'is_default' => 'boolean',
        'exchange_rate' => 'double',
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget('currencies');
        });

        static::deleted(function () {
            Cache::forget('currencies');
        });
    }
}
