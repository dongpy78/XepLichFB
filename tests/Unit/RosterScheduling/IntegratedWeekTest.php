<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\Coverage;
use Botble\RestaurantRoster\Scheduling\EmployeeSpec;
use Botble\RestaurantRoster\Scheduling\Requirement;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Tuần đầy đủ giống vận hành (DỮ LIỆU GIẢ LẬP – không phải nhân viên thật): Villa + Gusto (quản lý, Hostess, Waiter nam / nữ),
 * Night cứng + luân phiên với nhu cầu Night 2 / 3 theo ngày, Lobby 2 nữ cùng lúc 09:00–16:45 (OH(L) Nha Trang / M9(L) Cam Ranh).
 * Kiểm tra từng ngày: Night đúng nhu cầu, Lobby đủ, Waiter M6 / A2 cân bằng riêng từng outlet, OFF rải đều, không vi phạm điều kiện cứng.
 */
class IntegratedWeekTest extends TestCase
{
    use SyntheticRoster;

    private const HOSTESS = 101;

    private function staff(int $id, int $home, string $gender, int $position = self::WAITER, array $extra = [], ?string $role = null, ?string $residence = 'cam_ranh', bool $manager = false): EmployeeSpec
    {
        return new EmployeeSpec($id, "Synthetic #{$id}", $gender, $home, 48, 2, [$home . ':' . self::M6 => 'primary', $home . ':' . self::A2 => 'primary'] + $extra, [], $residence, $position,
            isManager: $manager, managerShiftIds: $manager ? [self::M6, self::A2] : null,
            isFemaleStaff: $gender === 'female' && $position === self::WAITER && ! $manager, isMaleStaff: $gender === 'male' && $position === self::WAITER && ! $manager, nightRole: $role);
    }

    public function test_week_checked_day_by_day(): void
    {
        $days = $this->days();
        $nightNeed = [3, 2, 2, 2, 3, 3, 2];
        $lobby = [self::LOBBY . ':0' => 'eligible'];
        $night = [self::NIGHT . ':' . self::N => 'eligible'];
        $people = [
            // Night cứng
            new EmployeeSpec(1, 'Synthetic #1', 'male', self::NIGHT, 48, 2, [self::NIGHT . ':' . self::N => 'primary'], [self::NIGHT . ':' . self::N], 'cam_ranh', self::WAITER, isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_CORE),
            new EmployeeSpec(2, 'Synthetic #2', 'male', self::NIGHT, 48, 2, [self::NIGHT . ':' . self::N => 'primary'], [self::NIGHT . ':' . self::N], 'cam_ranh', self::WAITER, isMaleStaff: true, nightRole: EmployeeSpec::NIGHT_CORE),
            // Villa: 2 quản lý, 2 Hostess, 4 nữ Waiter Nha Trang (OH(L)), 4 nam Waiter (2 luân phiên Night)
            $this->staff(10, self::VILLA, 'male', 200, manager: true), $this->staff(11, self::VILLA, 'female', 200, manager: true),
            $this->staff(12, self::VILLA, 'female', self::HOSTESS), $this->staff(13, self::VILLA, 'female', self::HOSTESS),
            ...array_map(fn ($id) => $this->staff($id, self::VILLA, 'female', extra: [self::VILLA . ':' . self::OH => 'primary'] + $lobby, residence: 'nha_trang'), range(14, 17)),
            $this->staff(18, self::VILLA, 'male', extra: $night, role: EmployeeSpec::NIGHT_ROTATION), $this->staff(19, self::VILLA, 'male', extra: $night, role: EmployeeSpec::NIGHT_ROTATION),
            $this->staff(20, self::VILLA, 'male'), $this->staff(21, self::VILLA, 'male'), $this->staff(22, self::VILLA, 'female', extra: [self::VILLA . ':' . self::OH => 'primary'] + $lobby, residence: 'nha_trang'),
            // Gusto: 2 quản lý, 2 Hostess, 4 nữ Waiter Cam Ranh (M9(L)), 4 nam Waiter (2 luân phiên Night)
            $this->staff(30, self::GUSTO, 'male', 200, manager: true), $this->staff(31, self::GUSTO, 'male', 200, manager: true),
            $this->staff(32, self::GUSTO, 'female', self::HOSTESS), $this->staff(33, self::GUSTO, 'female', self::HOSTESS),
            ...array_map(fn ($id) => $this->staff($id, self::GUSTO, 'female', extra: [self::LOBBY . ':' . self::M9 => 'primary'] + $lobby), range(34, 37)),
            $this->staff(38, self::GUSTO, 'male', extra: $night, role: EmployeeSpec::NIGHT_ROTATION), $this->staff(39, self::GUSTO, 'male', extra: $night, role: EmployeeSpec::NIGHT_ROTATION),
            $this->staff(40, self::GUSTO, 'male'), $this->staff(41, self::GUSTO, 'male'), $this->staff(42, self::GUSTO, 'female', extra: [self::LOBBY . ':' . self::M9 => 'primary'] + $lobby),
        ];
        $requirements = [];

        foreach ($days as $k => $d) {
            foreach ([self::VILLA, self::GUSTO] as $o) {
                // Như ProblemBuilder::applyGenderPresence: mỗi ca mở mục tiêu ≥ 1 nam, ≥ 1 nữ waiter.
                $requirements[] = new Requirement($d, $o, self::M6, 0, 0, 30, 0, 1, true, [], 0, 1);
                $requirements[] = new Requirement($d, $o, self::A2, 0, 0, 30, 0, 1, true, [], 0, 1);
            }

            $requirements[] = new Requirement($d, self::VILLA, self::OH, 0, 0, 2);
            $requirements[] = new Requirement($d, self::LOBBY, self::M9, 0, 0, 2);
            $requirements[] = new Requirement($d, self::NIGHT, self::N, $nightNeed[$k], $nightNeed[$k], $nightNeed[$k]);
        }

        $problem = $this->problem($people, $requirements, [
            'coverage' => array_map(fn ($d) => new Coverage($d, 540, 1005, 2, 2, 'Lobby', null, true), $days),
            'lobby_preferred' => array_fill_keys([...range(14, 17), 22], self::OH) + array_fill_keys([...range(34, 37), 42], self::M9),
            'night_plan' => array_fill_keys($days, [18, 19, 38, 39]),
            'busy_weekdays' => [], 'max_support' => 2, 'time_limit' => 20, 'max_iterations' => 400000,
        ]);
        $result = $this->solve($problem);
        $checks = collect($result->supportReport['day_checks'])->keyBy('date');

        foreach ($days as $k => $d) {
            $nights = count(array_filter($result->assignments, fn ($r) => $r['date'] === $d && $r['shift_id'] === self::N));
            $this->assertSame($nightNeed[$k], $nights, "{$d}: Night đúng nhu cầu");
            $this->assertTrue($checks[$d]['checks']['lobby']['ok'], "{$d}: " . $checks[$d]['checks']['lobby']['text']);
            $this->assertTrue($checks[$d]['checks']['hard']['ok'], "{$d}: điều kiện cứng");
            $this->assertTrue($checks[$d]['checks']['waiter']['ok'], "{$d}: Waiter M6 / A2 " . $checks[$d]['checks']['waiter']['text']);

            // Mỗi ca M6 / A2 của từng outlet có ít nhất 1 nam và 1 nữ waiter làm trực tiếp.
            foreach ([self::VILLA, self::GUSTO] as $o) {
                foreach ([self::M6, self::A2] as $s) {
                    $here = array_filter($result->assignments, fn ($r) => $r['date'] === $d && $r['kind'] === 'shift' && $r['outlet_id'] === $o && $r['shift_id'] === $s && ! $r['support']);
                    $genders = array_map(fn ($r) => $problem->employees[$r['employee_id']], $here);
                    $this->assertNotEmpty(array_filter($genders, fn ($e) => $e->isMaleStaff), "{$d} outlet {$o} ca {$s}: có nam waiter");
                    $this->assertNotEmpty(array_filter($genders, fn ($e) => $e->isFemaleStaff), "{$d} outlet {$o} ca {$s}: có nữ waiter");
                }
            }
        }

        $this->assertHardConstraintsHold($problem, $result);
    }
}
