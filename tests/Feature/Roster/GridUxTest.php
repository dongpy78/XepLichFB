<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Bảng xếp ca gọn & thao tác nhanh (01/11/2026) – DỮ LIỆU GIẢ LẬP: HTML gọn (không lặp tên / SVG ở từng ô), thông tin lọc trên hàng,
 * bảng cảnh báo bấm tới đúng ô, hoàn tác lần sửa ô gần nhất (không ghi đè thay đổi mới hơn), thanh lọc trên trang.
 */
class GridUxTest extends TestCase
{
    private const WEEK = '2030-03-04';

    /** @var array<string, Outlet> */
    private array $outlets = [];

    /** @var array<string, ShiftTemplate> */
    private array $templates = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(RestaurantRosterServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
        $this->withoutMiddleware(\Botble\Installer\Http\Middleware\RedirectIfNotInstalledMiddleware::class);
        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate', ['--path' => 'database/migrations', '--force' => true]);
            $this->artisan('migrate', ['--path' => 'platform/core/acl/database/migrations', '--force' => true]);
        }

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }

        foreach (['GUSTO' => 1, 'VILLA' => 2] as $code => $order) {
            $this->outlets[$code] = Outlet::query()->create(['name' => ucfirst(strtolower($code)), 'code' => $code, 'order' => $order, 'status' => 'published']);
        }

        foreach (['M6' => ['06:00:00', '14:00:00'], 'A2' => ['14:00:00', '22:00:00']] as $code => [$start, $end]) {
            $this->templates[$code] = ShiftTemplate::query()->create(['code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end, 'hours' => 8,
                'break_minutes' => 0, 'status' => 'published']);
        }

        $this->user = new User();
        $this->user->forceFill(['email' => uniqid('ux') . '@example.test', 'username' => uniqid('ux'), 'password' => bcrypt('secret'),
            'first_name' => 'Synthetic', 'last_name' => 'Manager', 'super_user' => true, 'permissions' => []])->save();
    }

    private function employee(string $code, string $outlet, string $gender): Employee
    {
        $waiter = Position::query()->firstOrCreate(['name' => 'Waiter'], ['status' => 'published']);

        return Employee::query()->create(['code' => $code, 'name' => "Synthetic {$code}", 'gender' => $gender, 'outlet_id' => $this->outlets[$outlet]->getKey(),
            'position_id' => $waiter->getKey(), 'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);
    }

    private function cell(Employee $employee, string $date, string $code, string $outlet): Shift
    {
        $template = $this->templates[$code];

        return Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date, 'kind' => Shift::KIND_SHIFT, 'shift_template_id' => $template->getKey(),
            'outlet_id' => $this->outlets[$outlet]->getKey(), 'status' => 'draft', 'start_at' => "{$date} {$template->start_time}", 'end_at' => "{$date} {$template->end_time}", 'hours' => 8]);
    }

    private function grid(): string
    {
        return (string) $this->actingAs($this->user)->getJson(route('roster.grid', ['week' => self::WEEK]))->assertJson(['error' => false])->json('data.html');
    }

    public function test_grid_html_is_compact_and_rows_carry_filter_data(): void
    {
        $villa = $this->employee('V1', 'VILLA', 'female');
        $gusto = $this->employee('G1', 'GUSTO', 'male');
        $idle = $this->employee('G2', 'GUSTO', 'female');
        $this->cell($villa, self::WEEK, 'M6', 'GUSTO');
        $this->cell($gusto, self::WEEK, 'A2', 'GUSTO');

        $html = $this->grid();

        $this->assertSame(1, substr_count($html, 'data-employee-name="' . $villa->name . '"'), 'Tên nhân viên chỉ ở <tr>, không lặp ở từng ô');
        $this->assertStringNotContainsString('data-date-label="', str_replace('<th', '', preg_replace('/<th[^>]*>/', '', $html)), 'Nhãn ngày chỉ ở tiêu đề cột');
        $this->assertStringNotContainsString('data-note=""', $html, 'Không ghi thuộc tính rỗng');
        $this->assertStringContainsString('<use href="#roster-i-plus"/>', $html, 'Ô trống dùng biểu tượng chung');
        $this->assertLessThan(3, substr_count($html, 'svg-icon-ti-ti-plus'), 'SVG dấu cộng chỉ vẽ một lần');
        $this->assertDoesNotMatchRegularExpression('/ {3,}/', preg_replace('/"[^"]*"/', '""', $html), 'Không còn khoảng trắng thụt lề');

        $this->assertMatchesRegularExpression('/<tr class="roster-row" data-employee-name="' . preg_quote($villa->name, '/') . '"[^>]*data-gender="female"[^>]*data-codes="M6"[^>]*data-transfer="1"/', $html,
            'Người Villa làm ở Gusto: có cờ điều chuyển');
        $this->assertMatchesRegularExpression('/data-employee-name="' . preg_quote($gusto->name, '/') . '"[^>]*data-codes="A2"(?![^>]*data-transfer)/', $html);
        $this->assertMatchesRegularExpression('/data-employee-name="' . preg_quote($idle->name, '/') . '"[^>]*data-unscheduled="1"/', $html, 'Người chưa có ô lịch nào');

        // Bảng cảnh báo: người chưa có lịch, bấm để tới đúng hàng.
        $this->assertStringContainsString('Cảnh báo cần xử lý', $html);
        $this->assertMatchesRegularExpression('/data-roster-jump data-employee="' . $idle->getKey() . '"/', $html);
        $this->assertStringContainsString('Chưa có ô lịch nào trong tuần', $html);
    }

    public function test_page_has_filter_bar_and_versioned_assets(): void
    {
        $this->employee('G1', 'GUSTO', 'male');

        $page = $this->actingAs($this->user)->get(route('roster.index', ['week' => self::WEEK]))->assertOk();
        $page->assertSee('id="rf-search"', false)->assertSee('id="rf-code"', false)->assertSee('Chưa xếp lịch');
        $page->assertSee('data-assign-undo-url="' . route('roster.assign-undo') . '"', false);
        $this->assertMatchesRegularExpression('/roster\.js\?v=\w+/', $page->getContent(), 'JS có số phiên bản – không cần Ctrl+F5 sau khi cập nhật');
    }

    public function test_undo_last_edit_restores_previous_cell(): void
    {
        $employee = $this->employee('G1', 'GUSTO', 'male');
        $this->cell($employee, self::WEEK, 'M6', 'GUSTO');
        $assign = fn (string $code) => $this->actingAs($this->user)->postJson(route('roster.assign'), ['employee_id' => $employee->getKey(), 'date' => self::WEEK,
            'week' => self::WEEK, 'action' => 'shift', 'shift_template_id' => $this->templates[$code]->getKey()]);

        $assign('A2')->assertJson(['error' => false, 'data' => ['edit_undo' => $employee->name . ' 04/03']]);
        $this->assertSame('A2', Shift::query()->where('employee_id', $employee->getKey())->first()->notation);

        $this->actingAs($this->user)->postJson(route('roster.assign-undo'), ['week' => self::WEEK])->assertJson(['error' => false, 'data' => ['edit_undone' => true]]);
        $this->assertSame(['M6'], Shift::query()->where('employee_id', $employee->getKey())->get()->map->notation->all(), 'Ô trở về M6, không trùng ô');

        $this->actingAs($this->user)->postJson(route('roster.assign-undo'), ['week' => self::WEEK])->assertJson(['error' => true]);
    }

    /** Hai lượt xếp lịch tự động cùng lúc (hai quản lý / hai tab): lượt sau bị từ chối rõ ràng, không chạy song song. */
    public function test_concurrent_auto_run_is_refused(): void
    {
        $this->employee('G1', 'GUSTO', 'male');
        config(['plugins.restaurant-roster.general.scheduling.require_off_confirmation' => false]);
        $lock = \Illuminate\Support\Facades\Cache::lock('roster-auto-run', 180);
        $this->assertTrue($lock->get(), 'Giả lập một lượt đang chạy');

        $response = $this->actingAs($this->user)->postJson(route('roster.auto.run'), ['from' => self::WEEK, 'to' => '2030-03-10', 'type' => 'validate'])
            ->assertJson(['error' => true]);
        $this->assertStringContainsString('Đang có một lượt xếp lịch tự động khác chạy', (string) $response->json('message'));

        $lock->release();
        $response = $this->actingAs($this->user)->postJson(route('roster.auto.run'), ['from' => self::WEEK, 'to' => '2030-03-10', 'type' => 'validate'])
            ->assertJson(['error' => false]);
        $this->assertMatchesRegularExpression('/Đã chạy xong trong [\d,]+ giây/', (string) $response->json('message'));
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock('roster-auto-run', 1)->get(), 'Khóa được nhả sau khi chạy xong');
    }

    public function test_undo_after_clearing_an_empty_cell_and_refuses_when_changed_later(): void
    {
        $employee = $this->employee('G1', 'GUSTO', 'male');
        $post = fn (array $data) => $this->actingAs($this->user)->postJson(route('roster.assign'), ['employee_id' => $employee->getKey(), 'date' => self::WEEK, 'week' => self::WEEK, ...$data]);

        // Ô trống → M6 → hoàn tác: ô trống lại.
        $post(['action' => 'shift', 'shift_template_id' => $this->templates['M6']->getKey()])->assertJson(['error' => false]);
        $this->actingAs($this->user)->postJson(route('roster.assign-undo'), ['week' => self::WEEK])->assertJson(['error' => false]);
        $this->assertSame(0, Shift::query()->count());

        // Sửa → ô bị khóa / sửa tiếp → hoàn tác bị từ chối, ô giữ thay đổi mới hơn.
        $post(['action' => 'shift', 'shift_template_id' => $this->templates['A2']->getKey()])->assertJson(['error' => false]);
        Shift::query()->update(['is_locked' => true, 'lock_reason' => Shift::LOCK_CONFIRMED]);
        $response = $this->actingAs($this->user)->postJson(route('roster.assign-undo'), ['week' => self::WEEK])->assertJson(['error' => true]);
        $this->assertStringContainsString('đã được sửa tiếp', (string) $response->json('message'));
        $this->assertTrue((bool) Shift::query()->first()->is_locked);
        $this->assertSame('A2', Shift::query()->first()->notation);
    }
}
