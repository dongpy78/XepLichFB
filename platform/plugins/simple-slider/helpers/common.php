<?php

use Botble\SimpleSlider\Models\SimpleSlider;
use Botble\SimpleSlider\Repositories\Interfaces\SimpleSliderInterface;
use Illuminate\Database\Eloquent\Collection;

if (! function_exists('get_all_simple_sliders')) {
    /**
     * @deprecated
     */
    function get_all_simple_sliders(array $condition = []): Collection
    {
        return SimpleSlider::query()->where($condition)->get();
    }
}

if(! function_exists('get_slider_by_key')){
    function get_slider_by_key($key) {
        return app(SimpleSliderInterface::class)->get_slider_by_key($key);
    }
}