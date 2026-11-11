<?php

namespace App\Support;

final class Stats
{
    /**
     * Pooled two-proportion z-test (two-tailed).
     *
     * @return ?array{p1: float, p2: float, pooled_p: float, diff_pp: float, se: float, z: float, p_value: float}
     */
    public static function twoProportionZTest(int $s1, int $n1, int $s2, int $n2): ?array
    {
        if ($n1 <= 0 || $n2 <= 0 || $s1 < 0 || $s1 > $n1 || $s2 < 0 || $s2 > $n2) {
            return null;
        }

        $p1 = $s1 / $n1;
        $p2 = $s2 / $n2;
        $pPool = ($s1 + $s2) / ($n1 + $n2);
        $diffPp = ($p1 - $p2) * 100.0;

        if ($pPool <= 0.0 || $pPool >= 1.0) {
            return [
                'p1' => round($p1, 6),
                'p2' => round($p2, 6),
                'pooled_p' => round($pPool, 6),
                'diff_pp' => round($diffPp, 2),
                'se' => 0.0,
                'z' => 0.0,
                'p_value' => 1.0,
            ];
        }

        $se = sqrt($pPool * (1.0 - $pPool) * ((1.0 / $n1) + (1.0 / $n2)));
        if ($se <= 0.0) {
            return [
                'p1' => round($p1, 6),
                'p2' => round($p2, 6),
                'pooled_p' => round($pPool, 6),
                'diff_pp' => round($diffPp, 2),
                'se' => 0.0,
                'z' => 0.0,
                'p_value' => 1.0,
            ];
        }

        $z = ($p1 - $p2) / $se;
        $pValue = self::twoTailedNormalPValue(abs($z));

        return [
            'p1' => round($p1, 6),
            'p2' => round($p2, 6),
            'pooled_p' => round($pPool, 6),
            'diff_pp' => round($diffPp, 2),
            'se' => round($se, 6),
            'z' => round($z, 4),
            'p_value' => round($pValue, 6),
        ];
    }

    /**
     * Sample z-score of $latest compared against a trailing weekly baseline series.
     * Requires at least $minPoints (default 8) points in the trailing baseline.
     *
     * @param  list<int|float>  $trailingSeries
     * @return ?array{n: int, mean: float, sd: float, latest: float, z: ?float, p_value: ?float}
     */
    public static function trailingZScore(array $trailingSeries, float $latest, int $minPoints = 8): ?array
    {
        $vals = array_values(array_map(static fn ($v): float => (float) $v, $trailingSeries));
        $n = count($vals);

        if ($n < max(2, $minPoints)) {
            return null;
        }

        $mean = array_sum($vals) / $n;
        $sqSum = 0.0;
        foreach ($vals as $v) {
            $d = $v - $mean;
            $sqSum += $d * $d;
        }
        $sd = sqrt($sqSum / ($n - 1));

        if ($sd <= 0.0) {
            return [
                'n' => $n,
                'mean' => round($mean, 4),
                'sd' => 0.0,
                'latest' => round($latest, 4),
                'z' => $latest == $mean ? 0.0 : null,
                'p_value' => $latest == $mean ? 1.0 : null,
            ];
        }

        $z = ($latest - $mean) / $sd;
        $pValue = self::twoTailedNormalPValue(abs($z));

        return [
            'n' => $n,
            'mean' => round($mean, 4),
            'sd' => round($sd, 4),
            'latest' => round($latest, 4),
            'z' => round($z, 4),
            'p_value' => round($pValue, 6),
        ];
    }

    /**
     * Convenience helper when the latest point is appended at the end of a full chronological series.
     *
     * @param  list<int|float>  $series
     * @return ?array{n: int, mean: float, sd: float, latest: float, z: ?float, p_value: ?float}
     */
    public static function latestZScoreFromSeries(array $series, int $minTrailingPoints = 8): ?array
    {
        $values = array_values($series);
        if (count($values) < ($minTrailingPoints + 1)) {
            return null;
        }

        $latest = (float) array_pop($values);

        return self::trailingZScore($values, $latest, $minTrailingPoints);
    }

    /**
     * Percentage change from $previous to $current with minimum baseline / volume guards.
     */
    public static function percentChange(
        float $previous,
        float $current,
        ?int $observedVolume = null,
        int $minVolume = 0,
        float $minPreviousValue = 0.0
    ): ?float {
        if ($previous <= 0.0) {
            return null;
        }
        if ($minPreviousValue > 0.0 && $previous < $minPreviousValue) {
            return null;
        }
        if ($observedVolume !== null && $minVolume > 0 && $observedVolume < $minVolume) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100.0, 2);
    }

    /**
     * Wilson score confidence interval for a binomial proportion.
     *
     * @return ?array{rate: float, rate_pct: float, low: float, high: float, low_pct: float, high_pct: float, n: int}
     */
    public static function wilsonInterval(int $successes, int $total, float $z = 1.959963984540054): ?array
    {
        if ($total <= 0 || $successes < 0 || $successes > $total || $z <= 0.0) {
            return null;
        }

        $p = $successes / $total;
        $z2 = $z * $z;
        $denom = 1.0 + ($z2 / $total);
        $center = ($p + ($z2 / (2.0 * $total))) / $denom;
        $half = ($z * sqrt(($p * (1.0 - $p) + ($z2 / (4.0 * $total))) / $total)) / $denom;

        $low = max(0.0, $center - $half);
        $high = min(1.0, $center + $half);

        return [
            'rate' => round($p, 6),
            'rate_pct' => round($p * 100.0, 2),
            'low' => round($low, 6),
            'high' => round($high, 6),
            'low_pct' => round($low * 100.0, 2),
            'high_pct' => round($high * 100.0, 2),
            'n' => $total,
        ];
    }

    /**
     * Two-tailed p-value from standard normal |z|: erfc(|z| / sqrt(2)).
     * Abramowitz & Stegun formula 7.1.26 (max error 1.5e-7).
     */
    public static function twoTailedNormalPValue(float $absZ): float
    {
        if ($absZ <= 0.0) {
            return 1.0;
        }

        $x = $absZ / M_SQRT2;
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $poly = $t * (
            0.254829592 + $t * (
                -0.284496736 + $t * (
                    1.421413741 + $t * (
                        -1.453152027 + $t * 1.061405429
                    )
                )
            )
        );
        $erfc = $poly * exp(-$x * $x);

        return max(0.0, min(1.0, $erfc));
    }
}
