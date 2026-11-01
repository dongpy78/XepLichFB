<?php

namespace Botble\RestaurantRoster\Casts;

use Botble\Base\Facades\BaseHelper;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Như Botble\Base\Casts\SafeContent (lọc HTML khi ghi và khi đọc), nhưng mỗi chuỗi chỉ lọc một lần trong một lượt xử lý:
 * Bảng xếp ca đọc mã ca / tên nhân viên hàng nghìn lần (mỗi ô, tooltip, bản chụp công bố…) và mỗi lần đọc SafeContent chạy lại
 * HTMLPurifier – phần chậm nhất khi mở bảng (đo 01/11/2026). Kết quả đọc giống hệt SafeContent; chỉ nhớ lại theo giá trị gốc.
 */
class SafeContent implements CastsAttributes
{
    /** Giới hạn bộ nhớ đệm (số chuỗi khác nhau) – đủ cho tên nhân viên / ca / outlet, tránh phình bộ nhớ khi chạy lâu. */
    private const LIMIT = 5000;

    /** @var array<string, string> */
    private static array $read = [];

    public function set($model, string $key, $value, array $attributes)
    {
        return BaseHelper::clean($value);
    }

    public function get($model, string $key, $value, array $attributes)
    {
        if (! $value) {
            return $value;
        }

        $raw = (string) $value;

        if (isset(self::$read[$raw])) {
            return self::$read[$raw];
        }

        if (count(self::$read) >= self::LIMIT) {
            self::$read = [];
        }

        return self::$read[$raw] = html_entity_decode(BaseHelper::clean($value));
    }
}
