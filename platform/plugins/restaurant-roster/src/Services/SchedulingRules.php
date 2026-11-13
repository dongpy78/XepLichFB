<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\RuleRevision;
use Botble\RestaurantRoster\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Cấu hình quy tắc xếp lịch" (quản lý 13/11/2026): nhóm ca đặc biệt quản lý bật / tắt không cần sửa code.
 * Một nguồn duy nhất cho thuật toán (ProblemBuilder), ký hiệu ca (RosterAssigner), cảnh báo trên Bảng xếp ca và trang cấu hình.
 * Giá trị hiện hành: bảng settings (khóa roster_scheduling_rules); lịch sử: roster_rule_revisions.
 */
class SchedulingRules
{
    public const SETTING_KEY = 'roster_scheduling_rules';

    private ?array $current = null;

    /** Mặc định giữ đúng cách xếp lịch trước khi có trang cấu hình: không Snack, Lobby bật đủ bốn ca. */
    public static function defaults(): array
    {
        return [
            'snack' => ['mode' => 'off', 'count' => 1],
            'lobby' => ['enabled' => true, 'codes' => array_fill_keys(self::lobbyCodes(), true)],
        ];
    }

    /** @return string[] */
    public static function lobbyCodes(): array
    {
        return array_map('strtoupper', (array) config('plugins.restaurant-roster.general.scheduling.lobby_support_codes', ['M9', 'A2', 'OH', 'M6']));
    }

    /** @return array<string, array{code: string, name: string, tag: string}> chế độ (villa / elprimo) => khu Snack */
    public static function snackOptions(): array
    {
        return (array) config('plugins.restaurant-roster.general.scheduling.snack.outlets', []);
    }

    public static function snackConfig(string $key, mixed $default = null): mixed
    {
        return config("plugins.restaurant-roster.general.scheduling.snack.{$key}", $default);
    }

    public function get(): array
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $stored = json_decode((string) setting(self::SETTING_KEY, ''), true);

        return $this->current = $this->normalize(is_array($stored) ? $stored : []);
    }

    /** Chuẩn hóa dữ liệu (từ form hoặc database): thiếu khóa thì lấy mặc định, giá trị lạ thì về an toàn. */
    public function normalize(array $input): array
    {
        $mode = (string) ($input['snack']['mode'] ?? 'off');
        $codes = [];

        foreach (self::lobbyCodes() as $code) {
            $codes[$code] = (bool) ($input['lobby']['codes'][$code] ?? true);
        }

        return [
            'snack' => [
                'mode' => isset(self::snackOptions()[$mode]) ? $mode : 'off',
                'count' => max(1, min(20, (int) ($input['snack']['count'] ?? 1))),
            ],
            'lobby' => ['enabled' => (bool) ($input['lobby']['enabled'] ?? true), 'codes' => $codes],
        ];
    }

    /** Ca hỗ trợ Lobby mã $code được tự xếp: công tắc tổng VÀ công tắc riêng đều bật. Mã ngoài danh sách theo công tắc tổng. */
    public function lobbyAllowed(string $code, ?array $rules = null): bool
    {
        $rules ??= $this->get();

        return $rules['lobby']['enabled'] && ($rules['lobby']['codes'][strtoupper($code)] ?? true);
    }

    public function lobbyFullyOn(?array $rules = null): bool
    {
        $rules ??= $this->get();

        return $rules['lobby']['enabled'] && ! in_array(false, $rules['lobby']['codes'], true);
    }

    /** @return array{mode: string, code: string, name: string, tag: string, count: int}|null */
    public function activeSnack(?array $rules = null): ?array
    {
        $rules ??= $this->get();
        $option = self::snackOptions()[$rules['snack']['mode']] ?? null;

        return $option ? ['mode' => $rules['snack']['mode'], ...$option, 'count' => $rules['snack']['count']] : null;
    }

    /** Khu Snack của một chế độ: tạo khi quản lý chọn lần đầu (không tạo sẵn để không ảnh hưởng nơi chưa dùng). */
    public function ensureSnackOutlet(string $mode): ?Outlet
    {
        $option = self::snackOptions()[$mode] ?? null;

        if (! $option) {
            return null;
        }

        return Outlet::query()->firstOrCreate(['code' => $option['code']], [
            'name' => $option['name'],
            'order' => 90 + array_search($mode, array_keys(self::snackOptions()), true),
            'status' => 'published',
        ]);
    }

    public function save(array $rules, ?int $userId, ?string $userName): array
    {
        $before = $this->get();
        $after = $this->normalize($rules);

        DB::transaction(function () use ($before, $after, $userId, $userName): void {
            if ($after['snack']['mode'] !== 'off') {
                $this->ensureSnackOutlet($after['snack']['mode']);
            }

            RuleRevision::query()->create([
                'before' => $before,
                'after' => $after,
                'summary' => mb_substr(implode('; ', $this->describeChanges($before, $after)) ?: 'Không đổi', 0, 1000),
                'user_id' => $userId,
                'user_name' => $userName,
            ]);
            setting()->set(self::SETTING_KEY, json_encode($after))->save();
        });

        return $this->current = $after;
    }

    /** @return string[] */
    public function describeChanges(array $before, array $after): array
    {
        $label = fn (string $mode) => self::snackOptions()[$mode]['name'] ?? 'Tắt';
        $onOff = fn (bool $value) => $value ? 'bật' : 'tắt';
        $changes = [];

        if ($before['snack']['mode'] !== $after['snack']['mode']) {
            $changes[] = "Snack: {$label($before['snack']['mode'])} → {$label($after['snack']['mode'])}";
        }

        if ($before['snack']['count'] !== $after['snack']['count']) {
            $changes[] = "Số người Snack: {$before['snack']['count']} → {$after['snack']['count']}";
        }

        if ($before['lobby']['enabled'] !== $after['lobby']['enabled']) {
            $changes[] = "Hỗ trợ Lobby/Bar: {$onOff($after['lobby']['enabled'])}";
        }

        foreach ($after['lobby']['codes'] as $code => $value) {
            if (($before['lobby']['codes'][$code] ?? true) !== $value) {
                $changes[] = "{$code}(L): {$onOff($value)}";
            }
        }

        return $changes;
    }

    /**
     * Ô lịch (từ $from) thuộc nhóm ca đang tắt theo $rules: Snack của nhà hàng không được chọn, ca hỗ trợ Lobby đã tắt.
     * Chỉ để cảnh báo – không tự sửa / xóa ô nào.
     *
     * @param int[]|null $employeeIds
     * @return Collection<int, array{id: int, employee_id: int, name: string, date: string, notation: string, reason: string, state: string}>
     */
    public function affected(array $rules, CarbonInterface $from, ?CarbonInterface $to = null, ?array $employeeIds = null): Collection
    {
        $stationCode = (string) config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY');
        $snackCodes = array_column(self::snackOptions(), 'name', 'code');
        $outlets = Outlet::query()->whereIn('code', [$stationCode, ...array_keys($snackCodes)])->pluck('code', 'id');
        $stationId = $outlets->search($stationCode);
        $activeCode = $this->activeSnack($rules)['code'] ?? null;
        $lobbyRelevant = ! $this->lobbyFullyOn($rules);
        $snackIds = $outlets->filter(fn ($code) => isset($snackCodes[$code]) && $code !== $activeCode)->keys()->all();

        if (! $snackIds && (! $lobbyRelevant || ! $stationId)) {
            return collect();
        }

        return Shift::query()
            ->with(['template:id,code,support_station', 'employee:id,name,outlet_id'])
            ->where('kind', Shift::KIND_SHIFT)
            ->whereDate('date', '>=', $from->toDateString())
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to->toDateString()))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->where(function ($q) use ($snackIds, $lobbyRelevant, $stationId): void {
                $q->whereIn('outlet_id', $snackIds ?: [0]);

                if ($lobbyRelevant && $stationId) {
                    $q->orWhere('outlet_id', $stationId)->orWhere('tags', 'like', '%"L"%');
                }
            })
            ->orderBy('date')
            ->get()
            ->map(function (Shift $shift) use ($rules, $snackIds, $outlets, $snackCodes, $stationId): ?array {
                $code = strtoupper((string) $shift->template?->code);

                if (in_array((int) $shift->outlet_id, $snackIds, true)) {
                    $reason = ($snackCodes[$outlets[$shift->outlet_id]] ?? 'Snack') . ' đang tắt';
                } elseif (((int) $shift->outlet_id === (int) $stationId && (int) $shift->employee?->outlet_id !== (int) $stationId)
                    || (in_array('L', $shift->tags ?: [], true) && ! $shift->template?->support_station)) {
                    if ($this->lobbyAllowed($code, $rules)) {
                        return null;
                    }

                    $reason = $rules['lobby']['enabled'] ? "Hỗ trợ Lobby {$code}(L) đang tắt" : 'Hỗ trợ Lobby/Bar đang tắt';
                } else {
                    return null;
                }

                return [
                    'id' => (int) $shift->getKey(),
                    'employee_id' => (int) $shift->employee_id,
                    'name' => (string) $shift->employee?->name,
                    'date' => $shift->date->toDateString(),
                    'notation' => (string) $shift->notation,
                    'reason' => $reason,
                    'manual' => $shift->source === 'manual',
                    'state' => match (true) {
                        (bool) $shift->is_locked => 'Đã khóa',
                        $shift->status === Shift::STATUS_PUBLISHED => 'Đã công bố',
                        $shift->source === 'manual' => 'Chỉnh tay',
                        default => 'Tự động',
                    },
                ];
            })
            ->filter()
            ->values();
    }
}
