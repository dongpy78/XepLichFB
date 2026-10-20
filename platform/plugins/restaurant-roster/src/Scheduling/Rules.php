<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Tham số ràng buộc và giới hạn chạy. Giá trị mặc định lấy từ config general.scheduling.
 */
final class Rules
{
    /**
     * @param array<string, string> $transitions "fromShiftId:toShiftId" => forbidden|heavy|allowed (ghi đè quy tắc theo giờ nghỉ)
     */
    public function __construct(
        // Nghỉ dưới mức này giữa hai ca liên tiếp là vi phạm cứng.
        public readonly float $hardMinRestHours = 8,
        // Nghỉ dưới mức này (nhưng >= mức cứng) được phép nhưng tính là "ca nặng".
        public readonly float $softMinRestHours = 12,
        public readonly float $maxDailyHours = 12,
        public readonly int $maxConsecutiveDays = 6,
        public readonly array $transitions = [],
        public readonly float $timeLimitSeconds = 8,
        public readonly int $maxIterations = 200000,
        public readonly int $seed = 20261012,
        // Tối ưu lại lịch đang dùng: "ít thay đổi" được xếp trên "OFF liền nhau" để không xáo trộn người không liên quan.
        public readonly bool $stabilityFirst = false,
        public readonly float $coverageOverlapRatio = 0.5,
        // weekly: đủ số ngày OFF trong từng tuần; fortnight: đủ trong mỗi cặp 2 tuần, mỗi tuần ít nhất minOffPerWeek ngày.
        public readonly string $daysOffMode = 'weekly',
        public readonly int $minOffPerWeek = 1,
        // Hỗ trợ Lobby (L) chỉ giao cho nhân viên giới tính này (null = không giới hạn).
        public readonly ?string $supportGender = null,
        // Mỗi outlet × ca × ngày góp tối đa bấy nhiêu người hỗ trợ Lobby (L).
        public readonly int $maxSupportPerOutlet = 2,
        /** @var int[] Thứ đông khách (ISO, 6 = thứ Bảy): hạn chế xếp OFF vào những ngày này. */
        public readonly array $busyWeekdays = [6],
        // Người không có ca cứng: số ca sáng và chiều trong tuần lệch nhau tối đa bấy nhiêu (1 → 5 ngày chia 3/2).
        public readonly int $shiftMixTolerance = 1,
    ) {
    }

    public static function fromConfig(array $config, array $transitions = []): self
    {
        return new self(
            hardMinRestHours: (float) ($config['hard_min_rest_hours'] ?? 8),
            softMinRestHours: (float) ($config['soft_min_rest_hours'] ?? 12),
            maxDailyHours: (float) ($config['max_daily_hours'] ?? 12),
            maxConsecutiveDays: (int) ($config['max_consecutive_days'] ?? 6),
            transitions: $transitions,
            timeLimitSeconds: (float) ($config['time_limit_seconds'] ?? 8),
            maxIterations: (int) ($config['max_iterations'] ?? 200000),
            seed: (int) ($config['seed'] ?? 20261012),
            stabilityFirst: (bool) ($config['stability_first'] ?? false),
            coverageOverlapRatio: (float) ($config['coverage_overlap_ratio'] ?? 0.5),
            daysOffMode: (string) ($config['days_off_mode'] ?? 'weekly'),
            minOffPerWeek: (int) ($config['min_off_per_week'] ?? 1),
            supportGender: $config['support_gender'] ?? null,
            maxSupportPerOutlet: (int) ($config['max_support_per_outlet'] ?? 2),
            busyWeekdays: array_map('intval', $config['busy_weekdays'] ?? [6]),
            shiftMixTolerance: (int) ($config['shift_mix_tolerance'] ?? 1),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
