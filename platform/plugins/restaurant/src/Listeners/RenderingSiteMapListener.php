<?php

namespace Botble\Restaurant\Listeners;

use Botble\Restaurant\Models\MenuCategory;
use Botble\Restaurant\Models\MenuItem;
use Botble\Theme\Events\RenderingSiteMapEvent;
use Botble\Theme\Facades\SiteMapManager;

class RenderingSiteMapListener
{
    public function handle(RenderingSiteMapEvent $event): void
    {
        if ($key = $event->key) {
            switch ($key) {
                case 'menu-categories':
                    $categories = MenuCategory::query()
                        ->with('slugable')
                        ->wherePublished()
                        ->select(['id', 'name', 'updated_at'])
                        ->latest('updated_at')
                        ->get();

                    foreach ($categories as $category) {
                        SiteMapManager::add($category->url, $category->updated_at, '0.8');
                    }

                    break;

                case 'menu-items':
                    $items = MenuItem::query()
                        ->with('slugable')
                        ->wherePublished()
                        ->select(['id', 'name', 'updated_at'])
                        ->latest('updated_at')
                        ->get();

                    foreach ($items as $item) {
                        SiteMapManager::add($item->url, $item->updated_at, '0.6', 'weekly');
                    }

                    break;
            }

            return;
        }

        $categoryLastUpdated = MenuCategory::query()
            ->wherePublished()
            ->latest('updated_at')
            ->value('updated_at');

        if ($categoryLastUpdated) {
            SiteMapManager::addSitemap(SiteMapManager::route('menu-categories'), $categoryLastUpdated);
        }

        $itemLastUpdated = MenuItem::query()
            ->wherePublished()
            ->latest('updated_at')
            ->value('updated_at');

        if ($itemLastUpdated) {
            SiteMapManager::addSitemap(SiteMapManager::route('menu-items'), $itemLastUpdated);
        }
    }
}
