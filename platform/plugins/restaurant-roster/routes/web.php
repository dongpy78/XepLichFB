<?php

use Botble\Base\Facades\AdminHelper;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Botble\RestaurantRoster\Http\Controllers'], function (): void {
    AdminHelper::registerRoutes(function (): void {
        Route::group(['prefix' => 'roster', 'as' => 'roster.'], function (): void {
            Route::get('/', [
                'as' => 'index',
                'uses' => 'RosterController@index',
                'permission' => 'roster.index',
            ]);

            Route::get('grid', [
                'as' => 'grid',
                'uses' => 'RosterController@grid',
                'permission' => 'roster.index',
            ]);

            Route::get('statistics', [
                'as' => 'statistics',
                'uses' => 'StatisticsController@index',
                'permission' => 'roster.index',
            ]);

            Route::group(['permission' => 'roster.manage'], function (): void {
                Route::post('assign', 'RosterController@assign')->name('assign');
                Route::post('lock', 'RosterController@lock')->name('lock');
                Route::post('copy-previous-week', 'RosterController@copyPreviousWeek')->name('copy-previous-week');
                Route::post('publish', 'RosterController@publish')->name('publish');
                Route::post('clear-week', 'RosterController@clearWeek')->name('clear-week');
                Route::post('clear-cells', 'RosterController@clearCells')->name('clear-cells');
                Route::post('clear-undo', 'RosterController@undoClear')->name('clear-undo');
            });

            Route::post('overtime', [
                'as' => 'overtime',
                'uses' => 'RosterController@overtime',
                'permission' => 'roster.approve',
            ]);

            // Thuật toán xếp lịch tự động.
            Route::group(['prefix' => 'auto', 'as' => 'auto.', 'permission' => 'roster.auto'], function (): void {
                Route::get('/', 'AutoScheduleController@index')->name('index');
                Route::post('run', 'AutoScheduleController@run')->name('run');
                Route::get('{id}', 'AutoScheduleController@show')->name('show')->wherePrimaryKey('id');
                Route::post('{id}/apply', 'AutoScheduleController@apply')->name('apply')->wherePrimaryKey('id');
                Route::post('{id}/discard', 'AutoScheduleController@discard')->name('discard')->wherePrimaryKey('id');
            });

            // Đơn OFF / ngày bận: quản lý tạo đơn; người có quyền duyệt mới duyệt/xóa.
            Route::group(['prefix' => 'availability', 'as' => 'availability.'], function (): void {
                Route::group(['permission' => 'roster.index'], function (): void {
                    Route::resource('', 'AvailabilityRequestController')
                        ->parameters(['' => 'availability'])
                        ->except(['show', 'destroy']);
                });

                Route::group(['permission' => 'roster.approve'], function (): void {
                    Route::delete('{availability}', 'AvailabilityRequestController@destroy')->name('destroy')->wherePrimaryKey('availability');
                    Route::post('{availability}/approve', 'AvailabilityRequestController@approve')->name('approve')->wherePrimaryKey('availability');
                    Route::post('{availability}/reject', 'AvailabilityRequestController@reject')->name('reject')->wherePrimaryKey('availability');
                });
            });

            Route::group(['permission' => 'roster.settings'], function (): void {
                Route::post('swap-waiters', 'RosterController@swapWaiters')->name('swap-waiters');

                Route::group(['prefix' => 'outlets', 'as' => 'outlets.'], function (): void {
                    Route::resource('', 'OutletController')->parameters(['' => 'outlet']);
                });

                Route::group(['prefix' => 'positions', 'as' => 'positions.'], function (): void {
                    Route::resource('', 'PositionController')->parameters(['' => 'position']);
                });

                Route::group(['prefix' => 'employees', 'as' => 'employees.'], function (): void {
                    Route::resource('', 'EmployeeController')->parameters(['' => 'employee']);
                });

                Route::group(['prefix' => 'shift-templates', 'as' => 'shift-templates.'], function (): void {
                    Route::resource('', 'ShiftTemplateController')->parameters(['' => 'shift_template']);
                });

                Route::group(['prefix' => 'skills', 'as' => 'skills.'], function (): void {
                    Route::resource('', 'EmployeeSkillController')->parameters(['' => 'skill'])->except('show');
                });

                Route::group(['prefix' => 'requirements', 'as' => 'requirements.'], function (): void {
                    Route::resource('', 'StaffingRequirementController')->parameters(['' => 'requirement'])->except('show');
                });

                Route::group(['prefix' => 'coverage', 'as' => 'coverage.'], function (): void {
                    Route::resource('', 'CoverageRequirementController')->parameters(['' => 'coverage'])->except('show');
                });

                Route::group(['prefix' => 'outlet-days', 'as' => 'outlet-days.'], function (): void {
                    Route::resource('', 'OutletDayController')->parameters(['' => 'outlet_day'])->except('show');
                });
            });
        });
    });
});
