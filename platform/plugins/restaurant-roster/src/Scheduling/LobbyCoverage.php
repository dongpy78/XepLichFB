<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Đếm người phủ Lobby theo số người CÓ MẶT CÙNG LÚC (quản lý chốt 22/10/2026):
 * khung định biên (vd 09:00–16:45, tối thiểu 2 nữ waiter) được chia thành các đoạn tại mọi mốc giờ vào / ra Lobby
 * của các ca (OH(L) 08:45–16:45, M9 09:00–17:00, M6(L) 09:00–14:00, A2(L) 14:00–17:00 → đoạn 09:00–14:00 và 14:00–16:45).
 * Trong mỗi đoạn, một người chỉ được tính khi có mặt ở Lobby TRỌN đoạn đó – nên "đủ ở mọi đoạn" đúng bằng
 * "lúc nào cũng có đủ số người cùng lúc". Hai mã ca hỗ trợ không làm cùng thời điểm (M6(L) + A2(L)) không bao giờ được tính là 2.
 */
final class LobbyCoverage
{
    /**
     * Chia [start, end] tại các mốc của những khoảng có mặt cắt ngang khung.
     *
     * @param array<int, array{0: int, 1: int}> $intervals
     * @return array<int, array{0: int, 1: int}>
     */
    public static function segments(int $start, int $end, array $intervals): array
    {
        $points = [$start => true, $end => true];

        foreach ($intervals as [$from, $to]) {
            foreach ([$from, $to] as $point) {
                if ($point > $start && $point < $end) {
                    $points[$point] = true;
                }
            }
        }

        $points = array_keys($points);
        sort($points);
        $segments = [];

        for ($n = 1; $n < count($points); $n++) {
            $segments[] = [$points[$n - 1], $points[$n]];
        }

        return $segments;
    }

    /**
     * Khoảng có mặt phủ trọn đoạn.
     */
    public static function covers(?array $interval, int $start, int $end): bool
    {
        return $interval !== null && $interval[0] <= $start && $interval[1] >= $end;
    }

    /**
     * Số người có mặt cùng lúc ít nhất trong khung và các đoạn còn thiếu so với tối thiểu.
     *
     * @param array<int, array{0: int, 1: int}> $presence khoảng có mặt ở Lobby của từng người được tính
     * @return array{segments: array<int, array{start: int, end: int, count: int}>, short: array<int, array{start: int, end: int, count: int}>, min_count: int}
     */
    public static function evaluate(int $start, int $end, int $min, array $presence): array
    {
        $segments = [];
        $short = [];

        foreach (self::segments($start, $end, $presence) as [$from, $to]) {
            $count = count(array_filter($presence, fn ($interval) => self::covers($interval, $from, $to)));
            $segments[] = $row = ['start' => $from, 'end' => $to, 'count' => $count];

            if ($count < $min) {
                $short[] = $row;
            }
        }

        return ['segments' => $segments, 'short' => $short, 'min_count' => $segments ? min(array_column($segments, 'count')) : 0];
    }

    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
