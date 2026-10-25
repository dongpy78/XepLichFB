<?php

namespace Tests\Feature\Roster;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Thay thương hiệu cũ VUTA CMS (tên, logo, favicon trang quản trị) bằng tên phần mềm – DỮ LIỆU GIẢ LẬP.
 */
class BrandingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vuta_branding_is_replaced_and_custom_values_kept(): void
    {
        if (! Schema::hasTable('settings')) {
            $this->artisan('migrate', ['--path' => 'platform/core/setting/database/migrations', '--force' => true]);
        }

        DB::table('settings')->whereIn('key', ['admin_title', 'admin_logo', 'admin_favicon', 'email_footer', 'admin_primary_color'])->delete();
        DB::table('settings')->insert([
            ['key' => 'admin_title', 'value' => 'VUTA CMS'],
            ['key' => 'admin_logo', 'value' => 'logo-vuta-v2-2-1.png'],
            ['key' => 'admin_favicon', 'value' => 'logo-vuta-favicon.png'],
            ['key' => 'email_footer', 'value' => 'Gửi từ Vuta'],
            ['key' => 'admin_primary_color', 'value' => '#206bc4'],
        ]);

        $migration = require base_path('platform/plugins/restaurant-roster/database/migrations/2026_10_25_000001_replace_vuta_branding.php');
        $migration->up();
        $value = fn (string $key) => DB::table('settings')->where('key', $key)->value('value');

        $this->assertSame('Selectum Noa · Xếp lịch nhà hàng', $value('admin_title'));
        $this->assertNull($value('admin_logo'), 'Logo cũ bỏ → dùng logo mặc định của phần mềm');
        $this->assertNull($value('admin_favicon'));
        $this->assertSame('Gửi từ Selectum Noa', $value('email_footer'));
        $this->assertSame('#206bc4', $value('admin_primary_color'), 'Cài đặt khác giữ nguyên');
        $this->assertSame(0, DB::table('settings')->whereRaw('LOWER(value) LIKE ?', ['%vuta%'])->count());

        // Chạy lại không đổi tên quản lý tự đặt.
        DB::table('settings')->where('key', 'admin_title')->update(['value' => 'Tên riêng']);
        $migration->up();
        $this->assertSame('Tên riêng', $value('admin_title'));
    }
}
