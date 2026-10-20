<?php

use Botble\Base\Facades\AdminHelper;
use Botble\Theme\Facades\Theme;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Botble\Restaurant\Http\Controllers'], function (): void {
    AdminHelper::registerRoutes(function (): void {
        Route::group(['prefix' => 'restaurant', 'as' => 'restaurant.'], function (): void {
            Route::group(['prefix' => 'menu-categories', 'as' => 'menu-categories.'], function (): void {
                Route::resource('', 'MenuCategoryController')->parameters(['' => 'menu_category']);
            });

            Route::group(['prefix' => 'menu-items', 'as' => 'menu-items.'], function (): void {
                Route::resource('', 'MenuItemController')->parameters(['' => 'menu_item']);
            });

            Route::group(['prefix' => 'reservations', 'as' => 'reservations.'], function (): void {
                Route::resource('', 'ReservationController')->parameters(['' => 'reservation']);

                Route::get('/{reservation}', 'ReservationController@show')->name('show')->wherePrimaryKey();
            });

            Route::group(['prefix' => 'chefs', 'as' => 'chefs.'], function (): void {
                Route::resource('', 'ChefController')->parameters(['' => 'chef']);
            });

            Route::get('booking-calendar', [
                'as' => 'booking-calendar.index',
                'uses' => 'BookingCalendarController@index',
                'permission' => 'restaurant.reservations.index',
            ]);

            Route::get('booking-calendar/records', [
                'as' => 'booking-calendar.records',
                'uses' => 'BookingCalendarController@getBookingRecords',
                'permission' => 'restaurant.reservations.index',
            ]);

            Route::get('booking-calendar/detail/{id}', [
                'as' => 'booking-calendar.detail',
                'uses' => 'BookingCalendarController@getBookingDetail',
                'permission' => 'restaurant.reservations.index',
            ])->wherePrimaryKey('id');

            Route::post('booking-calendar/store-reservation', [
                'as' => 'booking-calendar.store-reservation',
                'uses' => 'BookingCalendarController@storeReservation',
                'permission' => 'restaurant.reservations.create',
            ]);

            Route::get('currencies', 'CurrencyController@index')->name('currencies');
            Route::put('currencies', 'CurrencyController@update')->name('currencies.update');
            Route::post('currencies/update-from-exchange-api', 'CurrencyController@updateCurrenciesFromExchangeApi')->name('currencies.update-from-exchange-api');
            Route::post('currencies/clear-cache-rates', 'CurrencyController@clearCacheCurrencyRates')->name('currencies.clear-cache-rates');
        });

        Route::group(['prefix' => 'settings/restaurant', 'as' => 'restaurant.settings', 'permission' => 'restaurant.settings'], function (): void {
            Route::get('/', [
                'uses' => 'Settings\RestaurantSettingController@edit',
            ]);

            Route::put('/', [
                'as' => '.update',
                'uses' => 'Settings\RestaurantSettingController@update',
            ]);
        });
    });

    Theme::registerRoutes(function (): void {
        Route::post('restaurant/reservation', [
            'as' => 'public.restaurant.reservation',
            'uses' => 'PublicController@postReservation',
        ]);

        Route::get('currency/switch/{code?}', [
            'as' => 'public.restaurant.currency.switch',
            'uses' => 'PublicController@switchCurrency',
        ]);
    });
});
