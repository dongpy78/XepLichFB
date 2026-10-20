<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\RestaurantRoster\Forms\OutletDayForm;
use Botble\RestaurantRoster\Http\Requests\OutletDayRequest;
use Botble\RestaurantRoster\Models\OutletDay;
use Botble\RestaurantRoster\Tables\OutletDayTable;

class OutletDayController extends RosterCrudController
{
    protected function model(): string
    {
        return OutletDay::class;
    }

    protected function form(): string
    {
        return OutletDayForm::class;
    }

    protected function table(): string
    {
        return OutletDayTable::class;
    }

    protected function request(): string
    {
        return OutletDayRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'roster.outlet-days';
    }

    protected function title(): string
    {
        return 'Ngày đặc biệt của outlet';
    }
}
