<?php

namespace Botble\RestaurantRoster\Commands;

use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Khởi tạo dữ liệu cho thuật toán từ lịch đã có (một lần). Mọi giá trị sinh ra đều là GỢI Ý
 * từ lịch thực tế, được đánh dấu chưa xác nhận để quản lý duyệt lại; không ghi đè cấu hình tay.
 */
class BootstrapSchedulingCommand extends Command
{
    protected $signature = 'roster:bootstrap-scheduling
        {--from=2026-10-19 : Ngày đầu tuần lịch mẫu}
        {--to=2026-10-25 : Ngày cuối tuần lịch mẫu}
        {--dry-run : Chỉ in kết quả, không ghi database}';

    protected $description = 'Gợi ý năng lực nhân viên và định biên từ lịch đã xếp (chưa xác nhận)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $from = Carbon::parse($this->option('from'))->startOfDay();
        $to = Carbon::parse($this->option('to'))->startOfDay();
        $label = sprintf('Gợi ý từ lịch %s–%s', $from->format('d/m'), $to->format('d/m/Y'));

        DB::beginTransaction();

        try {
            $outlets = $this->ensureRoomOutlet($dry);
            $this->normalizeOutlets($outlets, $dry);
            $this->suggestSkills($outlets, $label, $dry);
            $this->suggestRequirements($outlets, $from, $to, $label, $dry);

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dry ? 'Chạy thử xong – chưa ghi gì vào database.' : 'Đã ghi dữ liệu gợi ý (chưa xác nhận).');

        return self::SUCCESS;
    }

    private function ensureRoomOutlet(bool $dry): array
    {
        if (! Outlet::query()->where('code', 'ROOM')->exists()) {
            Outlet::query()->create(['name' => 'Room Service', 'code' => 'ROOM', 'order' => 4, 'status' => 'published']);
            $this->line('+ Tạo outlet ROOM (Room Service)');
        }

        return Outlet::query()->pluck('id', 'code')->all();
    }

    /**
     * Ca (RS)/(RS1) tính cho ROOM; ca có outlet cố định (N → NIGHT) tính cho outlet đó.
     */
    private function normalizeOutlets(array $outlets, bool $dry): void
    {
        $shiftOutlet = config('plugins.restaurant-roster.general.scheduling.shift_outlet', []);
        $templates = ShiftTemplate::query()->pluck('code', 'id');
        $moved = 0;

        foreach (Shift::query()->where('kind', Shift::KIND_SHIFT)->get() as $shift) {
            $tags = $shift->tags ?: [];
            $target = match (true) {
                (bool) array_intersect($tags, ['RS', 'RS1']) => $outlets['ROOM'] ?? null,
                isset($shiftOutlet[$templates[$shift->shift_template_id] ?? '']) => $outlets[$shiftOutlet[$templates[$shift->shift_template_id]]] ?? null,
                default => null,
            };

            if ($target && (int) $shift->outlet_id !== (int) $target) {
                ChangeLog::query()->create([
                    'employee_id' => $shift->employee_id,
                    'date' => $shift->date,
                    'before' => ['outlet_id' => $shift->outlet_id],
                    'after' => ['outlet_id' => $target],
                    'action' => 'manual',
                    'reason' => 'Chuẩn hóa outlet tính công (RS → ROOM, N → NIGHT)',
                ]);
                $shift->outlet_id = $target;
                $shift->save();
                $moved++;
            }
        }

        $this->line("~ Chuẩn hóa outlet cho {$moved} ca (RS → ROOM, N → NIGHT)");
    }

    private function suggestSkills(array $outlets, string $label, bool $dry): void
    {
        $withSkills = EmployeeSkill::query()->distinct()->pluck('employee_id')->flip();
        $stats = Shift::query()
            ->where('kind', Shift::KIND_SHIFT)
            ->whereNotNull('shift_template_id')
            ->with('employee:id,outlet_id,name')
            ->get()
            ->groupBy(fn (Shift $s) => $s->employee_id . '|' . $s->outlet_id . '|' . $s->shift_template_id);

        $created = 0;
        $skipped = [];

        foreach ($stats as $key => $shifts) {
            [$employeeId, $outletId, $templateId] = array_map('intval', explode('|', $key));
            $employee = $shifts->first()->employee;

            if (! $employee || isset($withSkills[$employeeId])) {
                $skipped[$employeeId] = true;

                continue;
            }

            $count = $shifts->count();
            $home = (int) $employee->outlet_id === $outletId;
            // Tại outlet chính: làm >= 2 lần = chuyên trách; ngoài outlet chính hoặc thỉnh thoảng = đủ điều kiện thay thế.
            // Ca outlet cố định (N tại NIGHT, Room): làm >= 3 lần/tuần = chuyên trách.
            $primary = ($home && $count >= 2) || (! $home && in_array($outletId, [$outlets['NIGHT'] ?? 0, $outlets['ROOM'] ?? 0], true) && $count >= 3);

            EmployeeSkill::query()->create([
                'employee_id' => $employeeId,
                'outlet_id' => $outletId,
                'shift_template_id' => $templateId,
                'level' => $primary ? 'primary' : 'eligible',
                'note' => "{$label}: đã làm {$count} ca",
            ]);
            $created++;
        }

        $this->line("+ Gợi ý {$created} dòng năng lực" . ($skipped ? ' (bỏ qua ' . count($skipped) . ' nhân viên đã có cấu hình)' : ''));
    }

    private function suggestRequirements(array $outlets, Carbon $from, Carbon $to, string $label, bool $dry): void
    {
        if (StaffingRequirement::query()->exists()) {
            $this->line('= Đã có định biên – không tạo gợi ý mới.');

            return;
        }

        $counts = Shift::query()
            ->where('kind', Shift::KIND_SHIFT)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn (Shift $s) => $s->outlet_id . '|' . $s->shift_template_id)
            ->map(fn ($group) => $group->groupBy(fn (Shift $s) => $s->date->dayOfWeekIso)->map->count());

        $templates = ShiftTemplate::query()->pluck('code', 'id');
        $codes = array_flip($outlets);
        $created = 0;

        foreach ($counts as $key => $byWeekday) {
            [$outletId, $templateId] = array_map('intval', explode('|', $key));
            $outletCode = $codes[$outletId] ?? '';
            $shiftCode = $templates[$templateId] ?? '';
            $min = (int) $byWeekday->min();
            [$minMale, $targetMale] = $this->maleRule($outletCode, $shiftCode, false);

            foreach (range(1, 7) as $weekday) {
                $target = (int) ($byWeekday[$weekday] ?? 0);

                StaffingRequirement::query()->create([
                    'outlet_id' => $outletId,
                    'shift_template_id' => $templateId,
                    'weekday' => $weekday,
                    'upper_floor' => $outletCode === 'VILLA' ? false : null,
                    'min_staff' => min($min, $target),
                    'target_staff' => $target,
                    'min_male' => min($minMale, $target),
                    'target_male' => min($targetMale, max($target, $minMale)),
                    'allow_reduce' => true,
                    'is_confirmed' => false,
                    'source' => 'history',
                    'note' => "{$label}: thực tế {$target} người; tối thiểu = ngày ít nhất trong tuần",
                ]);
                $created++;

                if ($outletCode === 'VILLA') {
                    [$floorMinMale, $floorTargetMale] = $this->maleRule($outletCode, $shiftCode, true);
                    StaffingRequirement::query()->create([
                        'outlet_id' => $outletId,
                        'shift_template_id' => $templateId,
                        'weekday' => $weekday,
                        'upper_floor' => true,
                        'min_staff' => min($min, $target),
                        'target_staff' => $target,
                        'min_male' => min($floorMinMale, max($target, 1)),
                        'target_male' => min($floorTargetMale, max($target, 1)),
                        'allow_reduce' => true,
                        'is_confirmed' => false,
                        'source' => 'history',
                        'note' => "{$label}: khi mở tầng trên – cần quản lý nhập lại số người",
                    ]);
                    $created++;
                }
            }

            $this->line(sprintf('  %-6s %-3s  T2–CN: %s  (tối thiểu %d, nam %d/%d)', $outletCode, $shiftCode,
                implode(' ', array_map(fn ($d) => (int) ($byWeekday[$d] ?? 0), range(1, 7))), $min, $minMale, $targetMale));
        }

        $this->line("+ Gợi ý {$created} dòng định biên (chưa xác nhận)");
    }

    /**
     * Quy tắc số nam do quản lý mô tả (12/10/2026). Áp dụng cho ca sáng/chiều chính của outlet.
     *
     * @return array{0: int, 1: int} [tối thiểu, mục tiêu]
     */
    private function maleRule(string $outlet, string $shift, bool $upperFloor): array
    {
        if (! in_array($shift, ['M6', 'A2'], true)) {
            return [0, 0];
        }

        return match ($outlet) {
            'GUSTO' => [1, 2],
            'VILLA' => $upperFloor ? [2, 2] : [1, 2],
            'ROOM' => [1, 1],
            default => [0, 0],
        };
    }
}
