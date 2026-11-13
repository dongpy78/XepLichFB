<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\Employee;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\OutletDay;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Lưu một ô lịch cho nhân viên và đọc ký hiệu ca kiểu bảng Excel:
 * "OFF", "AL", "M6", "A2(L)(BQ)", "M6(K)+3", "A2(RS)".
 */
class RosterAssigner
{
    /**
     * @return array{kind: string, template: ?ShiftTemplate, tags: string[], overtime: float, leave_type: ?string}
     */
    public function parse(string $notation): array
    {
        $value = strtoupper(preg_replace('/\s+/', '', $notation));

        if ($value === 'OFF') {
            return ['kind' => Shift::KIND_OFF, 'template' => null, 'tags' => [], 'overtime' => 0.0, 'leave_type' => null];
        }

        if (array_key_exists($value, $this->leaveTypes())) {
            return ['kind' => Shift::KIND_LEAVE, 'template' => null, 'tags' => [], 'overtime' => 0.0, 'leave_type' => $value];
        }

        if (! preg_match('/^([A-Z0-9]+)((?:\([A-Z0-9]+\))*)(?:\+(\d+(?:[.,]\d+)?))?$/', $value, $match)) {
            throw new InvalidArgumentException("Không hiểu ký hiệu \"{$notation}\". Ví dụ hợp lệ: M6, A2(L)(BQ), M6(K)+3, OFF, AL.");
        }

        $template = ShiftTemplate::query()->where('code', $match[1])->first();

        if (! $template) {
            throw new InvalidArgumentException("Không có mẫu ca mã \"{$match[1]}\".");
        }

        preg_match_all('/\(([A-Z0-9]+)\)/', $match[2], $tagMatches);
        $tags = array_values(array_unique($tagMatches[1]));

        if ($unknown = array_diff($tags, array_keys($this->tagDefinitions()))) {
            throw new InvalidArgumentException('Ký hiệu phụ chưa được khai báo: ' . implode(', ', $unknown) . '.');
        }

        // Ký hiệu chỉ dành cho ca Snack (EL – không phải ký hiệu outlet chung như V): đi với ca khác thì là nội dung tự do (parseFree giữ nguyên văn).
        $snackOnly = array_filter(array_column(SchedulingRules::snackOptions(), 'tag'), fn ($tag) => ! isset($this->tagDefinitions()[$tag]['outlet']));

        if (array_intersect($tags, $snackOnly) && strtoupper((string) $match[1]) !== strtoupper((string) SchedulingRules::snackConfig('shift_code', 'M9'))) {
            throw new InvalidArgumentException('Ký hiệu ' . implode(', ', array_intersect($tags, $snackOnly)) . ' chỉ dùng với ca Snack.');
        }

        return [
            'kind' => Shift::KIND_SHIFT,
            'template' => $template,
            'tags' => $tags,
            'overtime' => isset($match[3]) ? (float) str_replace(',', '.', $match[3]) : 0.0,
            'leave_type' => null,
        ];
    }

    /**
     * Ô nhập tự do của quản lý: ký hiệu chuẩn xử lý như parse(); còn lại vẫn lưu và in đúng nguyên văn.
     * Bắt đầu bằng mã ca ("M6(EL)", "A2 học việc"): vẫn là ca đó, tính giờ và ký hiệu phụ đã khai báo. Không có mã ca ("Training"): ô không làm việc.
     */
    public function parseFree(string $notation): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $notation));

        try {
            return $this->parse($text);
        } catch (InvalidArgumentException) {
            // Không phải ký hiệu chuẩn: giữ nguyên văn bên dưới.
        }

        $template = ShiftTemplate::query()->get()->sortByDesc(fn (ShiftTemplate $t) => mb_strlen((string) $t->code))
            ->first(fn (ShiftTemplate $t) => $t->code && preg_match('/^' . preg_quote((string) $t->code, '/') . '(?![\p{L}\p{N}])/iu', $text));

        if (! $template) {
            return ['kind' => Shift::KIND_LEAVE, 'template' => null, 'tags' => [], 'overtime' => 0.0, 'leave_type' => Shift::LEAVE_FREE_TEXT, 'display' => $text];
        }

        preg_match_all('/\(([^()]+)\)/u', $text, $tagMatches);
        preg_match('/\+(\d+(?:[.,]\d+)?)$/u', $text, $overtime);

        return [
            'kind' => Shift::KIND_SHIFT,
            'template' => $template,
            'tags' => array_values(array_intersect(array_unique(array_map(fn ($tag) => mb_strtoupper(trim($tag)), $tagMatches[1])), array_keys($this->tagDefinitions()))),
            'overtime' => isset($overtime[1]) ? (float) str_replace(',', '.', $overtime[1]) : 0.0,
            'leave_type' => null,
            'display' => $text,
        ];
    }

    /**
     * @param array{kind: string, template?: ?ShiftTemplate, tags?: string[], overtime?: float, leave_type?: ?string, note?: ?string, outlet_id?: ?int, source?: string, run_id?: ?int} $spec
     */
    public function assign(Employee $employee, CarbonInterface $date, array $spec, ?int $userId = null, string $status = Shift::STATUS_DRAFT): Shift
    {
        $values = $this->values($employee, $date, $spec, $userId, $status);
        // Tìm theo ngày (không phụ thuộc cách database lưu cột date) rồi cập nhật hoặc tạo mới.
        $shift = Shift::query()->where('employee_id', $employee->getKey())->whereDate('date', $date->toDateString())->first();

        if ($shift) {
            $shift->update($values);

            return $shift;
        }

        return Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date->toDateString(), ...$values]);
    }

    /**
     * Giá trị cột của một ô lịch (dùng chung cho lưu từng ô và ghi hàng loạt).
     */
    public function values(Employee $employee, CarbonInterface $date, array $spec, ?int $userId = null, string $status = Shift::STATUS_DRAFT): array
    {
        $tags = array_values(array_intersect($spec['tags'] ?? [], array_keys($this->tagDefinitions())));

        $overtime = max(0, min(12, (float) ($spec['overtime'] ?? 0)));

        $outletId = $spec['outlet_id'] ?? $this->resolveOutletId($employee, $tags, $spec['template'] ?? null);

        // Chuẩn hóa mã ca: ca làm trọn ở trạm Lobby (vd M9) luôn là "M9(L)" – "M9" và "M9(L)" là cùng một ca, không tạo hai cách ghi.
        // Cùng mẫu ca nhưng làm ở quầy Snack thì không phải Lobby: M9(V) / M9(EL).
        if (($spec['template'] ?? null)?->support_station && isset($this->tagDefinitions()['L']) && ! in_array('L', $tags, true) && ! $this->snackTagFor($outletId)) {
            $tags[] = 'L';
        }

        // Nhà hàng gốc đóng cửa ngày này (chỉ còn một nhà hàng hoạt động): người đó làm thẳng tại nhà hàng còn mở - outlet làm việc là
        // nhà hàng đó, ca ghi M6 / A2 (không thêm (G) / (V); A2(V)(K) -> A2(K)). Outlet gốc trong hồ sơ không đổi.
        if ($spec['kind'] === Shift::KIND_SHIFT && ($open = $this->openRestaurantFor($employee, $date))) {
            if ((int) $outletId === (int) $employee->outlet_id) {
                $outletId = $open;
            }

            $restaurantTags = array_filter(array_map(fn ($o) => $this->tagForOutlet($o), $this->restaurantOutletIds()));
            $tags = array_values(array_diff($tags, $restaurantTags));
        }

        $values = [
            'outlet_id' => $outletId,
            'kind' => $spec['kind'],
            'shift_template_id' => null,
            'leave_type' => null,
            'tags' => null,
            'display_notation' => $spec['display'] ?? null,
            'start_at' => null,
            'end_at' => null,
            'split_start_at' => null,
            'split_end_at' => null,
            'break_minutes' => 0,
            'overtime_hours' => 0,
            'overtime_status' => null,
            'overtime_approved_by' => null,
            'overtime_approved_at' => null,
            'hours' => 0,
            'note' => $spec['note'] ?? null,
            'status' => $status,
            'source' => $spec['source'] ?? 'manual',
            'run_id' => $spec['run_id'] ?? null,
            'created_by' => $userId,
        ];

        if ($spec['kind'] === Shift::KIND_SHIFT) {
            /** @var ShiftTemplate $template */
            $template = $spec['template'];
            $resolved = $template->resolveForDate($date);

            // Ca làm trọn ở Lobby (M9) luôn ghi ký hiệu (L): M9(L). Ở quầy Snack thì không.
            if ($template->support_station && ! in_array('L', $tags, true) && ! $this->snackTagFor($values['outlet_id'] ?? null)) {
                $tags[] = 'L';
            }

            $values = [
                ...$values,
                ...$resolved,
                'shift_template_id' => $template->getKey(),
                'break_minutes' => $template->break_minutes,
                'tags' => $tags ?: null,
                'overtime_hours' => $overtime,
                // Tăng ca mới nhập luôn ở trạng thái chờ duyệt; quản lý có quyền duyệt xác nhận trên Bảng xếp ca.
                'overtime_status' => $overtime > 0 ? ($spec['overtime_status'] ?? 'pending') : null,
                'hours' => round($resolved['hours'] + $overtime, 2),
            ];
        } elseif ($spec['kind'] === Shift::KIND_LEAVE) {
            $values['leave_type'] = $spec['leave_type'];
        }

        return $values;
    }

    /** @var array<string, int>|null */
    private ?array $outletIds = null;

    /**
     * @return array<string, int> mã outlet => id (nạp một lần cho mỗi request)
     */
    protected function outletIdsByCode(): array
    {
        return $this->outletIds ??= Outlet::query()->whereNotNull('code')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    }

    public function tagDefinitions(): array
    {
        return config('plugins.restaurant-roster.general.shift_tags', []);
    }

    protected function leaveTypes(): array
    {
        return config('plugins.restaurant-roster.general.leave_types', []);
    }

    /**
     * Ca có ký hiệu mượn người (G, V, K, RS) được tính cho outlet đích; ca có outlet cố định (N → NIGHT)
     * tính cho outlet đó; còn lại tính cho outlet của nhân viên.
     */
    public function resolveOutletId(Employee $employee, array $tags, ?ShiftTemplate $template = null): ?int
    {
        // Ca Snack: mẫu ca Snack + ký hiệu nhà hàng Snack (M9(V), M9(EL)) là quầy Snack, không phải điều chuyển sang Villa.
        if ($snack = $this->snackOutletFor($tags, $template)) {
            return $snack;
        }

        // Ký hiệu khu trực thuộc (K → Kid-Deline) quyết định outlet trước: A2(V)(K) = người Villa đứng Kid-Deline của Gusto.
        $parents = config('plugins.restaurant-roster.general.scheduling.outlet_parents', []);
        usort($tags, fn ($a, $b) => (int) isset($parents[$this->tagDefinitions()[$b]['outlet'] ?? '']) <=> (int) isset($parents[$this->tagDefinitions()[$a]['outlet'] ?? '']));

        foreach ($tags as $tag) {
            $code = $this->tagDefinitions()[$tag]['outlet'] ?? null;

            if ($code && $outletId = ($this->outletIdsByCode()[$code] ?? null)) {
                return (int) $outletId;
            }
        }

        $shiftOutlet = config('plugins.restaurant-roster.general.scheduling.shift_outlet.' . $template?->code);

        if ($shiftOutlet && $outletId = ($this->outletIdsByCode()[$shiftOutlet] ?? null)) {
            return (int) $outletId;
        }

        return $employee->outlet_id;
    }

    /**
     * Ký hiệu phụ khi xếp nhân viên vào outlet (ngược của resolveOutletId): Villa → [V], Gusto → [G],
     * Kid-Deline → [K] cho người Gusto, [V, K] cho người Villa (ghi rõ người từ outlet khác sang).
     *
     * @return string[]
     */
    public function tagsForOutlet(Employee $employee, ?int $outletId, ?ShiftTemplate $template = null, ?CarbonInterface $date = null): array
    {
        if (! $outletId || (int) $outletId === (int) $employee->outlet_id) {
            return [];
        }

        // Nhà hàng gốc đóng cửa: làm tại nhà hàng còn mở là ca thường, không phải điều chuyển -> không có (G) / (V).
        $open = $date ? $this->openRestaurantFor($employee, $date) : null;

        if ($open && (int) $outletId === $open) {
            return [];
        }

        $tag = $this->tagForOutlet($outletId, $template);
        $codes = array_flip($this->outletIdsByCode());
        $parent = config('plugins.restaurant-roster.general.scheduling.outlet_parents.' . ($codes[$outletId] ?? ''));

        if ($tag && $parent) {
            $parentId = $this->outletIdsByCode()[$parent] ?? null;

            if ($parentId && (int) $parentId !== (int) $employee->outlet_id && (int) $parentId !== $open
                && ($origin = $this->tagForOutlet($employee->outlet_id, $template))) {
                return [$origin, $tag];
            }
        }

        return $tag ? [$tag] : [];
    }

    /** Ký hiệu in trên lịch của quầy Snack (V / EL), hoặc null nếu không phải quầy Snack. */
    public function snackTagFor(?int $outletId): ?string
    {
        $code = $outletId ? (array_flip($this->outletIdsByCode())[$outletId] ?? null) : null;

        foreach (SchedulingRules::snackOptions() as $option) {
            if ($code !== null && $option['code'] === $code) {
                return $option['tag'];
            }
        }

        return null;
    }

    /** Quầy Snack theo ký hiệu khi nhập tay (M9(V), M9(EL)); chỉ với mẫu ca Snack và khi quầy đã được tạo. */
    public function snackOutletFor(array $tags, ?ShiftTemplate $template): ?int
    {
        if (! $template || strtoupper((string) $template->code) !== strtoupper((string) SchedulingRules::snackConfig('shift_code', 'M9'))) {
            return null;
        }

        // Quản lý gõ M9(V) / M9(EL) là ca Snack của nhà hàng đó, kể cả khi đang chọn nhà hàng Snack khác hoặc quầy chưa từng được tạo.
        foreach (SchedulingRules::snackOptions() as $mode => $option) {
            if (in_array($option['tag'], $tags, true)) {
                if (! isset($this->outletIdsByCode()[$option['code']])) {
                    app(SchedulingRules::class)->ensureSnackOutlet($mode);
                    $this->outletIds = null;
                }

                return ($id = $this->outletIdsByCode()[$option['code']] ?? null) ? (int) $id : null;
            }
        }

        return null;
    }

    /**
     * Nhà hàng gốc (Villa / Gusto – transfer_outlets) của nhân viên đóng cửa ngày này và đúng một nhà hàng còn mở → id nhà hàng đó.
     * Đóng cửa = "Ngày đặc biệt" đánh dấu đóng, hoặc outlet ngừng hoạt động (giống ProblemBuilder::closedRestaurantDays).
     */
    public function openRestaurantFor(Employee $employee, CarbonInterface $date): ?int
    {
        $restaurants = $this->restaurantOutletIds();
        $home = (int) $employee->outlet_id;

        if (! in_array($home, $restaurants, true)) {
            return null;
        }

        $closed = $this->closedRestaurants($date->toDateString());

        if (! in_array($home, $closed, true)) {
            return null;
        }

        $open = array_values(array_diff($restaurants, $closed));

        return count($open) === 1 ? $open[0] : null;
    }

    /** @return int[] */
    protected function restaurantOutletIds(): array
    {
        return $this->restaurantIds ??= array_values(array_filter(array_map(
            fn ($code) => $this->outletIdsByCode()[$code] ?? null,
            (array) config('plugins.restaurant-roster.general.scheduling.transfer_outlets', ['GUSTO', 'VILLA'])
        )));
    }

    /** @return int[] nhà hàng đóng cửa ngày $date (nạp một lần mỗi ngày) */
    protected function closedRestaurants(string $date): array
    {
        if (! isset($this->closedByDate[$date])) {
            $ids = $this->restaurantOutletIds();
            $this->closedByDate[$date] = $ids ? array_values(array_unique([
                ...Outlet::query()->whereIn('id', $ids)->where('status', '!=', BaseStatusEnum::PUBLISHED)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ...OutletDay::query()->whereIn('outlet_id', $ids)->where('is_closed', true)->whereDate('date', $date)->pluck('outlet_id')->map(fn ($id) => (int) $id)->all(),
            ])) : [];
        }

        return $this->closedByDate[$date];
    }

    /** @var int[]|null */
    private ?array $restaurantIds = null;

    /** @var array<string, int[]> */
    private array $closedByDate = [];

    /**
     * Ký hiệu phụ thể hiện việc làm ngoài outlet chính (ngược của resolveOutletId), ví dụ Gusto → G.
     */
    public function tagForOutlet(?int $outletId, ?ShiftTemplate $template = null): ?string
    {
        if ($snack = $this->snackTagFor($outletId)) {
            return $snack;
        }

        $code = $outletId ? (array_flip($this->outletIdsByCode())[$outletId] ?? null) : null;

        if (! $code || config('plugins.restaurant-roster.general.scheduling.shift_outlet.' . $template?->code) === $code) {
            return null;
        }

        foreach ($this->tagDefinitions() as $tag => $definition) {
            if (($definition['outlet'] ?? null) === $code && $tag !== 'RS1') {
                return $tag;
            }
        }

        return null;
    }
}
