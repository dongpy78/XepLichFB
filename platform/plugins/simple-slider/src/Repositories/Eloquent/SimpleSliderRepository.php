<?php

namespace Botble\SimpleSlider\Repositories\Eloquent;

use Botble\SimpleSlider\Repositories\Interfaces\SimpleSliderInterface;
use Botble\Support\Repositories\Eloquent\RepositoriesAbstract;

class SimpleSliderRepository extends RepositoriesAbstract implements SimpleSliderInterface
{
    public function get_slider_by_key($key){
        $data = $this->model
        ->where('key', $key);
        
        return $this->applyBeforeExecuteQuery($data)->first();
    }
}
