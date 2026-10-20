<?php

namespace Botble\Restaurant\Services;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Facades\AdminHelper;
use Botble\Base\Supports\Helper;
use Botble\Media\Facades\RvMedia;
use Botble\Restaurant\Models\MenuCategory;
use Botble\Restaurant\Models\MenuItem;
use Botble\SeoHelper\Facades\SeoHelper;
use Botble\SeoHelper\SeoOpenGraph;
use Botble\Slug\Models\Slug;
use Botble\Theme\Facades\AdminBar;
use Botble\Theme\Facades\Theme;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;

class RestaurantService
{
    public function handleFrontRoutes(Slug|array $slug): Slug|array|Builder
    {
        if (! $slug instanceof Slug) {
            return $slug;
        }

        $condition = [
            'id' => $slug->reference_id,
            'status' => BaseStatusEnum::PUBLISHED,
        ];

        if (AdminHelper::isPreviewing()) {
            Arr::forget($condition, 'status');
        }

        switch ($slug->reference_type) {
            case MenuItem::class:
                /**
                 * @var MenuItem $menuItem
                 */
                $menuItem = MenuItem::query()
                    ->where($condition)
                    ->with(['category', 'slugable'])
                    ->firstOrFail();

                Helper::handleViewCount($menuItem, 'viewed_menu_item');

                SeoHelper::setTitle($menuItem->name)
                    ->setDescription($menuItem->description);

                $meta = new SeoOpenGraph();
                if ($menuItem->image) {
                    $meta->setImage(RvMedia::getImageUrl($menuItem->image));
                }
                $meta->setDescription($menuItem->description);
                $meta->setUrl($menuItem->url);
                $meta->setTitle($menuItem->name);
                $meta->setType('article');

                SeoHelper::setSeoOpenGraph($meta);

                SeoHelper::meta()->setUrl($menuItem->url);

                if (function_exists('admin_bar')) {
                    AdminBar::registerLink(
                        trans('plugins/restaurant::menu.edit_this_menu_item'),
                        route('restaurant.menu-items.edit', $menuItem->getKey()),
                        null,
                        'restaurant.menu-items.edit'
                    );
                }

                if (function_exists('shortcode')) {
                    shortcode()->getCompiler()->setEditLink(route('restaurant.menu-items.edit', $menuItem->getKey()), 'restaurant.menu-items.edit');
                }

                if ($menuItem->category) {
                    Theme::breadcrumb()->add($menuItem->category->name, $menuItem->category->url);
                }

                Theme::breadcrumb()->add($menuItem->name, $menuItem->url);

                do_action(BASE_ACTION_PUBLIC_RENDER_SINGLE, MENU_ITEM_MODULE_SCREEN_NAME, $menuItem);

                return [
                    'view' => 'restaurant.menu-item',
                    'default_view' => 'plugins/restaurant::themes.menu-item',
                    'data' => compact('menuItem'),
                    'slug' => $menuItem->slug,
                ];

            case MenuCategory::class:
                /**
                 * @var MenuCategory $category
                 */
                $category = MenuCategory::query()
                    ->where($condition)
                    ->with(['slugable', 'menuItems' => function ($query): void {
                        $query->where('status', BaseStatusEnum::PUBLISHED)
                            ->with('slugable')
                            ->orderBy('order')
                            ->orderBy('name');
                    }])
                    ->firstOrFail();

                $category->menuItems->loadMissing('category');

                SeoHelper::setTitle($category->name)
                    ->setDescription($category->description);

                $meta = new SeoOpenGraph();
                if ($category->image) {
                    $meta->setImage(RvMedia::getImageUrl($category->image));
                }
                $meta->setDescription($category->description);
                $meta->setUrl($category->url);
                $meta->setTitle($category->name);
                $meta->setType('article');

                SeoHelper::setSeoOpenGraph($meta);

                SeoHelper::meta()->setUrl($category->url);

                if (function_exists('admin_bar')) {
                    AdminBar::registerLink(
                        trans('plugins/restaurant::menu.edit_this_menu_category'),
                        route('restaurant.menu-categories.edit', $category->getKey()),
                        null,
                        'restaurant.menu-categories.edit'
                    );
                }

                Theme::breadcrumb()
                    ->add($category->name, $category->url);

                do_action(BASE_ACTION_PUBLIC_RENDER_SINGLE, MENU_CATEGORY_MODULE_SCREEN_NAME, $category);

                return [
                    'view' => 'restaurant.menu-category',
                    'default_view' => 'plugins/restaurant::themes.menu-category',
                    'data' => compact('category'),
                    'slug' => $category->slug,
                ];
        }

        return $slug;
    }
}
