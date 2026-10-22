<?php

namespace Botble\RestaurantRoster\Scheduling;

/**
 * Tham số ràng buộc và giới hạn chạy. Giá trị mặc định lấy từ config general.scheduling.
 */
final class Rules
{
    /**
     * Trọng số mặc định của các mục tiêu (điểm phạt cho mỗi đơn vị), chia 3 nhóm:
     *  - Bắt buộc: luật lao động, định biên tối thiểu, đúng số ngày OFF – cách nhau nhiều bậc nên không bao giờ đổi lấy nhóm dưới.
     *  - Quan trọng, theo thứ tự: quy tắc phân người của quản lý (Kid-Deline chỉ mượn người khi Gusto thiếu,
     *    luân phiên ca đêm) → đủ người theo định biên mục tiêu → cân bằng quản lý M6 / A2 → hạn chế OFF thứ Bảy → OFF liền nhau → xen kẽ sáng/chiều → tránh ca nặng → chia đều Lobby.
     *    Quyết định của quản lý 21/10/2026: đủ người theo mục tiêu được ưu tiên hơn OFF liền nhau và tránh ca nặng
     *    (1 chỗ thiếu mục tiêu ≈ 3 ngày OFF lẻ ≈ 5 lần chuyển ca nặng).
     *  - Nên có: công bằng, OFF liền 2–3 ngày, sở thích & ổn định.
     */
    public const DEFAULT_WEIGHTS = [
        // Bắt buộc
        'hard' => 1e15,
        'source_shortage' => 1e13,
        'min_shortage' => 1e11,
        'under_norm' => 1e8,
        'over_norm' => 1e8,
        // Quan trọng
        'child_borrow' => 60000,
        'night_rotation' => 30000,
        'target_shortage' => 10000,
        // Cấp quản lý: số quản lý ca sáng (M6) và chiều (A2) cân bằng theo outlet × ngày – không đổi lấy thiếu người.
        'manager_balance' => 8000,
        // Thứ Bảy đông khách: hạn chế OFF thứ Bảy, nhưng không đổi lấy thiếu người ngày khác.
        'busy_day_off' => 4000,
        'off_split' => 3000,
        'shift_mix' => 2500,
        'heavy_transitions' => 2000,
        'lobby_mix' => 1500,
        // Lobby: ưu tiên OH(L) (Nha Trang) + M9(L) (Cam Ranh) phủ trọn 09:00–16:45; M6(L) / A2(L) chỉ phủ một phần khung
        // là phương án thay thế – mỗi lượt bị phạt, nên chỉ dùng khi bù được thiếu người.
        'lobby_partial' => 1200,
        // Người luân phiên Night làm đêm thứ 3 trong tuần: chỉ khi cần để Night đủ 3 người / ngày (mục tiêu 2 đêm / người).
        'night_extra' => 3000,
        // Mượn người outlet khác (BorrowPolicy): chỉ để đủ tối thiểu – mượn vượt phần thiếu tối thiểu nặng hơn thiếu mục tiêu;
        // mượn người không phải nhân viên đa năng Night khi người đa năng làm được: phạt nhẹ (ưu tiên đa năng trước).
        'borrow_excess' => 20000,
        'borrow_order' => 400,
        // Cân bằng ca sáng / chiều qua nhiều tuần (lịch sử khoảng đánh giá + kỳ này) – nhẹ hơn xen kẽ ca trong tuần.
        'shift_history' => 500,
        // Nên có: công bằng → OFF liền 2–3 ngày → sở thích & ổn định.
        'fairness' => 5,
        'off_comfort' => 20,
        'preference' => 2,
    ];

    /**
     * Pha dựng khung (đầu quá trình tìm kiếm): OFF liền nhau tạm xếp trên "thiếu mục tiêu" để dựng được khung OFF gọn
     * (gom OFF thường phải tạm làm hụt một ca rồi bù lại). Sau đó tìm kiếm chạy với trọng số thật và chỉ đổi OFF lẻ
     * lấy đủ người khi thật sự có lợi. Phương án tốt nhất luôn được chấm theo trọng số thật.
     */
    public const FRAME_PHASE_WEIGHTS = ['off_split' => 15000];

    public const FRAME_PHASE_SHARE = 0.4;

    /**
     * Tối ưu lại lịch đang dùng: mỗi ô thay đổi (4 điểm "preference") đắt hơn một lần chuyển ca nặng
     * nhưng rẻ hơn một ngày OFF lẻ hay một chỗ thiếu người – một người nghỉ đột xuất không làm xáo trộn cả tuần.
     */
    public const STABILITY_PREFERENCE_WEIGHT = 600;

    /**
     * @param array<string, string> $transitions "fromShiftId:toShiftId" => forbidden|heavy|allowed (ghi đè quy tắc theo giờ nghỉ)
     * @param array<string, float> $weightOverrides Ghi đè trọng số mục tiêu (khóa như DEFAULT_WEIGHTS)
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
        // Tối ưu lại lịch đang dùng: "ít thay đổi" có trọng số cao để không xáo trộn người không liên quan.
        public readonly bool $stabilityFirst = false,
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
        public readonly array $weightOverrides = [],
        // Tìm kiếm "luyện kim" (simulated annealing): nhiệt độ đầu / cuối tính theo điểm phạt.
        // Đầu kỳ chấp nhận tạm bước xấu hơn cỡ một ngày OFF lẻ để thoát chỗ kẹt; cuối kỳ gần như chỉ nhận bước tốt hơn.
        public readonly float $annealStartTemperature = 2000,
        public readonly float $annealEndTemperature = 5,
    ) {
    }

    /**
     * Trọng số đang áp dụng cho từng mục tiêu.
     *
     * @return array<string, float>
     */
    public function weights(): array
    {
        $weights = self::DEFAULT_WEIGHTS;

        if ($this->stabilityFirst) {
            $weights['preference'] = self::STABILITY_PREFERENCE_WEIGHT;
        }

        foreach ($this->weightOverrides as $label => $weight) {
            if (array_key_exists($label, $weights) && is_numeric($weight)) {
                $weights[$label] = (float) $weight;
            }
        }

        return $weights;
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
            daysOffMode: (string) ($config['days_off_mode'] ?? 'weekly'),
            minOffPerWeek: (int) ($config['min_off_per_week'] ?? 1),
            supportGender: $config['support_gender'] ?? null,
            maxSupportPerOutlet: (int) ($config['max_support_per_outlet'] ?? 2),
            busyWeekdays: array_map('intval', $config['busy_weekdays'] ?? [6]),
            shiftMixTolerance: (int) ($config['shift_mix_tolerance'] ?? 1),
            weightOverrides: (array) ($config['weights'] ?? []),
            annealStartTemperature: (float) ($config['anneal_start_temperature'] ?? 2000),
            annealEndTemperature: (float) ($config['anneal_end_temperature'] ?? 5),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this) + ['weights' => $this->weights()];
    }
}
