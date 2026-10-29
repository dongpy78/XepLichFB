<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\ScheduleVersion;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bản chụp lịch tuần từ dữ liệu Bảng xếp ca (cùng khu, cùng thứ tự nhân viên, cùng ký hiệu ca như trên bảng).
 * Dùng để: lưu phiên bản đã công bố, so sánh với lịch hiện tại (trạng thái), tạo file PNG / Excel / PDF.
 */
class ScheduleSnapshotService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CHANGED = 'changed';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Bản nháp',
        self::STATUS_PUBLISHED => 'Đã công bố',
        self::STATUS_CHANGED => 'Có thay đổi chưa công bố',
    ];

    private const DAY_NAMES = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];

    /**
     * @param array $grid kết quả RosterController::buildGrid (rows, days, weekStart, weekEnd)
     */
    public function fromGrid(array $grid, int $scopeOutletId = 0, ?string $scopeLabel = null): array
    {
        $days = collect($grid['days'])->map(fn (Carbon $d) => ['date' => $d->toDateString(), 'label' => self::DAY_NAMES[$d->dayOfWeekIso] . ' ' . $d->format('d/m')])->all();
        $sections = [];
        $codes = [];

        foreach ($grid['rows'] as $row) {
            $key = $row['section']['key'];
            $employee = $row['employee'];

            if (! isset($sections[$key])) {
                $sections[$key] = ['label' => (string) $row['section']['label'], 'rows' => []];
            }

            $cells = [];
            $offs = [];

            foreach ($days as $day) {
                $shift = $row['cells'][$day['date']] ?? null;
                $cells[] = $shift ? (string) $shift->notation : '';
                // Ô nghỉ (OFF / nghỉ phép) – tô nền xám trên file.
                $offs[] = $shift !== null && ! $shift->isWorking();

                if ($shift?->template?->code) {
                    $codes[$shift->template->code] = true;
                }
            }

            $sections[$key]['rows'][] = [
                'id' => (int) $employee->getKey(),
                'code' => (string) $employee->code,
                'name' => (string) $employee->name,
                'position' => (string) ($employee->position->name ?? ''),
                'outlet' => (string) ($employee->outlet->code ?? ''),
                'cells' => $cells,
                'offs' => $offs,
            ];
        }

        $legend = ShiftTemplate::query()->whereIn('code', array_keys($codes))->orderBy('order')->get()
            ->mapWithKeys(fn (ShiftTemplate $t) => [(string) $t->code => $t->time_range])->all();

        return [
            'week_start' => $grid['weekStart']->toDateString(),
            'week_end' => $grid['weekEnd']->toDateString(),
            'scope_outlet_id' => $scopeOutletId,
            'scope_label' => $scopeLabel ?: 'Toàn bộ bảng xếp ca',
            'days' => $days,
            'sections' => array_values($sections),
            'legend' => $legend,
            'employee_count' => (int) array_sum(array_map(fn ($s) => count($s['rows']), $sections)),
        ];
    }

    /** Dấu vân tay nội dung lịch (khu, thứ tự, nhân viên, ca từng ngày) – không tính thời điểm tạo. */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode([$snapshot['days'], $snapshot['sections']], JSON_UNESCAPED_UNICODE));
    }

    /** Trạng thái tuần: bản nháp (chưa từng công bố) / đã công bố (khớp bản gần nhất) / có thay đổi chưa công bố. */
    public function status(array $snapshot, ?ScheduleVersion $latest): string
    {
        return match (true) {
            $latest === null => self::STATUS_DRAFT,
            $latest->hash === $this->hash($snapshot) => self::STATUS_PUBLISHED,
            default => self::STATUS_CHANGED,
        };
    }

    /** Lưu phiên bản công bố mới (số phiên bản tăng dần theo tuần × phạm vi); phiên bản cũ giữ nguyên. */
    public function store(array $snapshot, ?int $userId, ?string $userName): ScheduleVersion
    {
        return DB::transaction(function () use ($snapshot, $userId, $userName): ScheduleVersion {
            $latest = ScheduleVersion::query()->whereDate('week_start', $snapshot['week_start'])->where('scope_outlet_id', $snapshot['scope_outlet_id'])
                ->lockForUpdate()->max('version');
            $now = now();

            return ScheduleVersion::query()->create([
                'week_start' => $snapshot['week_start'],
                'scope_outlet_id' => $snapshot['scope_outlet_id'],
                'version' => (int) $latest + 1,
                'hash' => $this->hash($snapshot),
                'snapshot' => [...$snapshot, 'version' => (int) $latest + 1, 'published_at' => $now->format('d/m/Y H:i'), 'published_by' => $userName],
                'employee_count' => $snapshot['employee_count'],
                'published_by' => $userId,
                'published_by_name' => $userName,
                'published_at' => $now,
            ]);
        });
    }

    /** Dòng mô tả bản lịch in trên file: "Phiên bản v2 · công bố 30/10/2026 14:05 bởi …" hoặc "BẢN NHÁP – chưa công bố". */
    public function caption(array $snapshot): string
    {
        return isset($snapshot['version'])
            ? sprintf('Phiên bản v%d · công bố %s%s', $snapshot['version'], $snapshot['published_at'] ?? '', ! empty($snapshot['published_by']) ? ' bởi ' . $snapshot['published_by'] : '')
            : 'BẢN NHÁP – chưa công bố (' . now()->format('d/m/Y H:i') . ')';
    }

    public function period(array $snapshot): string
    {
        return Carbon::parse($snapshot['week_start'])->format('d/m/Y') . ' – ' . Carbon::parse($snapshot['week_end'])->format('d/m/Y');
    }

    /** Tên file: Lich-lam-viec-2026-11-02_2026-11-08-v1(.phan-2) / -ban-nhap. */
    public function filename(array $snapshot, string $extension, ?int $part = null): string
    {
        return sprintf('Lich-lam-viec-%s_%s-%s%s.%s', $snapshot['week_start'], $snapshot['week_end'],
            isset($snapshot['version']) ? 'v' . $snapshot['version'] : 'ban-nhap', $part ? '-phan-' . $part : '', $extension);
    }
}
