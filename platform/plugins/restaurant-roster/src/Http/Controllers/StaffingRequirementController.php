<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\Base\Models\BaseModel;
use Botble\RestaurantRoster\Forms\StaffingRequirementForm;
use Botble\RestaurantRoster\Http\Requests\StaffingRequirementRequest;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Tables\StaffingRequirementTable;

class StaffingRequirementController extends RosterCrudController
{
    protected function model(): string
    {
        return StaffingRequirement::class;
    }

    protected function form(): string
    {
        return StaffingRequirementForm::class;
    }

    protected function table(): string
    {
        return StaffingRequirementTable::class;
    }

    protected function request(): string
    {
        return StaffingRequirementRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'roster.requirements';
    }

    protected function title(): string
    {
        return 'Định biên nhân sự';
    }

    protected function prepare(array $data, ?BaseModel $model): array
    {
        // Sửa tay = quản lý đã xem lại dòng này.
        return ['source' => 'manual'] + $data;
    }
}
