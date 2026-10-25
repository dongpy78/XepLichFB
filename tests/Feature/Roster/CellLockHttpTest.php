<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Services\OffRequestService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Khóa ô được kiểm tra ở backend (không chỉ ở giao diện): xếp tay không đè ô đã khóa, gỡ OFF cần quyền – DỮ LIỆU GIẢ LẬP.
 */
class CellLockHttpTest extends TestCase
{
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(RestaurantRosterServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
        // Môi trường test chưa "cài đặt" Botble: bỏ chuyển hướng sang trình cài đặt.
        $this->withoutMiddleware(\Botble\Installer\Http\Middleware\RedirectIfNotInstalledMiddleware::class);
        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate', ['--path' => 'database/migrations', '--force' => true]);
            $this->artisan('migrate', ['--path' => 'platform/core/acl/database/migrations', '--force' => true]);
        }

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }

        $outlet = Outlet::query()->create(['name' => 'Gusto', 'code' => 'GUSTO', 'status' => 'published']);
        ShiftTemplate::query()->create(['code' => 'M6', 'name' => 'Sáng', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'status' => 'published']);
        $this->employee = Employee::query()->create(['code' => 'SYN1', 'name' => 'Synthetic 1', 'outlet_id' => $outlet->getKey(),
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);

        app(OffRequestService::class)->record([$this->employee->getKey()], ['date_from' => '2030-01-08', 'date_to' => '2030-01-08', 'type' => 'off_request'], 1);
    }

    private function user(array $permissions, bool $super = false): User
    {
        $user = new User();
        $user->forceFill([
            'email' => uniqid('syn') . '@example.test', 'username' => uniqid('syn'), 'password' => bcrypt('secret'),
            'first_name' => 'Synthetic', 'last_name' => 'Manager', 'super_user' => $super,
            'permissions' => array_fill_keys($permissions, true),
        ])->save();

        return $user;
    }

    private function cell(): ?Shift
    {
        return Shift::query()->where('employee_id', $this->employee->getKey())->whereDate('date', '2030-01-08')->first();
    }

    public function test_manual_assign_cannot_overwrite_locked_off(): void
    {
        $response = $this->actingAs($this->user([], true))->postJson(route('roster.assign'), [
            'employee_id' => $this->employee->getKey(), 'date' => '2030-01-08', 'action' => 'notation', 'notation' => 'M6',
        ]);

        $response->assertJson(['error' => true]);
        $this->assertSame(Shift::KIND_OFF, $this->cell()->kind);
        $this->assertTrue($this->cell()->is_locked);
    }

    public function test_off_form_records_locked_off_and_blocks_duplicates(): void
    {
        $manager = $this->user([], true);
        $this->actingAs($manager)->get(route('roster.availability.create'))->assertOk();

        $payload = ['employee_ids' => [$this->employee->getKey()], 'date_from' => '2030-01-10', 'date_to' => '2030-01-11',
            'type' => 'off_request', 'reason' => 'Nhắn Zalo', 'received_at' => '2030-01-02'];
        $this->actingAs($manager)->postJson(route('roster.availability.create.store'), $payload)->assertJson(['error' => false]);

        $cell = Shift::query()->where('employee_id', $this->employee->getKey())->whereDate('date', '2030-01-11')->first();
        $this->assertTrue($cell->isRecordedOff());

        // Nhập lại trùng ngày: bị chặn, không tạo đơn thứ hai.
        $this->actingAs($manager)->postJson(route('roster.availability.create.store'), [...$payload, 'date_from' => '2030-01-11', 'date_to' => '2030-01-12'])
            ->assertJson(['error' => true]);
        $this->assertSame(2, \Botble\RestaurantRoster\Models\AvailabilityRequest::query()->count());
    }

    /** Kỳ lịch: tổng hợp OFF có cảnh báo; chưa xác nhận nhập xong OFF thì không chạy xếp tự động, xác nhận rồi thì chạy được. */
    public function test_period_summary_and_off_confirmation_gate(): void
    {
        // Bước "Kỳ lịch & OFF" đã bỏ mặc định (xếp theo tuần) – vẫn giữ được khi bật lại cấu hình.
        config(['plugins.restaurant-roster.general.scheduling.require_off_confirmation' => true]);
        config(['plugins.restaurant-roster.general.scheduling.staffing_mode' => 'requirements']);
        $outlet = Outlet::query()->firstOrFail();
        $m6 = ShiftTemplate::query()->firstOrFail();
        \Botble\RestaurantRoster\Models\EmployeeSkill::query()->create(['employee_id' => $this->employee->getKey(), 'outlet_id' => $outlet->getKey(), 'level' => 'primary']);
        \Botble\RestaurantRoster\Models\StaffingRequirement::query()->create(['outlet_id' => $outlet->getKey(), 'shift_template_id' => $m6->getKey(),
            'min_staff' => 1, 'target_staff' => 1, 'is_confirmed' => true]);
        config(['plugins.restaurant-roster.general.scheduling.time_limit_seconds' => 2]);
        $manager = $this->user([], true);

        $this->actingAs($manager)->post(route('roster.periods.store'), ['name' => 'Tuần thử', 'start_date' => '2030-01-07', 'end_date' => '2030-01-13']);
        $period = \Botble\RestaurantRoster\Models\Period::query()->firstOrFail();

        // Người đủ năng lực duy nhất đã OFF ngày 08/01 → cảnh báo không còn người đủ năng lực.
        $this->actingAs($manager)->get(route('roster.periods.show', $period->id))->assertOk()->assertSee('chỉ còn 0 người đủ năng lực', false);

        $run = fn () => $this->actingAs($manager)->postJson(route('roster.auto.run'), ['from' => '2030-01-07', 'to' => '2030-01-13', 'type' => 'generate']);
        $run()->assertJson(['error' => true]);
        $this->assertSame(0, \Botble\RestaurantRoster\Models\Run::query()->count(), 'Chưa xác nhận OFF thì không chạy thuật toán');

        $this->actingAs($manager)->post(route('roster.periods.confirm-off', $period->id));
        $this->assertNotNull($period->refresh()->off_confirmed_at);

        $run()->assertJson(['error' => false]);
        $this->assertSame(1, \Botble\RestaurantRoster\Models\Run::query()->count());

        // Xóa kỳ lịch: chỉ xóa kỳ, lịch làm việc và OFF đã ghi giữ nguyên.
        $cells = Shift::query()->count();
        $this->actingAs($manager)->delete(route('roster.periods.destroy', $period->id));
        $this->assertNull(\Botble\RestaurantRoster\Models\Period::query()->find($period->id));
        $this->assertSame($cells, Shift::query()->count());
        $this->actingAs($manager)->get(route('roster.periods.index'))->assertOk();
    }

    /** Các trang quản lý mở được (không lỗi khi render). */
    public function test_manager_pages_render(): void
    {
        $manager = $this->user([], true);
        $request = \Botble\RestaurantRoster\Models\AvailabilityRequest::query()->firstOrFail();

        foreach ([route('roster.periods.index'), route('roster.availability.index'), route('roster.auto.index'),
            route('roster.index', ['week' => '2030-01-07']), route('roster.availability.edit', $request->id), route('roster.positions.create')] as $url) {
            $this->actingAs($manager)->get($url)->assertOk();
        }

        $this->actingAs($manager)->getJson(route('roster.grid', ['week' => '2030-01-07']))->assertJson(['error' => false]);

        // Bảng Botble tải dữ liệu bằng POST tới chính trang danh sách: route danh sách OFF phải nhận POST như các bảng khác.
        $this->actingAs($manager)->post(route('roster.availability.index'))->assertOk();
    }

    /** Form nhập OFF: ô chọn nhiều nhân viên gửi đúng employee_ids[] (lỗi cũ: employee_ids[][] → "must be an integer"). */
    public function test_off_form_employee_select_is_flat_array(): void
    {
        $html = $this->actingAs($this->user([], true))->get(route('roster.availability.create'))->getContent();

        $this->assertStringContainsString('name="employee_ids[]"', $html);
        $this->assertStringNotContainsString('employee_ids[][]', $html);
        $this->assertMatchesRegularExpression('/type="date"[^>]*name="date_from"|name="date_from"[^>]*type="date"/', $html, 'Từ ngày là ô chọn ngày');
        // Bộ kiểm tra trên trình duyệt không còn quy tắc từng phần tử (lỗi "The employee_ids.* must be an integer").
        $this->assertStringNotContainsString('employee_ids.*', $html);
        $this->assertStringNotContainsString('employee_ids[*]', $html);

        // Server vẫn chặn mã nhân viên không tồn tại.
        $this->actingAs($this->user([], true))->postJson(route('roster.availability.create.store'), ['employee_ids' => [$this->employee->getKey(), 99999],
            'date_from' => '2030-01-20', 'date_to' => '2030-01-20', 'type' => 'off_request'])->assertStatus(422);
    }

    /** Ghi OFF trên bảng: ngày liền nhau gộp một đơn, ngày đã là OFF đã cho thì bỏ qua, ô được khóa. */
    public function test_record_off_from_grid_selection(): void
    {
        $other = Employee::query()->create(['code' => 'SYN2', 'name' => 'Synthetic 2', 'outlet_id' => $this->employee->outlet_id,
            'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);
        $cells = [
            ['employee_id' => $this->employee->getKey(), 'date' => '2030-01-08'], // đã là OFF (setUp) → bỏ qua
            ['employee_id' => $this->employee->getKey(), 'date' => '2030-01-09'],
            ['employee_id' => $other->getKey(), 'date' => '2030-01-10'],
            ['employee_id' => $other->getKey(), 'date' => '2030-01-11'],
            ['employee_id' => $other->getKey(), 'date' => '2030-01-13'],
        ];

        $this->actingAs($this->user([], true))->postJson(route('roster.off-record'), ['cells' => $cells, 'off_type' => 'off_request', 'off_reason' => 'Zalo', 'week' => '2030-01-07'])
            ->assertJson(['error' => false]);

        $requests = \Botble\RestaurantRoster\Models\AvailabilityRequest::query()->where('employee_id', $other->getKey())->orderBy('date_from')->get();
        $this->assertSame([['2030-01-10', '2030-01-11'], ['2030-01-13', '2030-01-13']],
            $requests->map(fn ($r) => [$r->date_from->toDateString(), $r->date_to->toDateString()])->all(), 'Ngày liền nhau gộp một đơn');
        $this->assertTrue(Shift::query()->where('employee_id', $other->getKey())->whereDate('date', '2030-01-13')->first()->isRecordedOff());
        $this->assertSame(1, \Botble\RestaurantRoster\Models\AvailabilityRequest::query()->where('employee_id', $this->employee->getKey())->whereDate('date_from', '2030-01-09')->count());
        // Ngày 08/01 đã có OFF (đơn trong setUp) → không có đơn trùng
        $this->assertSame(1, \Botble\RestaurantRoster\Models\AvailabilityRequest::query()->where('employee_id', $this->employee->getKey())->whereDate('date_from', '2030-01-08')->count());

        // Người không có quyền ghi OFF bị chặn.
        $before = \Botble\RestaurantRoster\Models\AvailabilityRequest::query()->count();
        $denied = $this->actingAs($this->user(['roster.index', 'roster.manage']))->postJson(route('roster.off-record'), ['cells' => [['employee_id' => $other->getKey(), 'date' => '2030-01-12']], 'off_type' => 'off_request']);
        $this->assertTrue($denied->status() >= 400 || $denied->json('error') === true, 'Bị chặn bởi phân quyền');
        $this->assertSame($before, \Botble\RestaurantRoster\Models\AvailabilityRequest::query()->count(), 'Không tạo OFF');
    }

    /** Ghi ca nhân viên xin (M6 / A2) trên bảng: ô được ghi ca và khóa như OFF xin; ô OFF đã cho không bị đè; chỉ ca được phép; cần quyền. */
    public function test_record_requested_shift_from_grid_selection(): void
    {
        $m6 = ShiftTemplate::query()->where('code', 'M6')->firstOrFail();
        $oh = ShiftTemplate::query()->create(['code' => 'OH', 'name' => 'OH', 'start_time' => '08:45:00', 'end_time' => '16:45:00', 'status' => 'published']);
        $cells = [
            ['employee_id' => $this->employee->getKey(), 'date' => '2030-01-08'], // OFF đã cho (setUp) → giữ nguyên
            ['employee_id' => $this->employee->getKey(), 'date' => '2030-01-09'],
            ['employee_id' => $this->employee->getKey(), 'date' => '2030-01-10'],
        ];

        $response = $this->actingAs($this->user([], true))->postJson(route('roster.shift-request'), ['cells' => $cells, 'shift_template_id' => $m6->getKey(), 'reason' => 'Zalo', 'week' => '2030-01-07']);
        $response->assertJson(['error' => false]);
        $this->assertStringContainsString('Giữ nguyên ô đang khóa', (string) $response->json('message'));

        foreach (['2030-01-09', '2030-01-10'] as $date) {
            $shift = Shift::query()->where('employee_id', $this->employee->getKey())->whereDate('date', $date)->firstOrFail();
            $this->assertSame([Shift::KIND_SHIFT, $m6->getKey(), true, Shift::LOCK_SHIFT_REQUEST, (int) $this->employee->outlet_id],
                [$shift->kind, (int) $shift->shift_template_id, (bool) $shift->is_locked, $shift->lock_reason, (int) $shift->outlet_id]);
            $this->assertStringContainsString('Nhân viên xin ca M6', (string) $shift->note);
        }

        $this->assertTrue(Shift::query()->where('employee_id', $this->employee->getKey())->whereDate('date', '2030-01-08')->first()->isRecordedOff(), 'OFF đã cho giữ nguyên');

        // Ca không thuộc request_shift_codes (OH) bị từ chối.
        $this->actingAs($this->user([], true))->postJson(route('roster.shift-request'), ['cells' => [['employee_id' => $this->employee->getKey(), 'date' => '2030-01-11']], 'shift_template_id' => $oh->getKey()])
            ->assertStatus(422);

        // Người không có quyền ghi OFF / ca xin bị chặn.
        $denied = $this->actingAs($this->user(['roster.index', 'roster.manage']))->postJson(route('roster.shift-request'), ['cells' => [['employee_id' => $this->employee->getKey(), 'date' => '2030-01-12']], 'shift_template_id' => $m6->getKey()]);
        $this->assertTrue($denied->status() >= 400 || $denied->json('error') === true, 'Bị chặn bởi phân quyền');
        $this->assertNull(Shift::query()->where('employee_id', $this->employee->getKey())->whereDate('date', '2030-01-12')->first());

        // Xếp tự động chạy theo tuần, không cần kỳ lịch; ca xin đã khóa được giữ nguyên.
        $this->actingAs($this->user([], true))->postJson(route('roster.auto.run'), ['from' => '2030-01-07', 'to' => '2030-01-13', 'type' => 'generate'])
            ->assertJson(['error' => false]);
        $run = \Botble\RestaurantRoster\Models\Run::query()->latest('id')->firstOrFail();
        $cell = collect($run->proposal['assignments'] ?? [])->first(fn ($r) => (int) $r['employee_id'] === $this->employee->getKey() && $r['date'] === '2030-01-09');
        $this->assertSame($m6->getKey(), (int) ($cell['shift_id'] ?? 0), 'Ca xin đã khóa: thuật toán giữ nguyên');
    }

    /** Cấp quản lý theo cờ của vị trí: thuật toán nhận đúng, xếp tay ngoài M6 / A2 thì ô tự khóa, bảng báo lệch sáng / chiều. */
    public function test_manager_flag_drives_engine_and_manual_edits(): void
    {
        $outlet = Outlet::query()->firstOrFail();
        $m6 = ShiftTemplate::query()->where('code', 'M6')->firstOrFail();
        ShiftTemplate::query()->create(['code' => 'A2', 'name' => 'Chiều', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'status' => 'published']);
        ShiftTemplate::query()->create(['code' => 'OH', 'name' => 'OH', 'start_time' => '08:45:00', 'end_time' => '16:45:00', 'status' => 'published']);
        $captain = \Botble\RestaurantRoster\Models\Position::query()->create(['name' => 'Restaurant Captain', 'is_manager' => true, 'status' => 'published']);
        $waiter = \Botble\RestaurantRoster\Models\Position::query()->create(['name' => 'Waiter', 'is_manager' => false, 'status' => 'published']);

        $managers = [];

        foreach (range(1, 3) as $k) {
            $managers[] = Employee::query()->create(['code' => "MGR{$k}", 'name' => "Synthetic Captain {$k}", 'outlet_id' => $outlet->getKey(), 'position_id' => $captain->getKey(),
                'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);
        }

        $this->employee->update(['position_id' => $waiter->getKey()]);

        $problem = app(\Botble\RestaurantRoster\Services\ProblemBuilder::class)->build(\Carbon\Carbon::parse('2030-01-07'), \Carbon\Carbon::parse('2030-01-13'));
        $this->assertTrue($problem->employees[$managers[0]->getKey()]->isManager);
        $this->assertSame([(int) $m6->getKey(), (int) ShiftTemplate::query()->where('code', 'A2')->value('id')], $problem->employees[$managers[0]->getKey()]->managerShiftIds);
        $this->assertFalse($problem->employees[$this->employee->getKey()]->isManager);

        $manager = $this->user([], true);
        $assign = fn (Employee $e, string $notation) => $this->actingAs($manager)->postJson(route('roster.assign'), [
            'employee_id' => $e->getKey(), 'date' => '2030-01-09', 'action' => 'notation', 'notation' => $notation, 'week' => '2030-01-07',
        ]);
        $cell = fn (Employee $e) => Shift::query()->where('employee_id', $e->getKey())->whereDate('date', '2030-01-09')->first();

        $assign($managers[0], 'M6')->assertJson(['error' => false]);
        $this->assertFalse($cell($managers[0])->is_locked, 'M6 tại outlet chính: không cần khóa');

        $assign($managers[1], 'M6');
        $response = $assign($managers[2], 'OH')->assertJson(['error' => false]);
        $this->assertTrue($cell($managers[2])->is_locked, 'Quản lý xếp tay OH: ô tự khóa');
        $this->assertSame(Shift::LOCK_MANUAL, $cell($managers[2])->lock_reason);
        $this->assertStringContainsString('quản lý ca sáng 3 / ca chiều 0', $response->json('message'), 'Cảnh báo lệch sáng / chiều');
        $this->assertStringContainsString('quản lý lệch: sáng 3 / chiều 0', $response->json('data.html'), 'Bảng xếp ca báo lệch');
    }

    /** 8. Nữ waiter trực tiếp: cờ theo vị trí, sửa tay trên bảng tính lại cảnh báo thiếu ngay; form định biên có ô nữ waiter. */
    public function test_female_waiter_minimum_on_grid_is_recomputed_after_manual_edits(): void
    {
        config(['plugins.restaurant-roster.general.scheduling.staffing_mode' => 'requirements']);
        $outlet = Outlet::query()->firstOrFail();
        $m6 = ShiftTemplate::query()->where('code', 'M6')->firstOrFail();
        $waiter = \Botble\RestaurantRoster\Models\Position::query()->create(['name' => 'Waiter', 'status' => 'published']);
        $hostess = \Botble\RestaurantRoster\Models\Position::query()->create(['name' => 'Hostess', 'status' => 'published']);
        $make = fn (string $code, $position) => Employee::query()->create(['code' => $code, 'name' => "Synthetic {$code}", 'gender' => 'female', 'outlet_id' => $outlet->getKey(),
            'position_id' => $position->getKey(), 'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);
        [$a, $b, $h] = [$make('W1', $waiter), $make('W2', $waiter), $make('H1', $hostess)];
        \Botble\RestaurantRoster\Models\StaffingRequirement::query()->create(['outlet_id' => $outlet->getKey(), 'shift_template_id' => $m6->getKey(),
            'min_staff' => 1, 'target_staff' => 3, 'min_female' => 2, 'target_female' => 2, 'is_confirmed' => true]);

        $problem = app(\Botble\RestaurantRoster\Services\ProblemBuilder::class)->build(\Carbon\Carbon::parse('2030-01-07'), \Carbon\Carbon::parse('2030-01-13'));
        $this->assertTrue($problem->employees[$a->getKey()]->isFemaleStaff);
        $this->assertFalse($problem->employees[$h->getKey()]->isFemaleStaff, 'Hostess không tính là nữ waiter');
        $requirement = array_values(array_filter($problem->requirements, fn ($r) => $r->date === '2030-01-09' && $r->shiftId === (int) $m6->getKey()))[0];
        $this->assertSame([2, 2], [$requirement->minFemale, $requirement->targetFemale]);

        $manager = $this->user([], true);
        $assign = fn (Employee $e) => $this->actingAs($manager)->postJson(route('roster.assign'), [
            'employee_id' => $e->getKey(), 'date' => '2030-01-09', 'action' => 'notation', 'notation' => 'M6', 'week' => '2030-01-07',
        ])->json('data.html');

        $assign($h);
        $html = $assign($a);
        $this->assertStringContainsString('thiếu nữ waiter: 1/2 tối thiểu', $html, 'Hostess không được đếm; 1 nữ waiter → thiếu');
        $this->assertStringContainsString('NW1/2', $html);
        $html = $assign($b);
        // Ngày 09/01 đủ 2 nữ waiter → hết cảnh báo (các ngày khác chưa xếp ai vẫn báo thiếu 0/2).
        $this->assertStringNotContainsString('thiếu nữ waiter: 1/2', $html, 'Đủ 2 nữ waiter → hết cảnh báo');
        $this->assertStringContainsString('NW2/2', $html);

        $this->actingAs($manager)->get(route('roster.requirements.create'))->assertOk()->assertSee('Định biên theo nhóm nhân sự')->assertSee('Nữ waiter');
    }

    public function test_removing_recorded_off_requires_permission(): void
    {
        $this->actingAs($this->user(['roster.index', 'roster.manage']))->postJson(route('roster.lock'), [
            'employee_id' => $this->employee->getKey(), 'date' => '2030-01-08', 'locked' => 0,
        ])->assertJson(['error' => true]);
        $this->assertNotNull($this->cell(), 'Không có quyền thì OFF vẫn còn');

        $this->actingAs($this->user([], true))->postJson(route('roster.off-remove'), [
            'employee_id' => $this->employee->getKey(), 'date' => '2030-01-08',
        ])->assertJson(['error' => false]);
        $this->assertNull($this->cell(), 'Quản lý có quyền gỡ được OFF');
    }
}
