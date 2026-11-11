<?php

namespace Tests\Unit;

use App\Support\Stats;
use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase
{
    public function test_two_proportion_z_test_matches_known_values(): void
    {
        // Known: 20/100 (0.20) vs 50/100 (0.50)
        // p_pool = 0.35, se = sqrt(0.35 * 0.65 * (1/100 + 1/100)) = 0.06745369, z = -4.4475
        $res = Stats::twoProportionZTest(20, 100, 50, 100);
        $this->assertNotNull($res);
        $this->assertEqualsWithDelta(0.20, $res['p1'], 0.0001);
        $this->assertEqualsWithDelta(0.50, $res['p2'], 0.0001);
        $this->assertEqualsWithDelta(0.35, $res['pooled_p'], 0.0001);
        $this->assertEqualsWithDelta(-30.0, $res['diff_pp'], 0.01);
        $this->assertEqualsWithDelta(0.067454, $res['se'], 0.0001);
        $this->assertEqualsWithDelta(-4.4475, $res['z'], 0.005);
        $this->assertLessThan(0.0001, $res['p_value']);

        // Equal proportions give z=0, p=1
        $equal = Stats::twoProportionZTest(10, 50, 10, 50);
        $this->assertSame(0.0, $equal['z']);
        $this->assertSame(1.0, $equal['p_value']);

        // Boundary 0% in both groups does not divide by zero
        $zero = Stats::twoProportionZTest(0, 30, 0, 30);
        $this->assertSame(1.0, $zero['p_value']);

        // Invalid inputs return null
        $this->assertNull(Stats::twoProportionZTest(5, 0, 5, 10));
        $this->assertNull(Stats::twoProportionZTest(15, 10, 5, 10));
    }

    public function test_trailing_z_score_requires_minimum_eight_points_and_matches_known_values(): void
    {
        // Fewer than 8 points -> null
        $this->assertNull(Stats::trailingZScore([100, 102, 98, 100, 101, 99, 100], 80.0));
        $this->assertNull(Stats::latestZScoreFromSeries([100, 102, 98, 100, 101, 99, 100, 80]));

        // Trailing 8 points: [10, 12, 8, 10, 11, 9, 10, 10] -> mean=10, var=10/7=1.428571, sd=1.195229
        // latest=4 -> z = (4 - 10) / 1.195229 = -5.01996
        $series = [10, 12, 8, 10, 11, 9, 10, 10, 4];
        $res = Stats::latestZScoreFromSeries($series, 8);
        $this->assertNotNull($res);
        $this->assertSame(8, $res['n']);
        $this->assertEqualsWithDelta(10.0, $res['mean'], 0.0001);
        $this->assertEqualsWithDelta(1.1952, $res['sd'], 0.001);
        $this->assertEqualsWithDelta(-5.0200, $res['z'], 0.01);
        $this->assertLessThan(0.001, $res['p_value']);
    }

    public function test_percent_change_enforces_zero_and_min_volume_guards(): void
    {
        $this->assertSame(-25.0, Stats::percentChange(200.0, 150.0, 25, 20));
        $this->assertSame(50.0, Stats::percentChange(100.0, 150.0, 20, 20));

        // Zero previous or below min volume -> null
        $this->assertNull(Stats::percentChange(0.0, 100.0));
        $this->assertNull(Stats::percentChange(200.0, 100.0, 19, 20));
        $this->assertNull(Stats::percentChange(50.0, 10.0, null, 0, 100.0));
    }

    public function test_wilson_interval_matches_known_values(): void
    {
        // 50/100 at 95% (z=1.959964) -> [40.38%, 59.62%]
        $ci = Stats::wilsonInterval(50, 100);
        $this->assertNotNull($ci);
        $this->assertSame(50.0, $ci['rate_pct']);
        $this->assertEqualsWithDelta(40.38, $ci['low_pct'], 0.05);
        $this->assertEqualsWithDelta(59.62, $ci['high_pct'], 0.05);

        $zero = Stats::wilsonInterval(0, 30);
        $this->assertSame(0.0, $zero['low_pct']);
        $this->assertGreaterThan(5.0, $zero['high_pct']);

        $this->assertNull(Stats::wilsonInterval(1, 0));
    }
}
