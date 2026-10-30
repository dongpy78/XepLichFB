<?php

namespace Tests\Feature\Roster;

use Botble\ACL\Models\User;
use Botble\RestaurantRoster\Models\Employee;
use Botble\RestaurantRoster\Models\Outlet;
use Botble\RestaurantRoster\Models\Position;
use Botble\RestaurantRoster\Models\ScheduleVersion;
use Botble\RestaurantRoster\Models\Shift;
use Botble\RestaurantRoster\Models\ShiftTemplate;
use Botble\RestaurantRoster\Providers\RestaurantRosterServiceProvider;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__ . '/OffWorkflowTest.php';

/**
 * Công bố lịch tuần: phiên bản chính thức (bản chụp), trạng thái Bản nháp / Đã công bố / Có thay đổi chưa công bố,
 * tải PNG / Excel / PDF đúng phiên bản, phân quyền – DỮ LIỆU GIẢ LẬP.
 */
class SchedulePublicationTest extends TestCase
{
    private const WEEK = '2030-01-07';

    /** @var array<string, Outlet> */
    private array $outlets = [];

    /** @var array<string, ShiftTemplate> */
    private array $templates = [];

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

        foreach (['VILLA' => 1, 'GUSTO' => 2] as $code => $order) {
            $this->outlets[$code] = Outlet::query()->create(['name' => ucfirst(strtolower($code)), 'code' => $code, 'order' => $order, 'status' => 'published']);
        }

        foreach (['M6' => ['06:00:00', '14:00:00'], 'A2' => ['14:00:00', '22:00:00'], 'N' => ['22:00:00', '06:00:00']] as $code => [$start, $end]) {
            $this->templates[$code] = ShiftTemplate::query()->create(['code' => $code, 'name' => $code, 'start_time' => $start, 'end_time' => $end, 'hours' => 8, 'status' => 'published']);
        }
    }

    private function user(array $permissions = [], bool $super = true): User
    {
        $user = new User();
        $user->forceFill(['email' => uniqid('syn') . '@example.test', 'username' => uniqid('syn'), 'password' => bcrypt('secret'),
            'first_name' => 'Quản lý', 'last_name' => 'Thử', 'super_user' => $super, 'permissions' => array_fill_keys($permissions, true)])->save();

        return $user;
    }

    /** $count nhân viên (tên có dấu tiếng Việt), M6 / A2 xen kẽ, OFF thứ Tư (ngày 2), Chủ nhật trống. @return Employee[] */
    private function staff(int $count = 4, string $outlet = 'VILLA'): array
    {
        $waiter = Position::query()->firstOrCreate(['name' => 'Waiter'], ['status' => 'published', 'order' => 5]);
        $people = [];

        foreach (range(1, $count) as $k) {
            $employee = Employee::query()->create(['code' => $outlet[0] . $k, 'name' => "Nguyễn Thị Hằng {$outlet[0]}{$k}", 'gender' => 'female', 'outlet_id' => $this->outlets[$outlet]->getKey(),
                'position_id' => $waiter->getKey(), 'weekly_hours' => 48, 'days_off_per_week' => 1, 'auto_schedule' => true, 'status' => 'published']);

            foreach ([0, 1, 3, 4, 5] as $d) {
                $this->cell($employee, $d, $k % 2 ? 'M6' : 'A2');
            }

            Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $this->day(2), 'kind' => Shift::KIND_OFF, 'status' => 'draft', 'outlet_id' => $employee->outlet_id]);
            $people[] = $employee;
        }

        return $people;
    }

    private function day(int $d): string
    {
        return \Carbon\Carbon::parse(self::WEEK)->addDays($d)->toDateString();
    }

    private function cell(Employee $employee, int $d, string $code): Shift
    {
        $date = $this->day($d);
        [$start, $end] = [substr($this->templates[$code]->start_time, 0, 5), substr($this->templates[$code]->end_time, 0, 5)];

        $values = ['kind' => Shift::KIND_SHIFT, 'shift_template_id' => $this->templates[$code]->getKey(),
            'outlet_id' => $employee->outlet_id, 'status' => 'draft', 'start_at' => "{$date} {$start}:00",
            'end_at' => ($code === 'N' ? \Carbon\Carbon::parse($date)->addDay()->toDateString() : $date) . " {$end}:00", 'hours' => 8];
        $existing = Shift::query()->where('employee_id', $employee->getKey())->whereDate('date', $date)->first();

        return $existing ? tap($existing)->update($values) : Shift::query()->create(['employee_id' => $employee->getKey(), 'date' => $date, ...$values]);
    }

    private function publish(?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())->postJson(route('roster.publish'), ['week' => self::WEEK]);
    }

    private function grid(): string
    {
        return (string) $this->actingAs($this->user())->getJson(route('roster.grid', ['week' => self::WEEK]))->json('data.html');
    }

    private function export(string $format, array $extra = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())->get(route('roster.schedule.export', ['week' => self::WEEK, 'format' => $format, ...$extra]));
    }

    /** Ô ngày $d của nhân viên đầu tiên trong file Excel. */
    private function xlsxCell(string $content, int $d): string
    {
        return $this->xlsx($content)['cells'][\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(5 + $d) . '7'] ?? '';
    }

    /**
     * Đọc file .xlsx không cần extension zip (máy thử không bật): đọc thư mục trung tâm của file zip, giải nén các phần XML.
     *
     * @return array{cells: array<string, string>, files: array<string, string>}
     */
    private function xlsx(string $content): array
    {
        $this->assertStringStartsWith('PK', $content, 'File xlsx (zip) hợp lệ');
        $eocd = strrpos($content, "PK\x05\x06");
        $info = unpack('ventries/Vsize/Voffset', substr($content, $eocd + 10, 10));
        $files = [];
        $p = $info['offset'];

        for ($i = 0; $i < $info['entries']; $i++) {
            $h = unpack('Vsig/vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Vlocal', substr($content, $p, 46));
            $name = substr($content, $p + 46, $h['nlen']);
            $local = unpack('vnlen/velen', substr($content, $h['local'] + 26, 4));
            $data = substr($content, $h['local'] + 30 + $local['nlen'] + $local['elen'], $h['csize']);
            $files[$name] = $h['method'] === 8 ? gzinflate($data) : $data;
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        }

        $strings = [];

        if (isset($files['xl/sharedStrings.xml'])) {
            foreach ((new \SimpleXMLElement($files['xl/sharedStrings.xml']))->si as $si) {
                $strings[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', iterator_to_array($si->r->t ?? [], false)));
            }
        }

        $cells = [];
        $sheet = new \SimpleXMLElement($files['xl/worksheets/sheet1.xml']);

        foreach ($sheet->sheetData->row as $row) {
            foreach ($row->c as $c) {
                $value = (string) ($c->v ?? $c->is->t ?? '');
                $cells[(string) $c['r']] = (string) $c['t'] === 's' ? ($strings[(int) $value] ?? '') : $value;
            }
        }

        return ['cells' => $cells, 'files' => $files];
    }

    /** Xem trước → công bố v1 → trạng thái "Đã công bố"; công bố lại khi không đổi gì thì không tạo bản trùng. */
    public function test_publish_creates_official_version(): void
    {
        $this->staff();
        $this->assertStringContainsString('Bản nháp', $this->grid(), 'Chưa công bố lần nào');

        $preview = $this->actingAs($this->user())->getJson(route('roster.publish-preview', ['week' => self::WEEK]))->assertJson(['error' => false])->json('data');
        $this->assertSame([4, [], 1], [$preview['employees'], $preview['problems'], $preview['next_version']]);
        $this->assertStringContainsString('07/01/2030', $preview['week']);

        $published = $this->publish()->assertJson(['error' => false]);
        $this->assertStringContainsString('Đã công bố lịch tuần – phiên bản v1', (string) $published->json('message'));
        $version = ScheduleVersion::query()->firstOrFail();
        $this->assertSame([1, 4, 'Quản lý Thử'], [$version->version, $version->employee_count, $version->published_by_name]);
        $this->assertSame('M6', $version->snapshot['sections'][0]['rows'][0]['cells'][0]);
        $this->assertSame('OFF', $version->snapshot['sections'][0]['rows'][0]['cells'][2]);
        $this->assertTrue($version->snapshot['sections'][0]['rows'][0]['offs'][2]);
        $this->assertStringContainsString('Đã công bố', $this->grid());

        $this->publish()->assertJson(['error' => false]);
        $this->assertSame(1, ScheduleVersion::query()->count(), 'Không có thay đổi → không tạo phiên bản trùng');
    }

    /** Lỗi nghiêm trọng (N rồi M6 sáng hôm sau – thiếu giờ nghỉ): không công bố, không tạo phiên bản, không tự sửa lịch. */
    public function test_publish_blocked_by_serious_errors(): void
    {
        [$first] = $this->staff(2);
        $this->cell($first, 0, 'N');

        $preview = $this->actingAs($this->user())->getJson(route('roster.publish-preview', ['week' => self::WEEK]))->json('data');
        $this->assertNotEmpty($preview['problems']);
        $this->publish()->assertJson(['error' => true]);

        $this->assertSame(0, ScheduleVersion::query()->count());
        $this->assertSame('N', Shift::query()->where('employee_id', $first->getKey())->whereDate('date', $this->day(0))->first()->template->code, 'Không tự sửa lịch');
        $this->assertSame(0, Shift::query()->where('status', 'published')->count());
    }

    /** PNG, Excel, PDF tải được từ cùng một phiên bản; tiếng Việt, tên file có tuần + phiên bản. */
    public function test_exports_from_published_version(): void
    {
        [$first] = $this->staff();
        $first->update(['birthday' => '9/1']);
        $this->publish();
        $this->assertSame('09/01', ScheduleVersion::query()->firstOrFail()->snapshot['sections'][0]['rows'][0]['birthday'], 'Ngày sinh lưu kèm để in sau tên');

        $png = $this->export('png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('Lich-lam-viec-2030-01-07_2030-01-13-v1.png', (string) $png->headers->get('Content-Disposition'));
        $info = getimagesizefromstring($png->getContent());
        $this->assertSame(1600, $info[0], 'Ảnh rộng 1600px, bảng phủ kín chiều rộng');

        $xlsx = $this->export('xlsx')->assertOk();
        $this->assertStringContainsString('-v1.xlsx', (string) $xlsx->headers->get('Content-Disposition'));
        $book = $this->xlsx($xlsx->getContent());
        $cells = $book['cells'];
        $this->assertSame('LỊCH LÀM VIỆC TUẦN', $cells['A1']);
        $this->assertSame('Nguyễn Thị Hằng V1', $cells['C7'], 'Tên tiếng Việt có dấu');
        $this->assertSame(['M6', 'OFF'], [$cells['E7'], $cells['G7']]);
        $this->assertStringContainsString('Phiên bản v1', $cells['A3']);
        $this->assertStringContainsString('_xlnm.Print_Area', $book['files']['xl/workbook.xml'], 'Có vùng in');
        $this->assertStringContainsString('orientation="landscape"', $book['files']['xl/worksheets/sheet1.xml'], 'Khổ ngang');

        $pdf = $this->export('pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('-v1.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertStringContainsString('DejaVu', $pdf->getContent(), 'Font có tiếng Việt');

        $this->actingAs($this->user())->get(route('roster.schedule.index', ['week' => self::WEEK]))->assertOk()
            ->assertSee('Phiên bản v1')->assertSee('Nguyễn Thị Hằng V1')->assertSee('Tải Excel');
    }

    /**
     * Sửa lịch sau công bố → "Có thay đổi chưa công bố"; file tải vẫn là v1. Công bố lại → v2; v1 vẫn tải được đúng nội dung cũ.
     */
    public function test_changes_after_publish_and_versions(): void
    {
        [$first] = $this->staff();
        $this->publish();
        $this->cell($first, 0, 'A2');

        $this->assertStringContainsString('Có thay đổi chưa công bố', $this->grid());
        $this->assertSame('M6', $this->xlsxCell($this->export('xlsx')->getContent(), 0), 'Tải bản chính thức: vẫn v1');
        $this->assertSame('A2', $this->xlsxCell($this->export('xlsx', ['version' => 'draft'])->getContent(), 0), 'Bản nháp: lịch đang sửa');
        $draft = $this->export('png', ['version' => 'draft']);
        $this->assertStringContainsString('ban-nhap', (string) $draft->headers->get('Content-Disposition'));

        $this->publish()->assertJson(['error' => false]);
        $this->assertSame([1, 2], ScheduleVersion::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame('A2', $this->xlsxCell($this->export('xlsx')->getContent(), 0), 'Mặc định: phiên bản mới nhất v2');
        $this->assertSame('M6', $this->xlsxCell($this->export('xlsx', ['version' => 1])->getContent(), 0), 'v1 giữ nguyên nội dung cũ');
        $this->assertStringContainsString('Đã công bố', $this->grid());
        $this->actingAs($this->user())->get(route('roster.schedule.index', ['week' => self::WEEK, 'version' => 1]))->assertOk()->assertSee('phiên bản v1');
    }

    /** Bảng dài (65 người, 2 khu): MỘT ảnh duy nhất chứa đủ mọi người – ảnh cao theo số người, không chia phần. */
    public function test_png_is_one_image_with_everyone(): void
    {
        $this->staff(60, 'VILLA');
        $this->staff(5, 'GUSTO');
        $this->publish()->assertJson(['error' => false]);

        $png = $this->export('png')->assertOk();
        $this->assertStringContainsString('Lich-lam-viec-2030-01-07_2030-01-13-v1.png', (string) $png->headers->get('Content-Disposition'), 'Không có -phan-');
        [$width, $height] = getimagesizefromstring($png->getContent());
        $this->assertGreaterThan(1300, $width);
        $this->assertGreaterThan(65 * 60, $height, 'Đủ chỗ cho 65 dòng nhân viên + 2 khu trong một ảnh');

        $this->actingAs($this->user())->get(route('roster.schedule.index', ['week' => self::WEEK]))->assertOk()
            ->assertSee('Ảnh PNG để gửi Zalo')->assertDontSee('Phần 1/');
    }

    /** Phân quyền ở backend: chỉ xem → không công bố, không tải bản nháp; vẫn tải được lịch đã công bố. */
    public function test_permissions_enforced_on_backend(): void
    {
        $this->staff(2);
        $viewer = $this->user(['roster.index'], false);

        $denied = $this->publish($viewer);
        $this->assertTrue($denied->status() >= 400 || $denied->json('error') === true, 'Không có quyền công bố');
        $this->assertSame(0, ScheduleVersion::query()->count());

        $this->publish()->assertJson(['error' => false]);
        $this->export('xlsx', [], $viewer)->assertOk();
        // Bản nháp cần quyền sửa lịch: bị chặn (Botble trả 403 hoặc chuyển hướng kèm lỗi) – không nhận được file.
        $draft = $this->export('pdf', ['version' => 'draft'], $viewer);
        $this->assertNotSame(200, $draft->status());
        $this->assertStringNotContainsString('application/pdf', (string) $draft->headers->get('Content-Type'));

        $guest = $this->user([], false);
        $this->assertTrue($this->export('xlsx', [], $guest)->status() >= 300, 'Không có quyền xem lịch');
    }

    /**
     * "Xóa lịch tuần" (thay cho "Xóa ca nháp" – lỗi cũ: sau khi công bố không xóa được gì): xóa cả ca đã công bố của tuần,
     * mặc định giữ ô đã khóa; chọn xóa cả ô khóa thì vẫn giữ OFF quản lý đã cho; xóa luôn các bản đã công bố (tuần về "Bản nháp");
     * hoàn tác khôi phục cả ô lẫn bản đã công bố.
     */
    public function test_clear_week_removes_published_cells_and_can_undo(): void
    {
        [$first, $second] = $this->staff(3);
        $this->publish()->assertJson(['error' => false]);
        $this->assertSame(0, Shift::query()->where('status', 'draft')->count(), 'Đã công bố hết');
        $locked = Shift::query()->where('employee_id', $first->getKey())->whereDate('date', $this->day(0))->firstOrFail();
        $locked->update(['is_locked' => true, 'lock_reason' => Shift::LOCK_SHIFT_REQUEST]);
        app(\Botble\RestaurantRoster\Services\OffRequestService::class)->record([$second->getKey()], ['date_from' => $this->day(4), 'date_to' => $this->day(4), 'type' => 'off_request'], 1);
        $user = $this->user();
        $total = Shift::query()->count();

        $preview = $this->actingAs($user)->postJson(route('roster.clear-cells'), ['week' => self::WEEK, 'mode' => 'week', 'preview' => 1])->json('data');
        $this->assertSame([$total - 2, 2], [$preview['total'], $preview['kept']], 'Xem trước: số ô sẽ xóa / ô khóa được giữ');
        $this->assertGreaterThan(0, $preview['published']);
        $this->assertSame(1, $preview['versions'], 'Xem trước: số bản đã công bố sẽ xóa');

        $this->actingAs($user)->postJson(route('roster.clear-cells'), ['week' => self::WEEK, 'mode' => 'week'])->assertJson(['error' => false]);
        $this->assertSame(2, Shift::query()->count(), 'Chỉ còn ô khóa và OFF đã cho');
        $this->assertSame(0, ScheduleVersion::query()->count(), 'Xóa luôn bản đã công bố');
        $this->assertStringContainsString('Bản nháp', $this->grid(), 'Tuần trở về Bản nháp như mới');
        $this->export('png')->assertRedirect();

        $this->actingAs($user)->postJson(route('roster.clear-undo'), ['week' => self::WEEK])->assertJson(['error' => false]);
        $this->assertSame($total, Shift::query()->count(), 'Hoàn tác khôi phục đủ ô');
        $this->assertSame(1, ScheduleVersion::query()->count(), 'Hoàn tác khôi phục bản đã công bố');
        $this->assertStringContainsString('Đã công bố', $this->grid());

        $this->actingAs($user)->postJson(route('roster.clear-cells'), ['week' => self::WEEK, 'mode' => 'week', 'include_locked' => 1])->assertJson(['error' => false]);
        $this->assertSame(1, Shift::query()->count(), 'Xóa cả ô khóa – chỉ giữ OFF quản lý đã cho');
        $this->assertTrue(Shift::query()->first()->isRecordedOff());
    }
}
