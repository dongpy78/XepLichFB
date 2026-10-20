<?php

namespace Botble\Restaurant\Providers;

use Botble\Base\Supports\ServiceProvider;
use Botble\Restaurant\Services\RestaurantService;
use Botble\Slug\Models\Slug;
use Illuminate\Database\Query\Builder;

class HookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        add_filter(BASE_FILTER_PUBLIC_SINGLE_DATA, [$this, 'handleSingleView'], 15);
    }

    public function handleSingleView(Slug|array $slug): Slug|array|Builder
    {
        return (new RestaurantService())->handleFrontRoutes($slug);
    }
}
