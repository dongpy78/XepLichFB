<?php

use Botble\Base\Facades\AdminHelper;
use Botble\Member\Models\Member;
use Botble\Slug\Facades\SlugHelper;
use Botble\Theme\Facades\Theme;
use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => 'Botble\Member\Http\Controllers',
], function (): void {
    AdminHelper::registerRoutes(function (): void {
        Route::group(['prefix' => 'members', 'as' => 'member.'], function (): void {
            Route::resource('', 'MemberController')->parameters(['' => 'member']);

            Route::post('verify-email/{id}', [
                'as' => 'verify-email',
                'uses' => 'MemberController@verifyEmail',
                'permission' => 'member.edit',
            ])->wherePrimaryKey();
        });

        Route::group(['prefix' => 'settings', 'as' => 'member.'], function (): void {
            Route::get('members', [
                'as' => 'settings',
                'uses' => 'Settings\MemberSettingController@edit',
            ]);

            Route::put('members', [
                'as' => 'settings.update',
                'uses' => 'Settings\MemberSettingController@update',
                'permission' => 'member.settings',
            ]);
        });
    });

    if (defined('THEME_MODULE_SCREEN_NAME')) {
        Theme::registerRoutes(function (): void {
            Route::get(SlugHelper::getPrefix(Member::class, 'author') . '/{slug}')
                ->uses('PublicController@getAuthor')
                ->name('author.show');
        });
    }
});
