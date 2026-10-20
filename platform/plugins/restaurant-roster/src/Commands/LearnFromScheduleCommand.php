<?php

namespace Botble\RestaurantRoster\Commands;

use Botble\RestaurantRoster\Models\CoverageRequirement;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Học cấu hình từ một lịch quản lý đã xếp tay (file JSON chép từ bảng Excel):
 * - Mẫu ca: khung (L) của M6/A2, OH & M9 làm trọn ở Lobby, OH chỉ cho nhân viên ở Nha Trang.
 * - Định biên outlet × ca × thứ và định biên phủ Lobby theo khung giờ (GỢI Ý – chưa xác nhận).
 * - Năng lực (chỉ THÊM dòng còn thiếu, kể cả năng lực hỗ trợ Lobby).
 * Không ghi đè dòng quản lý đã sửa tay hoặc đã xác nhận.
 */
class LearnFromScheduleCommand extends Command
{
    protected $signature = 'roster:learn-from-schedule
        {file=database/data/schedule_2026-10-12_2026-10-25.json : File JSON (đường dẫn tương đối thư mục plugin)}
        {--dry-run : Chỉ in kết quả, không ghi database}';

    protected $description = 'Học mẫu ca, định biên, định biên Lobby và năng lực từ lịch quản lý đã xếp';

    /** Khung Lobby theo mô tả của quản lý (15/10/2026). */
    private const LOBBY_WINDOWS = [['09:00', '14:00'], ['14:00', '17:00']];

    public function handle(): int
    {
        $path = base_path('platform/plugins/restaurant-roster/' . $this->argument('file'));

        if (! is_file($path)) {
            $this->error("Không tìm thấy file {$path}");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        $dry = (bool) $this->option('dry-run');
        $this->info($data['source'] ?? $path);

        DB::beginTransaction();

        try {
            $outlets = $this->ensureLobby();
            $templates = $this->configureTemplates();
            $cells = $this->parse($data, $outlets, $templates);

            $this->learnRequirements($cells, $outlets, $templates, $data);
            $this->learnCoverage($cells, $data);
            $this->learnSkills($cells, $outlets);

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dry ? 'Chạy thử xong – chưa ghi gì.' : 'Đã ghi dữ liệu học được (định biên ở trạng thái chưa xác nhận).');

        return self::SUCCESS;
    }

    private function ensureLobby(): array
    {
        $code = config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY');

        if (! Outlet::query()->where('code', $code)->exists()) {
            Outlet::query()->create(['name' => 'Quầy bar Lobby', 'code' => $code, 'order' => 5, 'status' => 'published']);
            $this->line("+ Tạo outlet {$code} (Quầy bar Lobby)");
        }

        return Outlet::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    }

    private function configureTemplates()
    {
        $station = config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY');
        $settings = [
            // Quản lý 15/10/2026: Lobby sáng chỉ dùng OH(L) và M9, chiều dùng A2(L) – M6 không còn khung (L).
            'M6' => ['lobby_start_time' => null, 'lobby_end_time' => null],
            'A2' => ['lobby_start_time' => '14:00', 'lobby_end_time' => '17:00'],
            // OH làm ở nhà hàng (nhân viên Nha Trang – xe đưa đón); OH(L) = OH có hỗ trợ Lobby cả ca.
            'OH' => ['lobby_start_time' => '08:45', 'lobby_end_time' => '16:45', 'allowed_residence' => 'nha_trang'],
            // M9 làm trọn ca ở Lobby: chỉ nữ, Waiter, ở Cam Ranh.
            'M9' => ['support_station' => $station, 'allowed_residence' => 'cam_ranh', 'allowed_gender' => 'female',
                'allowed_position_id' => \Botble\RestaurantRoster\Models\Position::query()->where('name', 'Waiter')->value('id')],
        ];

        foreach ($settings as $code => $values) {
            $template = ShiftTemplate::query()->where('code', $code)->first();

            if (! $template) {
                continue;
            }

            // Chỉ điền ô đang trống – không ghi đè cấu hình quản lý đã nhập.
            $fill = array_filter($values, fn ($value, $field) => $template->{$field} === null || $template->{$field} === '', ARRAY_FILTER_USE_BOTH);

            if ($fill) {
                $template->update($fill);
                $this->line("~ Mẫu ca {$code}: " . json_encode($fill, JSON_UNESCAPED_UNICODE));
            }
        }

        return ShiftTemplate::query()->get()->keyBy('code');
    }

    /**
     * @return array<int, array{employee: Employee, date: string, outlet: string, code: string, tags: string[], ot: int}>
     */
    private function parse(array $data, array $outlets, $templates): array
    {
        $start = CarbonImmutable::parse($data['start']);
        $employees = Employee::query()->with('outlet:id,code')->get();
        $cells = [];
        $unmatched = [];

        foreach ($data['employees'] as $row) {
            $matches = $employees->filter(fn (Employee $e) => mb_strtolower(trim((string) $e->name)) === mb_strtolower($row['name']));
            $employee = $matches->count() > 1 ? $matches->first(fn ($e) => $e->outlet->code === $row['outlet']) : $matches->first();

            if (! $employee) {
                $unmatched[] = $row['name'];

                continue;
            }

            foreach ($row['cells'] as $d => $cell) {
                if ($cell === 'OFF' || ! preg_match('/^([A-Z0-9]+)((?:\([A-Z0-9]+\))*)(?:\+(\d+))?$/', $cell, $m)) {
                    continue;
                }

                preg_match_all('/\(([A-Z0-9]+)\)/', $m[2], $tags);
                $code = $m[1];
                $tags = $tags[1];
                $outlet = match (true) {
                    in_array('RS', $tags, true), in_array('RS1', $tags, true) => 'ROOM',
                    $code === 'N' => 'NIGHT',
                    $code === 'M9' => 'LOBBY',
                    in_array('G', $tags, true), in_array('K', $tags, true) => 'GUSTO',
                    in_array('V', $tags, true) => 'VILLA',
                    default => $row['outlet'],
                };

                if (! isset($templates[$code], $outlets[$outlet])) {
                    continue;
                }

                $cells[] = ['employee' => $employee, 'home' => $row['outlet'], 'date' => $start->addDays($d)->toDateString(),
                    'outlet' => $outlet, 'code' => $code, 'tags' => $tags, 'ot' => (int) ($m[3] ?? 0)];
            }
        }

        if ($unmatched) {
            $this->warn('Không khớp tên: ' . implode(', ', $unmatched));
        }

        $this->line('Đọc được ' . count($cells) . ' ca làm.');

        return $cells;
    }

    private function learnRequirements(array $cells, array $outlets, $templates, array $data): void
    {
        $deleted = StaffingRequirement::query()->where('source', 'history')->where('is_confirmed', false)->delete();
        $this->line("- Xóa {$deleted} dòng định biên gợi ý cũ (chưa xác nhận)");

        // Đếm người theo outlet × ca × ngày (bỏ OH/M9 – đã tính ở định biên Lobby).
        $count = [];

        foreach ($cells as $cell) {
            // Người đi hỗ trợ Lobby (L) rời nhà hàng – không tính vào định biên nhà hàng (quản lý 15/10/2026).
            if ($cell['outlet'] === 'LOBBY' || in_array('L', $cell['tags'], true)) {
                continue;
            }

            $count[$cell['outlet']][$cell['code']][$cell['date']] = ($count[$cell['outlet']][$cell['code']][$cell['date']] ?? 0) + 1;
        }

        $kept = StaffingRequirement::query()->get()->map(fn ($r) => $r->outlet_id . '|' . $r->shift_template_id)->flip();
        $events = $data['events'] ?? [];
        $created = 0;

        foreach ($count as $outletCode => $byShift) {
            foreach ($byShift as $code => $byDate) {
                $outletId = $outlets[$outletCode];
                $templateId = $templates[$code]->getKey();

                if (isset($kept["{$outletId}|{$templateId}"])) {
                    continue;
                }

                // Mọi ngày trong kỳ (kể cả ngày không ai làm) để tính trung bình theo thứ.
                $series = [];

                foreach (range(0, 13) as $d) {
                    $date = CarbonImmutable::parse($data['start'])->addDays($d)->toDateString();
                    $series[$date] = $byDate[$date] ?? 0;
                }

                $normal = array_diff_key($series, $events) ?: $series;
                // Room: quản lý yêu cầu luôn 1 người mỗi ca sáng/chiều.
                $min = $outletCode === 'ROOM' ? max(1, min($normal)) : min($normal);
                [$minMale, $targetMale] = $this->maleRule($outletCode, $code);

                foreach (range(1, 7) as $weekday) {
                    $values = array_values(array_filter($series, fn ($date) => CarbonImmutable::parse($date)->dayOfWeekIso === $weekday, ARRAY_FILTER_USE_KEY));
                    $target = max($min, (int) round(array_sum($values) / max(1, count($values))));

                    if ($target === 0 && $min === 0) {
                        continue;
                    }

                    foreach ($outletCode === 'VILLA' ? [false, true] : [null] as $upperFloor) {
                        StaffingRequirement::query()->create([
                            'outlet_id' => $outletId,
                            'shift_template_id' => $templateId,
                            'weekday' => $weekday,
                            'upper_floor' => $upperFloor,
                            'min_staff' => min($min, $target),
                            'target_staff' => $target,
                            'min_male' => min($upperFloor ? 2 : $minMale, max(1, $target)),
                            'target_male' => min($targetMale, max(1, $target)),
                            'allow_reduce' => true,
                            'is_confirmed' => false,
                            'source' => 'history',
                            'note' => sprintf('Học từ lịch 12–25/10: thực tế %s người (TB thứ này), ít nhất %d ngày thường%s',
                                implode(' & ', $values), $min, $upperFloor ? ' – khi mở tầng trên: cần quản lý nhập lại' : ''),
                        ]);
                        $created++;
                    }
                }

                $this->line(sprintf('  %-6s %-3s TB/ngày %.1f, ít nhất %d', $outletCode, $code, array_sum($series) / 14, $min));
            }
        }

        $this->line("+ Tạo {$created} dòng định biên (chưa xác nhận)");
    }

    private function learnCoverage(array $cells, array $data): void
    {
        $station = config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY');

        // Quản lý đã chốt định biên Lobby: giữ nguyên, không học đè.
        if (CoverageRequirement::query()->where('station', $station)->where('is_confirmed', true)->exists()) {
            $this->line('- Định biên Lobby đã được quản lý xác nhận – giữ nguyên, không học lại.');

            return;
        }

        $deleted = CoverageRequirement::query()->where('source', 'history')->where('is_confirmed', false)->delete();
        $minutes = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $lobby = [
            'M6' => [540, 840], 'A2' => [840, 1020], 'OH' => [525, 1005],
        ];
        $perDay = [];

        foreach ($cells as $cell) {
            $interval = match (true) {
                $cell['code'] === 'M9' => [540, 1020],
                in_array('L', $cell['tags'], true) && isset($lobby[$cell['code']]) => $lobby[$cell['code']],
                default => null,
            };

            if (! $interval) {
                continue;
            }

            foreach (self::LOBBY_WINDOWS as $k => [$from, $to]) {
                $overlap = min($minutes($to), $interval[1]) - max($minutes($from), $interval[0]);

                if ($overlap > 0 && $overlap >= ($minutes($to) - $minutes($from)) * 0.5) {
                    $perDay[$k][$cell['date']] = ($perDay[$k][$cell['date']] ?? 0) + 1;
                }
            }
        }

        $events = $data['events'] ?? [];
        $created = 0;

        foreach (self::LOBBY_WINDOWS as $k => [$from, $to]) {
            $series = [];

            foreach (range(0, 13) as $d) {
                $date = CarbonImmutable::parse($data['start'])->addDays($d)->toDateString();
                $series[$date] = $perDay[$k][$date] ?? 0;
            }

            $normal = array_diff_key($series, $events) ?: $series;
            // Tối thiểu: không dưới 1 người (quầy Lobby luôn cần người) và không quá mức thấp nhất của ngày thường.
            $min = max(1, min($normal));

            foreach (range(1, 7) as $weekday) {
                $values = array_values(array_filter($series, fn ($date) => CarbonImmutable::parse($date)->dayOfWeekIso === $weekday, ARRAY_FILTER_USE_KEY));
                $target = max($min, (int) round(array_sum($values) / max(1, count($values))));

                CoverageRequirement::query()->create([
                    'station' => config('plugins.restaurant-roster.general.scheduling.station_code', 'LOBBY'),
                    'weekday' => $weekday,
                    'start_time' => $from,
                    'end_time' => $to,
                    'min_staff' => $min,
                    'target_staff' => $target,
                    'is_confirmed' => false,
                    'source' => 'history',
                    'note' => 'Học từ lịch 12–25/10: thực tế ' . implode(' & ', $values) . ' người',
                ]);
                $created++;
            }

            $this->line(sprintf('  Lobby %s–%s: theo ngày %s (tối thiểu %d)', $from, $to, implode(' ', $series), $min));
        }

        $this->line("- Xóa {$deleted}, + tạo {$created} dòng định biên Lobby (chưa xác nhận)");
    }

    private function learnSkills(array $cells, array $outlets): void
    {
        $existing = EmployeeSkill::query()->get()->map(fn ($s) => $s->employee_id . '|' . $s->outlet_id . '|' . ($s->shift_template_id ?: 0))->flip();
        $stats = [];
        $lobbyPeople = [];

        foreach ($cells as $cell) {
            $templateId = ShiftTemplate::query()->where('code', $cell['code'])->value('id');
            $key = $cell['employee']->getKey() . '|' . $outlets[$cell['outlet']] . '|' . $templateId;
            $stats[$key] = ($stats[$key] ?? 0) + 1;

            // Hỗ trợ Lobby (L) và M9 chỉ dành cho nhân viên nữ.
            if ((in_array('L', $cell['tags'], true) || $cell['outlet'] === 'LOBBY') && $cell['employee']->gender === 'female') {
                $lobbyPeople[$cell['employee']->getKey()] = true;
            }
        }

        $created = 0;

        foreach ($stats as $key => $count) {
            if (isset($existing[$key])) {
                continue;
            }

            [$employeeId, $outletId, $templateId] = array_map('intval', explode('|', $key));
            $home = (int) Employee::query()->whereKey($employeeId)->value('outlet_id') === $outletId;

            EmployeeSkill::query()->create([
                'employee_id' => $employeeId,
                'outlet_id' => $outletId,
                'shift_template_id' => $templateId,
                'level' => ($home && $count >= 2) || $count >= 3 ? 'primary' : 'eligible',
                'note' => "Học từ lịch 12–25/10: đã làm {$count} ca",
            ]);
            $existing[$key] = true;
            $created++;
        }

        // Năng lực hỗ trợ Lobby (ký hiệu L) cho người đã từng làm (L), OH hoặc M9.
        $lobbyId = $outlets['LOBBY'];
        $lobbyCreated = 0;

        foreach (array_keys($lobbyPeople) as $employeeId) {
            if (! isset($existing["{$employeeId}|{$lobbyId}|0"])) {
                EmployeeSkill::query()->create([
                    'employee_id' => $employeeId,
                    'outlet_id' => $lobbyId,
                    'shift_template_id' => null,
                    'level' => 'eligible',
                    'note' => 'Học từ lịch 12–25/10: đã hỗ trợ Lobby (L hoặc M9)',
                ]);
                $lobbyCreated++;
            }
        }

        $this->line("+ Thêm {$created} dòng năng lực ca/outlet và {$lobbyCreated} người có năng lực hỗ trợ Lobby");
    }

    /**
     * Quy tắc số nam do quản lý mô tả (12/10/2026). [tối thiểu, mục tiêu]
     */
    private function maleRule(string $outlet, string $shift): array
    {
        if (! in_array($shift, ['M6', 'A2'], true)) {
            return [0, 0];
        }

        return match ($outlet) {
            'GUSTO' => [1, 2],
            'VILLA' => [1, 2],
            'ROOM' => [1, 1],
            default => [0, 0],
        };
    }
}
