<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Định biên theo nhóm nhân sự của một dòng định biên: một vị trí (Hostess, Waiter…) hoặc nhóm "cấp quản lý".
 */
class StaffingRequirementPosition extends BaseModel
{
    public const KIND_POSITION = 'position';
    public const KIND_MANAGERS = 'managers';

    protected $table = 'roster_staffing_requirement_positions';

    protected $fillable = [
        'requirement_id',
        'kind',
        'position_id',
        'min_staff',
        'target_staff',
        'note',
    ];

    protected $casts = [
        'min_staff' => 'int',
        'target_staff' => 'int',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function label(): string
    {
        return $this->kind === self::KIND_MANAGERS ? 'Cấp quản lý (Junior Captain trở lên)' : ($this->position?->name ?? 'Vị trí #' . $this->position_id);
    }
}
