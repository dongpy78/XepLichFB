<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Models\StaffingRequirement;
use Botble\RestaurantRoster\Models\StaffingRequirementPosition;
use Botble\RestaurantRoster\Models\StaffingRequirementRevision;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Botble\RestaurantRoster\Scheduling\RequirementResolver;
use Botble\RestaurantRoster\Services\ProblemBuilder;
use Botble\RestaurantRoster\Services\RosterScheduler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Module Định biên nhân sự trên database thật (SQLite) qua giao diện quản lý – DỮ LIỆU GIẢ LẬP, ngày năm 2030.
 */
class StaffingRequirementModuleTest extends TestCase
{
    private Outlet $villa;

    private ShiftTemplate $m6;

    private Position $hostess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(RestaurantRosterServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
        $this->withoutMiddleware(\Botble\Installer\Http\Middleware\RedirectIfNotInstalledMiddleware::class);
        config(['plugins.restaurant-roster.general' => require dirname(__DIR__, 3) . '/platform/plugins/restaurant-roster/config/general.php']);
        // Module Định biên nhân sự dùng ở chế độ "requirements" (chế độ mặc định "balance" chỉ giữ định biên Lobby / Night / Room Service / Kid).
        config(['plugins.restaurant-roster.general.scheduling.staffing_mode' => 'requirements']);

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate', ['--path' => 'database/migrations', '--force' => true]);
            $this->artisan('migrate', ['--path' => 'platform/core/acl/database/migrations', '--force' => true]);
        }

        if (! Schema::hasTable('roster_shifts')) {
            $this->artisan('migrate', ['--path' => 'platform/plugins/restaurant-roster/database/migrations', '--force' => true]);
        }

        $this->villa = Outlet::query()->create(['name' => 'Villa', 'code' => 'VILLA', 'status' => 'published']);
        $this->m6 = ShiftTemplate::query()->create(['code' => 'M6', 'name' => 'Sáng', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'lobby_start_time' => '09:00:00', 'lobby_end_time' => '12:00:00', 'status' => 'published']);
        $this->hostess = Position::query()->create(['name' => 'Hostess', 'status' => 'published']);
        Position::query()->create(['name' => 'Waiter', 'status' => 'published']);
        Position::query()->create(['name' => 'Restaurant Captain', 'is_manager' => true, 'status' => 'published']);
    }

    private function user(array $permissions, bool $super = false): User
    {
        $user = new User();
        $user->forceFill(['email' => uniqid('syn') . '@example.test', 'username' => uniqid('syn'), 'password' => bcrypt('secret'),
            'first_name' => 'Synthetic', 'last_name' => 'Manager', 'super_user' => $super, 'permissions' => array_fill_keys($permissions, true)])->save();

        return $user;
    }

    private function payload(array $override = []): array
    {
        return [
            'outlet_id' => $this->villa->id, 'shift_template_id' => $this->m6->id, 'status' => 'active', 'apply_mode' => 'all',
            'min_staff' => 3, 'target_staff' => 4, 'applies_male' => 1, 'min_male' => 1, 'target_male' => 2, 'applies_female' => 1, 'min_female' => 2, 'target_female' => 3,
            'is_confirmed' => 1, 'allow_reduce' => 1, 'reason' => 'Thiết lập mùa cao điểm',
            'positions' => [
                ['kind' => 'position', 'position_id' => $this->hostess->id, 'applies' => 1, 'min_staff' => 1, 'target_staff' => 1],
                ['kind' => 'managers', 'position_id' => '', 'applies' => 1, 'min_staff' => 1, 'target_staff' => 1],
            ],
            ...$override,
        ];
    }

    private function requirementOn(string $date): ?\Botble\RestaurantRoster\Scheduling\Requirement
    {
        $problem = app(ProblemBuilder::class)->build(Carbon::parse($date), Carbon::parse($date));

        return array_values(array_filter($problem->requirements, fn ($r) => $r->outletId === $this->villa->id && $r->shiftId === $this->m6->id))[0] ?? null;
    }

    /** Thuật toán đọc đúng định biên lưu qua giao diện: tổng, nam, nữ waiter, Hostess, cấp quản lý; có lịch sử. */
    public function test_scheduler_reads_configuration_saved_through_the_ui(): void
    {
        $this->actingAs($this->user([], true))->postJson(route('roster.requirements.create.store'), $this->payload())->assertJson(['error' => false]);

        $requirement = $this->requirementOn('2030-01-09');
        $this->assertSame([3, 4, 1, 2, 2, 3], [$requirement->min, $requirement->target, $requirement->minMale, $requirement->targetMale, $requirement->minFemale, $requirement->targetFemale]);
        $this->assertSame([(string) $this->hostess->id => 1, RequirementResolver::MANAGER_GROUP => 1], $requirement->positionMins);

        $revision = StaffingRequirementRevision::query()->firstOrFail();
        $this->assertSame(['create', 'Thiết lập mùa cao điểm'], [$revision->action, $revision->reason]);
        $this->assertNotNull($revision->user_id);
    }

    /** 11.9: nháp thiếu số được lưu nhưng không được thuật toán dùng; đang áp dụng mà thiếu số thì bị chặn (không coi trống là 0). */
    public function test_incomplete_configuration_is_never_used_as_zero(): void
    {
        $manager = $this->user([], true);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['min_staff' => '', 'target_staff' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['min_staff']);
        $this->assertSame(0, StaffingRequirement::query()->count());

        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['status' => 'draft', 'min_staff' => '', 'target_staff' => '']))
            ->assertJson(['error' => false]);
        $this->assertSame('draft', StaffingRequirement::query()->value('status'));
        $this->assertNull($this->requirementOn('2030-01-09'), 'Bản nháp không được dùng');

        // Nhóm "áp dụng" nhưng chưa nhập tối thiểu → lỗi đúng trường.
        $positions = $this->payload()['positions'];
        $positions[0]['min_staff'] = '';
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['positions' => $positions]))
            ->assertStatus(422)->assertJsonValidationErrors(['positions.0.min_staff']);

        // Không áp dụng nữ waiter = null, không phải 0.
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['applies_female' => 0, 'min_female' => 5]))->assertJson(['error' => false]);
        $this->assertNull(StaffingRequirement::query()->where('status', 'active')->value('min_female'));
    }

    /** 11.10: cấu hình cũ hết hiệu lực, cấu hình mới bắt đầu có hiệu lực. */
    public function test_new_configuration_takes_over_from_effective_date(): void
    {
        $manager = $this->user([], true);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['effective_to' => '2030-01-09']))->assertJson(['error' => false]);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['effective_from' => '2030-01-10', 'min_staff' => 5, 'target_staff' => 6]))->assertJson(['error' => false]);

        $this->assertSame(3, $this->requirementOn('2030-01-09')->min);
        $this->assertSame(5, $this->requirementOn('2030-01-10')->min);
    }

    /** 11.11: hai cấu hình cùng mức ưu tiên mâu thuẫn – không cho lưu; nếu có sẵn trong database thì thuật toán dừng và nêu rõ. */
    public function test_conflicting_configurations_are_blocked(): void
    {
        $manager = $this->user([], true);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload())->assertJson(['error' => false]);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['min_staff' => 2, 'target_staff' => 2]))
            ->assertStatus(422)->assertJsonValidationErrors(['conflict']);

        // Dữ liệu cũ đã mâu thuẫn sẵn (vd nhập trực tiếp vào database): không tự chọn – lần chạy báo lỗi kèm dòng xung đột.
        StaffingRequirement::query()->create(['outlet_id' => $this->villa->id, 'shift_template_id' => $this->m6->id, 'status' => 'active', 'min_staff' => 1, 'target_staff' => 1]);
        $builder = app(ProblemBuilder::class);
        $builder->build(Carbon::parse('2030-01-09'), Carbon::parse('2030-01-09'));
        $this->assertNull($this->requirementOn('2030-01-09'));
        $this->assertNotEmpty($builder->conflicts);

        config(['plugins.restaurant-roster.general.scheduling.time_limit_seconds' => 1]);
        $run = app(RosterScheduler::class)->run(Carbon::parse('2030-01-07'), Carbon::parse('2030-01-08'), null, 'generate', 1);
        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('định biên mâu thuẫn', $run->explanation);

        $this->actingAs($manager)->get(route('roster.requirements.index', ['week' => '2030-01-07']))->assertOk()->assertSee('Xung đột');
    }

    /** 11.12: đổi định biên khi đã có lịch – xem trước ảnh hưởng, lưu không tự đổi lịch. */
    public function test_impact_preview_and_saving_never_changes_schedules(): void
    {
        $manager = $this->user([], true);
        // Định biên cũ: 2 người, 1 nữ waiter – lịch đã xếp (2 nữ trực tiếp + 1 người (L)) đang đủ.
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['applies_male' => 0, 'min_staff' => 2, 'target_staff' => 3, 'min_female' => 1, 'target_female' => 1]))->assertJson(['error' => false]);
        $model = StaffingRequirement::query()->firstOrFail();
        $date = Carbon::today()->addDays(2)->toDateString();
        $waiter = Position::query()->where('name', 'Waiter')->value('id');

        foreach (range(1, 3) as $k) {
            $employee = Employee::query()->create(['code' => "W{$k}", 'name' => "Synthetic W{$k}", 'gender' => 'female', 'outlet_id' => $this->villa->id, 'position_id' => $waiter,
                'weekly_hours' => 48, 'days_off_per_week' => 2, 'auto_schedule' => true, 'status' => 'published']);
            Shift::query()->create(['employee_id' => $employee->id, 'date' => $date, 'kind' => 'shift', 'shift_template_id' => $this->m6->id, 'outlet_id' => $this->villa->id,
                'status' => $k === 1 ? 'published' : 'draft', 'tags' => $k === 3 ? ['L'] : null, 'start_at' => "{$date} 06:00:00", 'end_at' => "{$date} 14:00:00", 'hours' => 8]);
        }

        $preview = $this->actingAs($manager)->postJson(route('roster.requirements.preview'), $this->payload(['id' => $model->id, 'applies_male' => 0, 'min_staff' => 2, 'target_staff' => 3, 'min_female' => 3, 'target_female' => 3]))
            ->assertOk()->json('data');
        $row = collect($preview['rows'])->firstWhere('date', $date);
        $this->assertSame(['total' => 2, 'male' => 0, 'female' => 2], $row['actual'], 'Người hỗ trợ (L) không tính vào outlet');
        $this->assertTrue($row['published']);
        $this->assertSame('short_min', $row['new_status']);
        $this->assertNotEmpty($preview['newly_short']);

        $before = Shift::query()->get()->toArray();
        $this->actingAs($manager)->postJson(route('roster.requirements.edit.update', $model->id), $this->payload(['applies_male' => 0, 'min_staff' => 2, 'target_staff' => 3, 'min_female' => 3, 'target_female' => 3]))->assertJson(['error' => false]);
        $this->assertSame($before, Shift::query()->get()->toArray(), 'Lịch đã tạo / đã công bố không tự đổi');
        $this->assertSame(3, $this->requirementOn($date)->minFemale);
        $this->assertSame(['create', 'update'], StaffingRequirementRevision::query()->orderBy('id')->pluck('action')->all());
    }

    /** 11.13: người không có quyền không thêm / sửa / ngừng hiệu lực được (kiểm tra ở backend). */
    public function test_users_without_permission_cannot_change_configuration(): void
    {
        $this->actingAs($this->user([], true))->postJson(route('roster.requirements.create.store'), $this->payload())->assertJson(['error' => false]);
        $model = StaffingRequirement::query()->firstOrFail();
        $viewer = $this->user(['roster.index']);

        $this->assertGreaterThanOrEqual(400, $this->actingAs($viewer)->postJson(route('roster.requirements.create.store'), $this->payload(['min_staff' => 9, 'target_staff' => 9]))->status());
        $this->assertGreaterThanOrEqual(400, $this->actingAs($viewer)->postJson(route('roster.requirements.edit.update', $model->id), $this->payload(['min_staff' => 9, 'target_staff' => 9]))->status());
        $this->assertGreaterThanOrEqual(400, $this->actingAs($viewer)->postJson(route('roster.requirements.status', $model->id), ['status' => 'inactive'])->status());

        $this->assertSame(1, StaffingRequirement::query()->count());
        $this->assertSame([3, 'active'], [$model->refresh()->min_staff, $model->status]);
    }

    /** 11.14: lưu thất bại giữa chừng → không còn dữ liệu dở dang (transaction); dữ liệu sai → không lưu gì. */
    public function test_failed_save_leaves_no_partial_data(): void
    {
        $manager = $this->user([], true);
        StaffingRequirementPosition::creating(function (): void {
            throw new RuntimeException('Giả lập lỗi ghi nhóm vị trí');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload());
            $this->fail('Phải có lỗi');
        } catch (RuntimeException) {
        }

        $this->assertSame([0, 0, 0], [StaffingRequirement::query()->count(), StaffingRequirementPosition::query()->count(), StaffingRequirementRevision::query()->count()]);
        StaffingRequirementPosition::flushEventListeners();
    }

    public function test_invalid_values_are_rejected_per_field(): void
    {
        $manager = $this->user([], true);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['min_staff' => 5, 'target_staff' => 3, 'min_female' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['min_female']);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['min_staff' => 5, 'target_staff' => 3]))
            ->assertStatus(422)->assertJsonValidationErrors(['target_staff']);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['effective_from' => '2030-02-01', 'effective_to' => '2030-01-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        $this->assertSame(0, StaffingRequirement::query()->count());
    }

    /** Xóa dòng đã áp dụng = ngừng hiệu lực (giữ lịch sử); xóa nháp = xóa hẳn. Trang tổng quan, sửa, sao chép, lịch sử mở được. */
    public function test_deactivate_instead_of_delete_and_pages_render(): void
    {
        $manager = $this->user([], true);
        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload())->assertJson(['error' => false]);
        $model = StaffingRequirement::query()->firstOrFail();

        $this->actingAs($manager)->deleteJson(route('roster.requirements.destroy', $model->id))->assertJson(['error' => false]);
        $this->assertSame('inactive', $model->refresh()->status, 'Đã từng áp dụng: chỉ ngừng hiệu lực');
        $this->assertNull($this->requirementOn('2030-01-09'));

        foreach ([route('roster.requirements.index'), route('roster.requirements.edit', $model->id), route('roster.requirements.create', ['copy' => $model->id]),
            route('roster.requirements.history', $model->id), route('roster.requirements.create')] as $url) {
            $this->actingAs($manager)->get($url)->assertOk();
        }

        $this->actingAs($manager)->get(route('roster.requirements.history', $model->id))->assertSee('Ngừng hiệu lực');

        $this->actingAs($manager)->postJson(route('roster.requirements.create.store'), $this->payload(['status' => 'draft']))->assertJson(['error' => false]);
        $draft = StaffingRequirement::query()->where('status', 'draft')->firstOrFail();
        $this->actingAs($manager)->deleteJson(route('roster.requirements.destroy', $draft->id))->assertJson(['error' => false]);
        $this->assertNull(StaffingRequirement::query()->find($draft->id), 'Nháp chưa từng áp dụng: xóa hẳn');
    }

    /** Mỗi lần chạy lưu kèm ảnh chụp định biên đã dùng (giải thích kết quả về sau). */
    public function test_run_keeps_snapshot_of_staffing_used(): void
    {
        $this->actingAs($this->user([], true))->postJson(route('roster.requirements.create.store'), $this->payload())->assertJson(['error' => false]);
        config(['plugins.restaurant-roster.general.scheduling.time_limit_seconds' => 1]);

        $run = app(RosterScheduler::class)->run(Carbon::parse('2030-01-07'), Carbon::parse('2030-01-08'), null, 'generate', 1, ['time_limit_seconds' => 1]);
        $snapshot = $run->settings['staffing_snapshot'] ?? [];
        $id = StaffingRequirement::query()->value('id');

        $this->assertArrayHasKey($id, $snapshot);
        $this->assertSame(2, $snapshot[$id]['min_female']);
    }
}
