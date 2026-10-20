<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Models\BaseModel;
use Botble\RestaurantRoster\Forms\CoverageRequirementForm;
use Botble\RestaurantRoster\Http\Requests\CoverageRequirementRequest;
use Botble\RestaurantRoster\Models\CoverageRequirement;
use Botble\RestaurantRoster\Tables\CoverageRequirementTable;

class CoverageRequirementController extends RosterCrudController
{
    protected function model(): string
    {
        return CoverageRequirement::class;
    }

    protected function form(): string
    {
        return CoverageRequirementForm::class;
    }

    protected function table(): string
    {
        return CoverageRequirementTable::class;
    }

    protected function request(): string
    {
        return CoverageRequirementRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'roster.coverage';
    }

    protected function title(): string
    {
        return 'Định biên quầy Lobby';
    }

    protected function prepare(array $data, ?BaseModel $model): array
    {
        return ['source' => 'manual'] + $data;
    }
}
