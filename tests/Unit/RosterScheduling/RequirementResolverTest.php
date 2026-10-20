<?php

namespace Tests\Unit\RosterScheduling;

use Botble\RestaurantRoster\Scheduling\RequirementResolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyntheticRoster.php';

/**
 * Chọn định biên theo ngày – DỮ LIỆU GIẢ LẬP.
 */
class RequirementResolverTest extends TestCase
{
    use SyntheticRoster;

    private function rule(array $overrides): array
    {
        return $overrides + [
            'outlet_id' => self::VILLA, 'shift_template_id' => self::M6, 'weekday' => null, 'date' => null, 'upper_floor' => null,
            'min_staff' => 3, 'target_staff' => 4, 'max_staff' => null, 'min_male' => 1, 'target_male' => 1, 'allow_reduce' => true,
        ];
    }

    /** 8 & 9. Villa mở / không mở tầng trên dùng định biên nam khác nhau. */
    public function test_villa_upper_floor_switches_male_requirement(): void
    {
        $resolver = new RequirementResolver([
            $this->rule(['upper_floor' => false, 'min_male' => 1, 'target_male' => 2]),
            $this->rule(['upper_floor' => true, 'min_staff' => 4, 'target_staff' => 5, 'min_male' => 2, 'target_male' => 2]),
        ]);

        $closedFloor = $resolver->resolve($this->day(0), self::VILLA, self::M6, upperFloorOpen: false);
        $openFloor = $resolver->resolve($this->day(0), self::VILLA, self::M6, upperFloorOpen: true);

        $this->assertSame([1, 2], [$closedFloor->minMale, $closedFloor->targetMale]);
        $this->assertSame([2, 2, 4], [$openFloor->minMale, $openFloor->targetMale, $openFloor->min]);
    }

    /** 19. Quản lý đổi định biên giữa kỳ: dòng cho ngày cụ thể thắng dòng theo thứ và dòng chung. */
    public function test_date_override_beats_weekday_and_generic_rules(): void
    {
        $resolver = new RequirementResolver([
            $this->rule(['min_staff' => 2, 'target_staff' => 2]),
            $this->rule(['weekday' => 6, 'min_staff' => 4, 'target_staff' => 5]),
            $this->rule(['date' => $this->day(5), 'min_staff' => 6, 'target_staff' => 7]),
        ]);

        $this->assertSame(2, $resolver->resolve($this->day(0), self::VILLA, self::M6)->min);
        $this->assertSame(6, $resolver->resolve($this->day(5), self::VILLA, self::M6)->min, 'Thứ Bảy có dòng ngày cụ thể');
        $this->assertSame(4, $resolver->resolve($this->day(12), self::VILLA, self::M6)->min, 'Thứ Bảy tuần sau dùng dòng theo thứ');
    }

    public function test_closed_outlet_has_no_requirement_and_target_never_below_min(): void
    {
        $resolver = new RequirementResolver([$this->rule(['min_staff' => 3, 'target_staff' => 1])]);

        $this->assertNull($resolver->resolve($this->day(0), self::VILLA, self::M6, closed: true));
        $this->assertSame(3, $resolver->resolve($this->day(0), self::VILLA, self::M6)->target);
        $this->assertNull($resolver->resolve($this->day(0), self::GUSTO, self::M6), 'Outlet không có dòng định biên');
    }
}
