<?php
namespace Tests\Unit\RosterScheduling;
require_once __DIR__ . '/RestaurantTransferTest.php';
class TmpProbeTest extends RestaurantTransferTest
{
    public function test_probe(): void
    {
        $sunday = $this->day(6);
        $solve = new \ReflectionMethod(RestaurantTransferTest::class, 'solveWeek'); $solve->setAccessible(true);
        $split = new \ReflectionMethod(RestaurantTransferTest::class, 'homeSplit'); $split->setAccessible(true);
        foreach (['old' => ['weights' => ['home_balance' => 0]], 'new' => []] as $label => $opt) {
            [$r] = $solve->invoke($this, [self::VILLA => [$sunday => true]], $opt);
            fwrite(STDERR, $label . ' Gusto own ' . json_encode($split->invoke($this, $r, $sunday, self::GUSTO)) . ' Villa moved ' . json_encode($split->invoke($this, $r, $sunday, self::VILLA)) . PHP_EOL);
        }
        $this->assertTrue(true);
    }
}
