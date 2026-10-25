<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thay thương hiệu cũ "VUTA CMS" (tên hệ thống, logo, favicon trang quản trị / đăng nhập) bằng tên phần mềm hiện tại (quản lý 25/10/2026).
 * Chỉ sửa giá trị đang chứa "vuta" – tên / logo quản lý tự đặt sau này không bị đè. Logo, favicon mới: config core.base.general (ServiceProvider).
 */
return new class () extends Migration {
    public const NAME = 'Selectum Noa · Xếp lịch nhà hàng';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (DB::table('settings')->whereRaw('LOWER(value) LIKE ?', ['%vuta%'])->get(['id', 'key', 'value']) as $row) {
            $value = (string) $row->value;

            // Ảnh (logo, favicon, ảnh nền đăng nhập…) của thương hiệu cũ: bỏ để dùng logo / favicon mặc định của phần mềm.
            if (preg_match('/\.(png|jpe?g|gif|svg|webp|ico)(\?.*)?$/i', $value) || str_contains(strtolower($value), 'vuta.vn')) {
                DB::table('settings')->where('id', $row->id)->delete();

                continue;
            }

            if ($row->key === 'admin_title') {
                $value = self::NAME;
            } else {
                $value = str_ireplace(['VUTA CMS', 'VUTA'], [self::NAME, 'Selectum Noa'], $value);
            }

            DB::table('settings')->where('id', $row->id)->update(['value' => $value, 'updated_at' => now()]);
        }

        if (! DB::table('settings')->where('key', 'admin_title')->exists()) {
            DB::table('settings')->insert(['key' => 'admin_title', 'value' => self::NAME, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Không khôi phục thương hiệu cũ.
    }
};
