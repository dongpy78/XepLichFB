<?php

namespace Botble\RestaurantRoster\Services;

use Botble\RestaurantRoster\Models\ChangeLog;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\EmployeeSkill;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Luân chuyển Waiter giữa hai outlet: mọi Waiter của outlet A sang B và ngược lại, cùng lúc.
 * Chỉ vị trí Waiter; các vị trí khác giữ nguyên. Năng lực (outlet được phép làm) đổi theo
 * để thuật toán xếp được ngay ở outlet mới. Lịch đã xếp không bị thay đổi.
 */
class OutletRotationService
{
    public const POSITION = 'Waiter';

    /**
     * @return array{a: int, b: int, a_code: string, b_code: string}
     */
    public function preview(string $codeA = 'GUSTO', string $codeB = 'VILLA'): array
    {
        [$a, $b, $positionId] = $this->resolve($codeA, $codeB);

        return [
            'a' => Employee::query()->where('position_id', $positionId)->where('outlet_id', $a->getKey())->count(),
            'b' => Employee::query()->where('position_id', $positionId)->where('outlet_id', $b->getKey())->count(),
            'a_code' => $a->code,
            'b_code' => $b->code,
        ];
    }

    /**
     * @return array{a_to_b: int, b_to_a: int}
     */
    public function swapWaiters(?int $userId, string $codeA = 'GUSTO', string $codeB = 'VILLA'): array
    {
        [$a, $b, $positionId] = $this->resolve($codeA, $codeB);
        $swap = [$a->getKey() => $b->getKey(), $b->getKey() => $a->getKey()];
        $counts = ['a_to_b' => 0, 'b_to_a' => 0];

        DB::transaction(function () use ($positionId, $swap, $a, $b, $userId, &$counts): void {
            $employees = Employee::query()
                ->where('position_id', $positionId)
                ->whereIn('outlet_id', array_keys($swap))
                ->lockForUpdate()
                ->get();

            $now = now();
            $logs = [];

            foreach ($employees as $employee) {
                $from = (int) $employee->outlet_id;
                $to = $swap[$from];
                $counts[$from === $a->getKey() ? 'a_to_b' : 'b_to_a']++;

                $employee->update(['outlet_id' => $to]);

                $logs[] = [
                    'employee_id' => $employee->getKey(),
                    'date' => $now->toDateString(),
                    'before' => json_encode(['outlet_id' => $from]),
                    'after' => json_encode(['outlet_id' => $to]),
                    'action' => 'outlet_swap',
                    'reason' => sprintf('Hoán đổi Waiter %s ⇄ %s', $a->code, $b->code),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Năng lực: đổi outlet A ⇄ B trên các dòng của những Waiter này (xóa rồi tạo lại để tránh trùng khóa).
            $skills = EmployeeSkill::query()
                ->whereIn('employee_id', $employees->modelKeys())
                ->whereIn('outlet_id', array_keys($swap))
                ->get();

            EmployeeSkill::query()->whereKey($skills->modelKeys())->delete();

            foreach ($skills as $skill) {
                EmployeeSkill::query()->create([
                    'employee_id' => $skill->employee_id,
                    'outlet_id' => $swap[(int) $skill->outlet_id],
                    'shift_template_id' => $skill->shift_template_id,
                    'level' => $skill->level,
                    'is_fixed' => $skill->is_fixed,
                    'note' => $skill->note,
                ]);
            }

            if ($logs) {
                ChangeLog::query()->insert($logs);
            }
        });

        return $counts;
    }

    /**
     * @return array{0: Outlet, 1: Outlet, 2: int}
     */
    private function resolve(string $codeA, string $codeB): array
    {
        $a = Outlet::query()->where('code', $codeA)->first();
        $b = Outlet::query()->where('code', $codeB)->first();
        $positionId = Position::query()->where('name', self::POSITION)->value('id');

        if (! $a || ! $b) {
            throw new RuntimeException("Không tìm thấy outlet {$codeA} hoặc {$codeB}.");
        }

        if (! $positionId) {
            throw new RuntimeException('Không tìm thấy vị trí "' . self::POSITION . '".');
        }

        return [$a, $b, (int) $positionId];
    }
}
