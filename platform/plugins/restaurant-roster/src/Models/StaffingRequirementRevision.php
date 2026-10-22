<?php

namespace Botble\RestaurantRoster\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lịch sử chỉnh sửa một dòng định biên: hành động, giá trị trước / sau, lý do, người sửa.
 */
class StaffingRequirementRevision extends BaseModel
{
    protected $table = 'roster_staffing_requirement_revisions';

    protected $fillable = [
        'requirement_id',
        'action',
        'before',
        'after',
        'reason',
        'user_id',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
