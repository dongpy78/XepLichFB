<?php

namespace Botble\Restaurant;

use Botble\PluginManagement\Abstracts\PluginOperationAbstract;
use Illuminate\Support\Facades\Schema;

class Plugin extends PluginOperationAbstract
{
    public static function remove(): void
    {
        Schema::dropIfExists('restaurant_reservations');
        Schema::dropIfExists('restaurant_menu_items');
        Schema::dropIfExists('restaurant_menu_categories');
        Schema::dropIfExists('restaurant_chefs');
        Schema::dropIfExists('restaurant_menu_items_translations');
        Schema::dropIfExists('restaurant_menu_categories_translations');
        Schema::dropIfExists('restaurant_chefs_translations');
    }
}
