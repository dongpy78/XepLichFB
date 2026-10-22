<?php

namespace Tests\Feature\Roster;

use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Lịch sử phân công thực tế (database) dùng cho luân phiên: đếm đúng loại ca hỗ trợ và theo khoảng đánh giá – DỮ LIỆU GIẢ LẬP.
 */
class SupportHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }
    }

    /** Kid-Deline: bất kỳ nữ Waiter của Gusto đứng được (không cần khai báo năng lực); nam / vị trí khác / nữ Villa chưa khai báo thì không. */
    public function test_any_female_gusto_waiter_can_cover_kid_deline(): void
    {
        $gusto = Outlet::query()->create(['name' => 'Gusto', 'code' => 'GUSTO', 'status' => 'published']);
        $villa = Outlet::query()->create(['name' => 'Villa', 'code' => 'VILLA', 'status' => 'published']);
        $kid = Outlet::query()->create(['name' => 'Kid-Deline', 'code' => 'KID', 'status' => 'published']);
        ShiftTemplate::query()->create(['code' => 'M6', 'name' => 'Sáng', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'status' => 'published']);
        $waiter = \Botble\RestaurantRoster\Models\Position::query()->create(['name' => 'Waiter', 'status' => 'published']);
        $hostess = \Botble\RestaurantRoster\Models\Position::query()->create(['name' => 'Hostess', 'status' => 'published']);
        $make = fn (string $code, Outlet $outlet, string $gender, $position) => Employee::query()->create(['code' => $code, 'name' => "Synthetic {$code}", 'gender' => $gender,
            'outlet_id' => $outlet->getKey(), 'position_id' => $position->getKey(), 'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);

        $gustoWaiter = $make('G1', $gusto, 'female', $waiter);
        $gustoMale = $make('G2', $gusto, 'male', $waiter);
        $gustoHostess = $make('G3', $gusto, 'female', $hostess);
        $villaWaiter = $make('V1', $villa, 'female', $waiter);

        $problem = app(ProblemBuilder::class)->build(Carbon::parse('2030-03-04'), Carbon::parse('2030-03-10'));
        $m6 = (int) ShiftTemplate::query()->value('id');
        $level = fn (Employee $e) => $problem->employees[$e->getKey()]->skillLevel($kid->getKey(), $m6);

        $this->assertSame('primary', $level($gustoWaiter));
        $this->assertNull($level($gustoMale));
        $this->assertNull($level($gustoHostess));
        $this->assertNull($level($villaWaiter), 'Nữ Villa chỉ được mượn khi có khai báo năng lực Kid-Deline');
    }

    public function test_history_counts_support_types_within_window(): void
    {
        $gusto = Outlet::query()->create(['name' => 'Gusto', 'code' => 'GUSTO', 'status' => 'published']);
        $kid = Outlet::query()->create(['name' => 'Kid-Deline', 'code' => 'KID', 'status' => 'published']);
        $lobby = Outlet::query()->create(['name' => 'Lobby', 'code' => 'LOBBY', 'status' => 'published']);
        $m6 = ShiftTemplate::query()->create(['code' => 'M6', 'name' => 'Sáng', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'lobby_start_time' => '09:00:00', 'lobby_end_time' => '14:00:00', 'status' => 'published']);
        $a2 = ShiftTemplate::query()->create(['code' => 'A2', 'name' => 'Chiều', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'lobby_start_time' => '14:00:00', 'lobby_end_time' => '17:00:00', 'status' => 'published']);
        // Có ca đêm: lịch sử ca đêm cũng được đọc (lỗi cũ: tên biến trùng làm hỏng phép tính ngày).
        $night = ShiftTemplate::query()->create(['code' => 'N', 'name' => 'Đêm', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'status' => 'published']);
        $m9 = ShiftTemplate::query()->create(['code' => 'M9', 'name' => 'Lobby', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'support_station' => 'LOBBY', 'status' => 'published']);
        $employee = Employee::query()->create(['code' => 'SYN1', 'name' => 'Synthetic 1', 'gender' => 'female', 'outlet_id' => $gusto->getKey(),
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);

        $from = Carbon::parse('2030-03-04');
        $cell = function (int $daysBefore, ShiftTemplate $template, Outlet $outlet, array $tags = []) use ($employee, $from): void {
            $date = $from->copy()->subDays($daysBefore)->toDateString();
            Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $template->getKey(),
                'outlet_id' => $outlet->getKey(), 'tags' => $tags ?: null, 'status' => 'published', 'start_at' => "{$date} 06:00:00", 'end_at' => "{$date} 14:00:00", 'hours' => 8]);
        };

        $cell(1, $a2, $gusto, ['L']);   // A2(L)
        $cell(2, $a2, $gusto, ['L']);   // A2(L)
        $cell(3, $a2, $gusto);          // A2 thường
        $cell(4, $m9, $lobby, ['L']);   // M9(L) làm trọn ở Lobby
        $cell(5, $m6, $kid, ['K']);     // M6(K)
        $cell(10, $night, $gusto);       // ca đêm
        $cell(40, $a2, $gusto, ['L']);  // 40 ngày trước: chỉ tính khi khoảng đánh giá 8 tuần

        $build = fn (int $weeks) => app(ProblemBuilder::class)->build($from, $from->copy()->addDays(6), null, ['history_weeks' => $weeks]);

        $four = $build(4);
        $this->assertSame(1, $four->nightHistory[$employee->getKey()] ?? null, 'Số tuần có ca đêm');
        $this->assertSame(4, $four->historyWeeks);
        // Lượt quy về số tuần đi làm (công bằng cho người nghỉ): 4 tuần chỉ đi làm 2 tuần → lượt × 2 (thô: A2(L) 2, M9(L) 1, M6(K) 1).
        $this->assertEqualsCanonicalizing(['A2(L)' => 4, 'M9(L)' => 2, 'M6(K)' => 2], $four->supportHistory[$employee->getKey()] ?? null);
        $this->assertEquals(['morning' => 2, 'afternoon' => 3], $four->shiftHistory[$employee->getKey()] ?? null);

        $eight = $build(8);
        // 8 tuần: thêm lượt A2(L) 40 ngày trước (thô 3), đi làm 3 / 8 tuần → 3 × 8 / 3 = 8.
        $this->assertSame(8, $eight->supportHistory[$employee->getKey()]['A2(L)'], 'Khoảng 8 tuần tính thêm lượt cũ hơn');
    }
}
