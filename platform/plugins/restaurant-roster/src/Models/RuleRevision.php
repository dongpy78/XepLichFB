<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Botble\RestaurantRoster\Models\Concerns\FastAttributes;

class RuleRevision extends BaseModel
{
    use FastAttributes;

    protected $table = 'roster_rule_revisions';

    protected $fillable = [
        'before',
        'after',
        'summary',
        'user_id',
        'user_name',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];
}
