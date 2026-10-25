<?php

namespace Botble\RestaurantRoster\Providers;

use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Supports\ServiceProvider;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\RestaurantRoster\Commands\BootstrapSchedulingCommand;
use Botble\RestaurantRoster\Commands\LearnFromScheduleCommand;
use Botble\RestaurantRoster\Services\RosterConflictChecker;
use Illuminate\Routing\Events\RouteMatched;

class RestaurantRosterServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register(): void
    {
        $this->app->singleton(RosterConflictChecker::class);
    }

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/restaurant-roster')
            ->loadAndPublishConfigurations(['general', 'permissions'])
            ->loadAndPublishViews()
            ->loadRoutes()
            ->loadMigrations()
            ->publishAssets();

        // Logo / favicon mặc định của phần mềm (thay thương hiệu cũ VUTA CMS); quản lý đặt logo riêng ở Giao diện quản trị thì dùng logo đó.
        config([
            'core.base.general.logo' => '/vendor/core/plugins/restaurant-roster/images/logo.svg',
            'core.base.general.favicon' => '/vendor/core/plugins/restaurant-roster/images/favicon.svg',
        ]);

        // Dòng copyright trang quản trị / đăng nhập kèm tên người phát triển (config author).
        $this->app->booted(function (): void {
            $author = trim((string) config('plugins.restaurant-roster.general.author'));
            $translator = $this->app['translator'];

            if ($author === '') {
                return;
            }

            foreach (array_unique([$translator->getLocale(), $translator->getFallback(), 'en', 'vi']) as $locale) {
                $translator->load('core/base', 'layouts', $locale); // nạp đủ file gốc trước, chỉ ghi đè một dòng
                $translator->addLines(['layouts.copyright' => 'Copyright :year © :company · Phát triển bởi ' . e($author) . '. Version :version'], $locale, 'core/base');
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([BootstrapSchedulingCommand::class, LearnFromScheduleCommand::class]);
        }

        $this->app['events']->listen(RouteMatched::class, function (): void {
            DashboardMenu::make()
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster',
                    'priority' => 1,
                    'name' => 'Xếp lịch nhà hàng',
                    'icon' => 'ti ti-calendar-time',
                    'permissions' => ['roster.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-grid',
                    'priority' => 1,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Bảng xếp ca',
                    'icon' => 'ti ti-layout-grid',
                    'route' => 'roster.index',
                    'permissions' => ['roster.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-statistics',
                    'priority' => 1,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Thống kê nhân sự',
                    'icon' => 'ti ti-chart-bar',
                    'route' => 'roster.statistics',
                    'permissions' => ['roster.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-employees',
                    'priority' => 2,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Nhân viên',
                    'icon' => 'ti ti-users',
                    'route' => 'roster.employees.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-shift-templates',
                    'priority' => 3,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Mẫu ca',
                    'icon' => 'ti ti-clock-hour-4',
                    'route' => 'roster.shift-templates.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-positions',
                    'priority' => 4,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Vị trí',
                    'icon' => 'ti ti-id-badge-2',
                    'route' => 'roster.positions.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-night',
                    'priority' => 4,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Nhân sự Night',
                    'icon' => 'ti ti-moon',
                    'route' => 'roster.night.index',
                    'permissions' => ['roster.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-room-service',
                    'priority' => 4,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Nhân sự Room Service',
                    'icon' => 'ti ti-bell-ringing',
                    'route' => 'roster.room-service.index',
                    'permissions' => ['roster.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-schedule-groups',
                    'priority' => 5,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Nhóm hiển thị lịch',
                    'icon' => 'ti ti-layout-rows',
                    'route' => 'roster.schedule-groups.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-outlets',
                    'priority' => 5,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Nhà hàng / Outlet',
                    'icon' => 'ti ti-building-store',
                    'route' => 'roster.outlets.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-auto',
                    'priority' => 0,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Tự động xếp lịch',
                    'icon' => 'ti ti-wand',
                    'route' => 'roster.auto.index',
                    'permissions' => ['roster.auto'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-availability',
                    'priority' => 2,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'OFF của nhân viên',
                    'icon' => 'ti ti-calendar-off',
                    'route' => 'roster.availability.index',
                    'permissions' => ['roster.index'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-requirements',
                    'priority' => 6,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Định biên nhân sự',
                    'icon' => 'ti ti-users-group',
                    'route' => 'roster.requirements.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-coverage',
                    'priority' => 6,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Định biên quầy Lobby',
                    'icon' => 'ti ti-glass-cocktail',
                    'route' => 'roster.coverage.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-skills',
                    'priority' => 7,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Ngoại lệ năng lực',
                    'icon' => 'ti ti-certificate',
                    'route' => 'roster.skills.index',
                    'permissions' => ['roster.settings'],
                ])
                ->registerItem([
                    'id' => 'cms-plugins-restaurant-roster-outlet-days',
                    'priority' => 8,
                    'parent_id' => 'cms-plugins-restaurant-roster',
                    'name' => 'Ngày đặc biệt (tầng trên…)',
                    'icon' => 'ti ti-calendar-event',
                    'route' => 'roster.outlet-days.index',
                    'permissions' => ['roster.settings'],
                ]);
        });
    }
}
