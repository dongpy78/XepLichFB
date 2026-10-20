<?php

namespace Botble\Restaurant\Http\Controllers\Settings;

use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Restaurant\Forms\Settings\RestaurantSettingForm;
use Botble\Restaurant\Http\Requests\Settings\RestaurantSettingRequest;
use Botble\Setting\Http\Controllers\SettingController;

class RestaurantSettingController extends SettingController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/restaurant::restaurant.name'));
    }

    public function edit()
    {
        $this->pageTitle(trans('plugins/restaurant::restaurant.settings.title'));

        return RestaurantSettingForm::create()->renderForm();
    }

    public function update(RestaurantSettingRequest $request): BaseHttpResponse
    {
        return $this->performUpdate($request->validated());
    }
}
