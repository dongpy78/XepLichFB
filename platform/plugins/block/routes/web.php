<?php

use Botble\Base\Facades\AdminHelper;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Botble\Block\Http\Controllers'], function (): void {
    AdminHelper::registerRoutes(function (): void {
        Route::group(['prefix' => 'blocks', 'as' => 'block.'], function (): void {
            Route::resource('', 'BlockController')->parameters(['' => 'block']);
        });
    });
});
