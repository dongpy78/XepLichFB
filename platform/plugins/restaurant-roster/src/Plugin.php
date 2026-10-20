<?php

namespace Botble\RestaurantRoster;

use Botble\PluginManagement\Abstracts\PluginOperationAbstract;
use Illuminate\Support\Facades\Schema;

class Plugin extends PluginOperationAbstract
{
    public static function remove(): void
    {
        Schema::dropIfExists('roster_shifts');
        Schema::dropIfExists('roster_shift_templates');
        Schema::dropIfExists('roster_employees');
        Schema::dropIfExists('roster_positions');
        Schema::dropIfExists('roster_outlets');
    }
}
