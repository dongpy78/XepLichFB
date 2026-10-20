<?php

namespace Botble\RestaurantRoster\Http\Controllers;

use Botble\RestaurantRoster\Forms\EmployeeSkillForm;
use Botble\RestaurantRoster\Http\Requests\EmployeeSkillRequest;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Tables\EmployeeSkillTable;

class EmployeeSkillController extends RosterCrudController
{
    protected function model(): string
    {
        return EmployeeSkill::class;
    }

    protected function form(): string
    {
        return EmployeeSkillForm::class;
    }

    protected function table(): string
    {
        return EmployeeSkillTable::class;
    }

    protected function request(): string
    {
        return EmployeeSkillRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'roster.skills';
    }

    protected function title(): string
    {
        return 'Năng lực nhân viên';
    }
}
