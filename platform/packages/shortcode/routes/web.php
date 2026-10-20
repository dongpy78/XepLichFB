<?php

use Botble\Base\Facades\AdminHelper;
use Botble\Base\Http\Middleware\RequiresJsonRequestMiddleware;
use Botble\Shortcode\Http\Controllers\ShortcodeController;
use Botble\Theme\Facades\Theme;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Botble\Shortcode\Http\Controllers'], function (): void {
    AdminHelper::registerRoutes(function (): void {
        Route::group(['prefix' => 'short-codes'], function (): void {
            Route::post('ajax-get-admin-config/{key}', [
                'as' => 'short-codes.ajax-get-admin-config',
                'uses' => 'ShortcodeController@ajaxGetAdminConfig',
                'permission' => false,
            ]);
        });
    });
});

app()->booted(function (): void {
    Route::middleware(RequiresJsonRequestMiddleware::class)->group(function (): void {
        Theme::registerRoutes(function (): void {
            Route::post('ajax/render-ui-blocks', [ShortcodeController::class, 'ajaxRenderUiBlock'])
                ->name('public.ajax.render-ui-block');
        });
    });
});
