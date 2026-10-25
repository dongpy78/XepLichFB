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

            // Gỡ OFF đã cho / xử lý ô "cần xem xét": quyền ghi / gỡ OFF.
            Route::post('off-record', ['as' => 'off-record', 'uses' => 'RosterController@offRecord', 'permission' => 'roster.approve']);
            Route::post('shift-request', ['as' => 'shift-request', 'uses' => 'RosterController@shiftRequest', 'permission' => 'roster.approve']);
            Route::post('off-remove', ['as' => 'off-remove', 'uses' => 'RosterController@offRemove', 'permission' => 'roster.approve']);
            Route::post('review', ['as' => 'review', 'uses' => 'RosterController@review', 'permission' => 'roster.approve']);

            Route::post('overtime', [
                'as' => 'overtime',
                'uses' => 'RosterController@overtime',
                'permission' => 'roster.approve',
            ]);

            // Kỳ lịch: tổng hợp OFF, xác nhận "đã nhập xong OFF" trước khi xếp tự động.
            Route::group(['prefix' => 'periods', 'as' => 'periods.'], function (): void {
                Route::get('/', ['as' => 'index', 'uses' => 'PeriodController@index', 'permission' => 'roster.index']);
                Route::get('{id}', ['as' => 'show', 'uses' => 'PeriodController@show', 'permission' => 'roster.index'])->wherePrimaryKey('id');

                Route::group(['permission' => 'roster.approve'], function (): void {
                    Route::post('/', 'PeriodController@store')->name('store');
                    Route::post('{id}/confirm-off', 'PeriodController@confirmOff')->name('confirm-off')->wherePrimaryKey('id');
                    Route::post('{id}/reopen-off', 'PeriodController@reopenOff')->name('reopen-off')->wherePrimaryKey('id');
                    Route::delete('{id}', 'PeriodController@destroy')->name('destroy')->wherePrimaryKey('id');
                });
            });

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
                // GET hiển thị trang, POST tải dữ liệu bảng (datatable) – giống route index của Botble.
                Route::match(['GET', 'POST'], '', ['as' => 'index', 'uses' => 'AvailabilityRequestController@index', 'permission' => 'roster.index']);

                // Ghi / sửa / gỡ OFF làm thay đổi ô lịch đã khóa: chỉ quản lý có quyền roster.approve.
                Route::group(['permission' => 'roster.approve'], function (): void {
                    Route::resource('', 'AvailabilityRequestController')
                        ->parameters(['' => 'availability'])
                        ->except(['index', 'show', 'destroy']);

                    Route::delete('{availability}', 'AvailabilityRequestController@destroy')->name('destroy')->wherePrimaryKey('availability');
                    Route::post('{availability}/approve', 'AvailabilityRequestController@approve')->name('approve')->wherePrimaryKey('availability');
                    Route::post('{availability}/reject', 'AvailabilityRequestController@reject')->name('reject')->wherePrimaryKey('availability');
                });
            });

            Route::get('night', ['as' => 'night.index', 'uses' => 'NightStaffController@index', 'permission' => 'roster.index']);
            Route::get('room-service', ['as' => 'room-service.index', 'uses' => 'RoomServiceStaffController@index', 'permission' => 'roster.index']);

            Route::group(['permission' => 'roster.settings'], function (): void {
                Route::post('swap-waiters', 'RosterController@swapWaiters')->name('swap-waiters');

                Route::group(['prefix' => 'schedule-groups', 'as' => 'schedule-groups.'], function (): void {
                    Route::resource('', 'ScheduleGroupController')->parameters(['' => 'group'])->except('show');
                });

                Route::post('night', 'NightStaffController@save')->name('night.save');
                Route::post('night/reset', 'NightStaffController@reset')->name('night.reset');
                Route::post('night/propose', 'NightStaffController@propose')->name('night.propose');
                Route::post('night/unapprove', 'NightStaffController@unapprove')->name('night.unapprove');
                Route::post('night/{employee}/toggle', 'NightStaffController@toggle')->name('night.toggle')->wherePrimaryKey('employee');
                Route::post('room-service', 'RoomServiceStaffController@store')->name('room-service.store');
                Route::post('room-service/{row}/end', 'RoomServiceStaffController@end')->name('room-service.end')->wherePrimaryKey('row');

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
                    Route::post('preview', 'StaffingRequirementController@preview')->name('preview');
                    Route::post('{requirement}/status', 'StaffingRequirementController@status')->name('status')->wherePrimaryKey('requirement');
                    Route::get('{requirement}/history', 'StaffingRequirementController@history')->name('history')->wherePrimaryKey('requirement');
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
