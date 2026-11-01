<?php

namespace Botble\RestaurantRoster\Models\Concerns;

use Botble\Base\Facades\MacroableModels;
use Illuminate\Database\Eloquent\Model;

/**
 * Đọc thuộc tính nhanh (tối ưu 01/11/2026, đo trên bảng xếp ca 54 nhân viên): mỗi lần đọc một thuộc tính model,
 *  - Laravel gọi getCasts() → getIncrementing() / getKeyType(), mà HasUuidsOrIntegerIds của Botble đọc config kiểu ID tới 6 lần
 *    (≈ 15 µs / lần đọc – phần lớn thời gian mở bảng xếp ca);
 *  - BaseModel::__get hỏi MacroableModels (facade + Str::studly + is_subclass_of) để tìm macro "get…Attribute".
 * Kiểu ID là cấu hình cố định trong một lượt xử lý → đọc một lần. Model của plugin không đăng ký macro nào → kiểm tra một lần cho mỗi
 * lớp rồi đọc thẳng như Laravel; nếu sau này có macro cho model này, hành vi trở lại như BaseModel. Kết quả đọc không đổi.
 */
trait FastAttributes
{
    /** @var array<class-string, bool> */
    private static array $macroFree = [];

    /** @var array<class-string, string> */
    private static array $typeOfId = [];

    public static function getTypeOfId(): string
    {
        return self::$typeOfId[static::class] ??= parent::getTypeOfId();
    }

    public function __get($key)
    {
        if (self::$macroFree[static::class] ??= MacroableModels::macrosForModel(static::class) === []) {
            return Model::__get($key);
        }

        return parent::__get($key);
    }
}
