<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Thống kê nhân sự dùng chung cho Bảng xếp ca và trang Thống kê nhân sự:
 * cơ cấu nhân viên (nam/nữ, quản lý, nơi ở, ca cứng, ngày OFF) và số người đi làm theo ngày × outlet × ca,
 * đối chiếu quy định mỗi ca (có nam, Hostess, quản lý – config scheduling.staffing_rules).
 */
class RosterStatistics
{
    private ?array $rules = null;

    /**
     * @return array{leaderIds: int[], hostessIds: int[], checks: array<string, bool>}
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $ids = Position::query()->pluck('id', 'name');
        // Cấp quản lý theo cờ "Cấp quản lý" của vị trí (Junior Restaurant Captain trở lên).
        $leaderIds = Position::query()->where('is_manager', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $hostessIds = [];
        $checks = [];

        foreach (config('plugins.restaurant-roster.general.scheduling.staffing_rules', []) as $rule) {
            $names = (array) ($rule['positions'] ?? $rule['position'] ?? []);
            $groupIds = $names ? $ids->only($names)->values()->map(fn ($id) => (int) $id)->all() : [];

            if ($names && empty($rule['managers'])) {
                $hostessIds = $groupIds;
            }

            foreach ($rule['outlets'] ?? [] as $outlet) {
                foreach ($rule['shifts'] ?? [] as $shift) {
                    $checks["{$outlet}|{$shift}"] = true;
                }
            }
        }

        return $this->rules = ['leaderIds' => $leaderIds, 'hostessIds' => $hostessIds, 'checks' => $checks];
    }

    public function summarize(Collection $employees): array
    {
        $leaderIds = $this->rules()['leaderIds'];
        $leaders = $employees->filter(fn ($e) => in_array((int) $e->position_id, $leaderIds, true));

        return [
            'total' => $employees->count(),
            'male' => $employees->where('gender', 'male')->count(),
            'female' => $employees->where('gender', 'female')->count(),
            'no_gender' => $employees->whereNull('gender')->count(),
            'leaders' => $leaders->count(),
            'leaders_male' => $leaders->where('gender', 'male')->count(),
            'nha_trang' => $employees->where('residence', 'nha_trang')->count(),
            'cam_ranh' => $employees->where('residence', 'cam_ranh')->count(),
            'no_residence' => $employees->whereNull('residence')->count(),
            'fixed' => $employees->filter(fn ($e) => $e->fixedShift)->groupBy(fn ($e) => $e->fixedShift->code)->map->count()->all(),
            'days_off' => $employees->groupBy('days_off_per_week')->map->count()->sortKeys()->all(),
            'manual' => $employees->where('auto_schedule', false)->count(),
        ];
    }

    /**
     * Người đi làm theo ngày × outlet làm việc × ca trong tuần (người đi Lobby (L) tính riêng),
     * kèm số OFF / nghỉ phép theo outlet chính và các ô vi phạm quy định.
     */
    public function onDuty(Carbon $weekStart, Collection $outlets, ?Collection $shifts = null): array
    {
        $rules = $this->rules();
        $end = $weekStart->copy()->addDays(6);
        $shifts ??= Shift::query()
            ->whereBetween('date', [$weekStart->toDateString(), $end->toDateString()])
            ->with(['employee:id,gender,position_id,outlet_id', 'template:id,code,start_time,support_station'])
            ->get();

        $days = [];

        foreach (CarbonPeriod::create($weekStart, $end) as $date) {
            $days[$date->toDateString()] = $date->copy();
        }

        $codes = [];
        $cells = [];
        $absent = [];
        $managerSplit = [];

        foreach ($shifts as $shift) {
            $date = $shift->date->toDateString();
            $employee = $shift->employee;

            if (! $employee || ! isset($days[$date])) {
                continue;
            }

            if (! $shift->isWorking()) {
                $kind = $shift->kind === Shift::KIND_LEAVE ? 'leave' : 'off';
                $absent[$date][$employee->outlet_id][$kind] = ($absent[$date][$employee->outlet_id][$kind] ?? 0) + 1;

                continue;
            }

            $code = $shift->template?->code ?? 'Ca';
            $codes[$shift->outlet_id][$code] = $shift->template?->start_time ?? '99';
            $support = in_array('L', $shift->tags ?: [], true) && ! $shift->template?->support_station;
            $cell = &$cells[$date][$shift->outlet_id][$code];
            $cell ??= ['total' => 0, 'male' => 0, 'female' => 0, 'leaders' => 0, 'hostess' => 0, 'lobby' => 0];

            if ($support) {
                $cell['lobby']++;
            } else {
                $cell['total']++;
                $cell[$employee->gender === 'male' ? 'male' : 'female']++;
                $cell['leaders'] += in_array((int) $employee->position_id, $rules['leaderIds'], true) ? 1 : 0;

                if (in_array((int) $employee->position_id, $rules['leaderIds'], true) && $shift->template?->start_time) {
                    $hour = (int) substr((string) $shift->template->start_time, 0, 2);
                    $half = $hour >= 4 && $hour < 12 ? 'morning' : ($hour >= 12 && $hour < 20 ? 'afternoon' : null);

                    if ($half) {
                        $managerSplit[$date][$shift->outlet_id][$half] = ($managerSplit[$date][$shift->outlet_id][$half] ?? 0) + 1;
                    }
                }
                $cell['hostess'] += in_array((int) $employee->position_id, $rules['hostessIds'], true) ? 1 : 0;
            }

            unset($cell);
        }

        // Mỗi outlet chỉ hiện các ca có người làm trong tuần, theo giờ bắt đầu; ca thuộc quy định luôn hiện.
        foreach ($outlets as $outlet) {
            foreach (array_keys($rules['checks']) as $check) {
                [$outletCode, $shiftCode] = explode('|', $check);

                if ($outletCode === $outlet->code && ! isset($codes[$outlet->id][$shiftCode])) {
                    $codes[$outlet->id][$shiftCode] = '00';
                }
            }
        }

        $codes = array_map(function (array $list) {
            asort($list);

            return array_keys($list);
        }, $codes);

        $problems = [];

        // Cấp quản lý: số quản lý ca sáng / chiều lệch quá mức không tránh được (vd 4 sáng / 1 chiều) → báo ở các ca của outlet hôm đó.
        foreach ($managerSplit as $date => $byOutlet) {
            foreach ($byOutlet as $outletId => $split) {
                [$morning, $afternoon] = [$split['morning'] ?? 0, $split['afternoon'] ?? 0];

                if (\Botble\RestaurantRoster\Scheduling\ScheduleState::managerPenalty($morning, $afternoon) > 0) {
                    foreach ($codes[$outletId] ?? [] as $code) {
                        $problems[$date][$outletId][$code][] = "quản lý lệch: sáng {$morning} / chiều {$afternoon}";
                    }
                }
            }
        }

        foreach ($shifts->isEmpty() ? [] : $outlets as $outlet) {
            foreach ($codes[$outlet->id] ?? [] as $code) {
                if (! isset($rules['checks'][$outlet->code . '|' . $code])) {
                    continue;
                }

                foreach (array_keys($days) as $date) {
                    $cell = $cells[$date][$outlet->id][$code] ?? null;
                    $list = $cell ? array_values(array_filter([
                        $cell['male'] ? null : 'không có nam',
                        $cell['hostess'] ? null : 'không có Hostess',
                        $cell['leaders'] ? null : 'không có quản lý',
                    ])) : ['không có ai'];

                    if ($list) {
                        $problems[$date][$outlet->id][$code] = [...$list, ...($problems[$date][$outlet->id][$code] ?? [])];
                    }
                }
            }
        }

        return [
            'days' => $days,
            'codes' => $codes,
            'cells' => $cells,
            'absent' => $absent,
            'problems' => $problems,
            'outlets' => $outlets->filter(fn ($o) => ! empty($codes[$o->id]) || collect($absent)->contains(fn ($day) => isset($day[$o->id])))->values(),
            'empty' => $shifts->isEmpty(),
        ];
    }
}
