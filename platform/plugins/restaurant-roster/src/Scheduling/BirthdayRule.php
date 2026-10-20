<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Quy định resort: nhân viên được OFF đúng ngày sinh nhật.
 */
final class BirthdayRule
{
    /**
     * Các ngày trong phạm vi trùng ngày/tháng sinh.
     *
     * @param string|null $birthday Dạng "d/m" (vd "6/2", "28/10")
     * @param string[] $days Các ngày Y-m-d
     * @return string[]
     */
    public static function offDates(?string $birthday, array $days): array
    {
        if (! $birthday || ! preg_match('/^(\d{1,2})\/(\d{1,2})$/', trim($birthday), $match)) {
            return [];
        }

        $key = sprintf('%02d-%02d', (int) $match[2], (int) $match[1]);

        return array_values(array_filter($days, fn (string $date) => substr($date, 5, 5) === $key));
    }
}
