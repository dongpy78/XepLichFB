<?php

use Botble\Base\Facades\AdminHelper;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Botble\CustomField\Http\Controllers'], function (): void {
    AdminHelper::registerRoutes(function (): void {
        Route::group(['prefix' => 'custom-fields', 'as' => 'custom-fields.'], function (): void {
            Route::resource('', 'CustomFieldController')->parameters(['' => 'custom-field']);

            Route::get('export/{id?}', [
                'as' => 'export',
                'uses' => 'CustomFieldController@getExport',
                'permission' => 'custom-fields.index',
            ]);

            Route::post('import', [
                'as' => 'import',
                'uses' => 'CustomFieldController@postImport',
                'permission' => 'custom-fields.index',
            ]);
        });
    });
});
