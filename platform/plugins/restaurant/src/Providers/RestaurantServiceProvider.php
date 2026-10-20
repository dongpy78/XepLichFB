<?php

namespace Botble\Restaurant\Providers;

use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Facades\EmailHandler;
use Botble\Base\Supports\ServiceProvider;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\LanguageAdvanced\Supports\LanguageAdvancedManager;
use Botble\Restaurant\Facades\Currency;
use Botble\Restaurant\Models\Chef;
use Botble\Restaurant\Models\MenuCategory;
use Botble\Restaurant\Models\MenuItem;
use Botble\Restaurant\Supports\CurrencySupport;
use Botble\Slug\Facades\SlugHelper;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Events\RouteMatched;

class RestaurantServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register(): void
    {
        $this->app->singleton(CurrencySupport::class, CurrencySupport::class);

        AliasLoader::getInstance()->alias('RestaurantCurrency', Currency::class);
    }

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/restaurant')
            ->loadHelpers()
            ->loadAndPublishConfigurations(['permissions', 'email'])
            ->loadAndPublishTranslations()
            ->loadAndPublishViews()
            ->loadRoutes()
            ->loadMigrations()
            ->publishAssets();

        $this->app['events']->listen(RouteMatched::class, function (): void {
            DashboardMenu::make()
                ->registerItem([
                    'id' => 'cms-plugins-restaurant',
                    'priority' => 5,
                    'parent_id' => null,
                    'name' => 'plugins/restaurant::restaurant.menu_name',
                    'icon' => 'ti ti-chef-hat',
                    'permissions' => ['restaurant.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-menu-categories',
                    'priority' => 1,
                    'parent_id' => 'cms-plugins-restaurant',
                    'name' => 'plugins/restaurant::menu.menu_categories',
                    'icon' => 'ti ti-category',
                    'route' => 'restaurant.menu-categories.index',
                    'permissions' => ['restaurant.menu-categories.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-menu-items',
                    'priority' => 2,
                    'parent_id' => 'cms-plugins-restaurant',
                    'name' => 'plugins/restaurant::menu.menu_items',
                    'icon' => 'ti ti-clipboard-list',
                    'route' => 'restaurant.menu-items.index',
                    'permissions' => ['restaurant.menu-items.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-reservations',
                    'priority' => 3,
                    'parent_id' => 'cms-plugins-restaurant',
                    'name' => 'plugins/restaurant::reservation.reservations',
                    'icon' => 'ti ti-calendar-event',
                    'route' => 'restaurant.reservations.index',
                    'permissions' => ['restaurant.reservations.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-booking-calendar',
                    'priority' => 4,
                    'parent_id' => 'cms-plugins-restaurant',
                    'name' => 'plugins/restaurant::restaurant.booking_calendar',
                    'icon' => 'ti ti-calendar',
                    'route' => 'restaurant.booking-calendar.index',
                    'permissions' => ['restaurant.reservations.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-chefs',
                    'priority' => 5,
                    'parent_id' => 'cms-plugins-restaurant',
                    'name' => 'plugins/restaurant::chef.name',
                    'icon' => 'ti ti-user-star',
                    'route' => 'restaurant.chefs.index',
                    'permissions' => ['restaurant.chefs.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-currencies',
                    'priority' => 6,
                    'parent_id' => 'cms-plugins-restaurant',
                    'name' => 'plugins/restaurant::currency.currencies',
                    'icon' => 'ti ti-currency-dollar',
                    'route' => 'restaurant.currencies',
                    'permissions' => ['restaurant.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-settings',
                    'priority' => 999,
                    'parent_id' => 'cms-plugins-restaurant',
                    'name' => 'plugins/restaurant::restaurant.settings.menu_title',
                    'icon' => 'ti ti-settings',
                    'route' => 'restaurant.settings',
                    'permissions' => ['restaurant.settings'],
                ]);
        });

        SlugHelper::registering(function (): void {
            SlugHelper::registerModule(MenuCategory::class, fn () => trans('plugins/restaurant::menu.menu_categories'));
            SlugHelper::registerModule(MenuItem::class, fn () => trans('plugins/restaurant::menu.menu_items'));

            SlugHelper::setPrefix(MenuCategory::class, 'menu-categories', true);
            SlugHelper::setPrefix(MenuItem::class, 'menu', true);
        });

        if (defined('LANGUAGE_ADVANCED_MODULE_SCREEN_NAME')) {
            LanguageAdvancedManager::registerModule(MenuCategory::class, [
                'name',
                'description',
            ]);

            LanguageAdvancedManager::registerModule(MenuItem::class, [
                'name',
                'description',
                'ingredients',
            ]);

            LanguageAdvancedManager::registerModule(Chef::class, [
                'name',
                'title',
                'bio',
            ]);
        }

        EmailHandler::addTemplateSettings(RESTAURANT_MODULE_SCREEN_NAME, config('plugins.restaurant.email', []));

        $this->app->booted(function (): void {
            $this->app->register(EventServiceProvider::class);
            $this->app->register(HookServiceProvider::class);
        });
    }
}
