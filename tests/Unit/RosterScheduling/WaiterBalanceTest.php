<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\FixedAssignment;
use Botble\RestaurantRoster\Scheduling\Requirement;
use Botble\RestaurantRoster\Scheduling\SolveResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Waiter (nam + nữ) chia đều M6 / A2 từng outlet × ngày, tính riêng Villa và Gusto (quản lý 28/10/2026) – DỮ LIỆU GIẢ LẬP.
 * Chẵn → bằng nhau, lẻ → lệch 1; người OFF / Night / Lobby (L) / ô khóa không được tính là người khả dụng.
 */
class WaiterBalanceTest extends TestCase
{
    use SyntheticRoster;

    /** Kiểm tra một ngày: nhân viên 0 ngày OFF trong phạm vi một ngày → ai khả dụng cũng phải đi làm (thuật toán chỉ chọn M6 / A2). */
    private function waiter(int $id, int $home, string $gender, array $extra = [], ?string $role = null, int $daysOff = 0): EmployeeSpec
    {
        return new EmployeeSpec($id, "Waiter #{$id}", $gender, $home, 48, $daysOff, [$home . ':' . self::M6 => 'primary', $home . ':' . self::A2 => 'primary'] + $extra, [], 'cam_ranh', self::WAITER,
            isFemaleStaff: $gender === 'female', isMaleStaff: $gender === 'male', nightRole: $role);
    }

    /** Chỗ mở M6 / A2 (chế độ balance: không định biên nhà hàng). */
    private function open(string $day, int ...$outlets): array
    {
        $slots = [];

        foreach ($outlets as $o) {
            $slots[] = new Requirement($day, $o, self::M6, 0, 0, 30);
            $slots[] = new Requirement($day, $o, self::A2, 0, 0, 30);
        }

        return $slots;
    }

    /** @return array{0: int, 1: int} số Waiter làm trực tiếp M6 / A2 tại outlet */
    private function split(SolveResult $result, string $day, int $outlet): array
    {
        $count = fn (int $shift) => count(array_filter($result->assignments, fn ($r) => $r['date'] === $day && $r['kind'] === 'shift'
            && $r['outlet_id'] === $outlet && $r['shift_id'] === $shift && ! $r['support']));

        return [$count(self::M6), $count(self::A2)];
    }

    private function solveDay(array $people, array $requirements, array $options = []): SolveResult
    {
        $day = $this->day(2);

        return $this->solve($this->problem($people, $requirements, ['days' => [$day], 'time_limit' => 30, 'max_iterations' => 20000] + $options));
    }

    /**
     * Lỗi gốc: cân bằng riêng nữ (2/1) và riêng nam (1/0) cùng lệch về M6 → tổng 3/1. Nay tổng Waiter 4 người → 2/2.
     * Đồng thời Gusto 3 người → 2–1, tính riêng, không gộp với Villa và không điều chuyển người để "làm đẹp" tỉ lệ.
     */
    public function test_villa_four_and_gusto_three_balanced_independently(): void
    {
        $day = $this->day(2);
        $people = [
            $this->waiter(1, self::VILLA, 'female'), $this->waiter(2, self::VILLA, 'female'), $this->waiter(3, self::VILLA, 'female'), $this->waiter(4, self::VILLA, 'male'),
            $this->waiter(11, self::GUSTO, 'female'), $this->waiter(12, self::GUSTO, 'male'), $this->waiter(13, self::GUSTO, 'male'),
        ];
        $result = $this->solveDay($people, $this->open($day, self::VILLA, self::GUSTO));

        $this->assertSame([2, 2], $this->split($result, $day, self::VILLA), 'Villa 4 Waiter → 2 M6 / 2 A2');
        $gusto = $this->split($result, $day, self::GUSTO);
        $this->assertSame(3, array_sum($gusto));
        $this->assertLessThanOrEqual(1, abs($gusto[0] - $gusto[1]), 'Gusto 3 Waiter → 2–1: ' . json_encode($gusto));
        $this->assertSame([], array_filter($result->assignments, fn ($r) => $r['off_home_outlet']), 'Không điều chuyển Villa ↔ Gusto để cân bằng');
        $this->assertSame([], array_filter($result->warnings, fn ($w) => $w['code'] === 'waiter_balance'));
    }

    /** 2 Waiter → 1 / 1. */
    public function test_two_waiters_split_one_one(): void
    {
        $day = $this->day(2);
        $result = $this->solveDay([$this->waiter(1, self::VILLA, 'female'), $this->waiter(2, self::VILLA, 'female')], $this->open($day, self::VILLA));

        $this->assertSame([1, 1], $this->split($result, $day, self::VILLA));
    }

    /** 5 Waiter, 1 người OFF đã duyệt → 4 người khả dụng → 2 / 2. */
    public function test_off_staff_not_counted(): void
    {
        $day = $this->day(2);
        $people = array_map(fn ($id) => $this->waiter($id, self::VILLA, $id % 2 ? 'female' : 'male'), range(1, 5));
        $result = $this->solveDay($people, $this->open($day, self::VILLA), ['hard' => [3 => [$day => 'Xin OFF']]]);

        $this->assertNull($this->shiftOf($result, 3, $day));
        $this->assertSame([2, 2], $this->split($result, $day, self::VILLA));
    }

    /** 5 Waiter, 1 người làm Night (ô đã khóa) → không tính vào M6 / A2 → 2 / 2. */
    public function test_night_staff_not_counted(): void
    {
        $day = $this->day(2);
        $people = [...array_map(fn ($id) => $this->waiter($id, self::VILLA, $id % 2 ? 'female' : 'male'), range(1, 4)),
            $this->waiter(5, self::VILLA, 'male', [self::NIGHT . ':' . self::N => 'eligible'], EmployeeSpec::NIGHT_ROTATION)];
        $requirements = [...$this->open($day, self::VILLA), new Requirement($day, self::NIGHT, self::N, 1, 1, 1)];
        $result = $this->solveDay($people, $requirements, ['fixed' => [$this->locked(5, $day, self::NIGHT, self::N)]]);

        $this->assertSame([self::NIGHT, self::N], $this->shiftOf($result, 5, $day));
        $this->assertSame([2, 2], $this->split($result, $day, self::VILLA));
    }

    /** 5 Waiter, 1 nữ đang hỗ trợ Lobby M6(L) (ô khóa) → người đó không tính trùng → 4 người còn lại 2 / 2. */
    public function test_lobby_support_not_double_counted(): void
    {
        $day = $this->day(2);
        $people = array_map(fn ($id) => $this->waiter($id, self::VILLA, $id % 2 ? 'female' : 'male', [self::LOBBY . ':0' => 'eligible']), range(1, 5));
        $support = new FixedAssignment(1, $day, FixedAssignment::KIND_SHIFT, self::VILLA, self::M6, support: true);
        $result = $this->solveDay($people, $this->open($day, self::VILLA), ['fixed' => [$support]]);

        $this->assertSame([2, 2], $this->split($result, $day, self::VILLA), 'Không tính người đi Lobby (L)');
    }

    /** Ô khóa không bị ghi đè: 3 người đã khóa M6 → không cân bằng được, có cảnh báo nêu ô khóa. */
    public function test_locked_cells_kept_and_imbalance_explained(): void
    {
        $day = $this->day(2);
        $people = array_map(fn ($id) => $this->waiter($id, self::VILLA, 'female'), range(1, 4));
        $fixed = array_map(fn ($id) => $this->locked($id, $day, self::VILLA, self::M6), [1, 2, 3]);
        $result = $this->solveDay($people, $this->open($day, self::VILLA), ['fixed' => $fixed]);

        foreach ([1, 2, 3] as $id) {
            $this->assertSame([self::VILLA, self::M6], $this->shiftOf($result, $id, $day), "#{$id}: ô khóa giữ nguyên");
        }

        $this->assertSame([3, 1], $this->split($result, $day, self::VILLA), 'Người còn lại vào A2 để lệch ít nhất');
        $warning = array_values(array_filter($result->warnings, fn ($w) => $w['code'] === 'waiter_balance'))[0] ?? null;
        $this->assertNotNull($warning, 'Cảnh báo lệch M6 / A2');
        $this->assertStringContainsString('Ô đã khóa', $warning['message']);

        $check = collect($result->supportReport['day_checks'])->firstWhere('date', $day);
        $this->assertSame('warning', $check['status']);
        $this->assertFalse($check['checks']['waiter']['ok']);
        $this->assertTrue($check['checks']['hard']['ok']);
    }

    /** Cả tuần, nhiều người: mọi ngày cả Villa và Gusto đều cân bằng; bảng kiểm tra từng ngày "Đạt". */
    public function test_full_week_each_day_balanced_and_checked(): void
    {
        $days = $this->days();
        $people = [
            ...array_map(fn ($id) => $this->waiter($id, self::VILLA, $id % 3 ? 'female' : 'male', daysOff: 2), range(1, 10)),
            ...array_map(fn ($id) => $this->waiter($id, self::GUSTO, $id % 2 ? 'female' : 'male', daysOff: 2), range(21, 27)),
        ];
        $requirements = array_merge(...array_map(fn ($d) => $this->open($d, self::VILLA, self::GUSTO), $days));
        $problem = $this->problem($people, $requirements, ['busy_weekdays' => [], 'time_limit' => 60, 'max_iterations' => 40000]);
        $result = $this->solve($problem);

        foreach ($days as $day) {
            foreach ([self::VILLA, self::GUSTO] as $outlet) {
                [$am, $pm] = $this->split($result, $day, $outlet);
                $this->assertSame(($am + $pm) % 2, abs($am - $pm), "{$day} outlet {$outlet}: {$am}/{$pm}");
            }
        }

        $this->assertSame(['ok'], array_values(array_unique(array_column($result->supportReport['day_checks'], 'status'))));
        $this->assertHardConstraintsHold($problem, $result);
    }
}
