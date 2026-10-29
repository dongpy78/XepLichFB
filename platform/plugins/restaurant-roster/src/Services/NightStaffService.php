<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Illuminate\Support\Collection;

/**
 * Nhận diện ca đêm và outlet Night cho bộ dựng bài toán.
 *
 * Quản lý 29/10/2026: thuật toán chỉ tự xếp ca đêm cho người bật "Night cố định" trong hồ sơ nhân viên.
 * Night luân phiên không còn tự động (bỏ trang Nhân sự Night, đề xuất / duyệt luân phiên, nhu cầu Night theo ngày):
 * quản lý xếp tay ca N trên Bảng xếp ca. Dữ liệu cũ (roster_night_rotations, roster_night_demands) được giữ, không dùng.
 */
class NightStaffService
{
    public function __construct(private readonly ProblemBuilder $builder)
    {
    }

    /** @return Collection<int, ShiftTemplate> */
    public function nightTemplates(): Collection
    {
        return ShiftTemplate::query()->get()->filter(fn (ShiftTemplate $t) => $this->builder->shiftSpec($t)->category() === 'night')->values();
    }

    /**
     * Outlet Night: outlet cố định của ca đêm (shift_outlet, vd N → NIGHT) và outlet có định biên ca đêm.
     *
     * @return int[]
     */
    public function nightOutletIds(): array
    {
        $templates = $this->nightTemplates();
        $map = (array) config('plugins.restaurant-roster.general.scheduling.shift_outlet', []);
        $codes = $templates->map(fn ($t) => $map[strtoupper((string) $t->code)] ?? null)->filter()->all();

        return Outlet::query()->whereIn('code', $codes)->pluck('id')
            ->merge(StaffingRequirement::query()->whereIn('shift_template_id', $templates->modelKeys())->pluck('outlet_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
