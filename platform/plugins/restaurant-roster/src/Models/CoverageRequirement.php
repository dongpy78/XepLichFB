<?php

namespace Botble\RestaurantRoster\Models;

use Botble\Base\Models\BaseModel;

/**
 * Định biên phủ trạm hỗ trợ theo khung giờ: số người CÓ MẶT CÙNG LÚC trong suốt khung,
 * ví dụ Lobby 09:00–16:45 cần 2 nữ waiter đồng thời.
 */
class CoverageRequirement extends BaseModel
{
    protected $table = 'roster_coverage_requirements';

    protected $fillable = [
        'station',
        'weekday',
        'date',
        'start_time',
        'end_time',
        'shift_codes',
        'female_only',
        'min_staff',
        'target_staff',
        'is_confirmed',
        'source',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
        'weekday' => 'int',
        'min_staff' => 'int',
        'target_staff' => 'int',
        'is_confirmed' => 'bool',
        'female_only' => 'bool',
    ];

    /**
     * @return string[] Mã ca được tính phủ khung (rỗng = mọi ca).
     */
    public function getShiftCodeListAttribute(): array
    {
        return array_values(array_filter(array_map(fn ($code) => strtoupper(trim($code)), explode(',', (string) $this->shift_codes))));
    }

    public function getWindowLabelAttribute(): string
    {
        return substr((string) $this->start_time, 0, 5) . '–' . substr((string) $this->end_time, 0, 5);
    }
}
