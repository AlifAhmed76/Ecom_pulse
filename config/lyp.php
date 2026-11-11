<?php

return [
    'cache_ttl' => (int) env('LYP_CACHE_TTL', 1800),
    'cache_lock_wait' => (int) env('LYP_CACHE_LOCK_WAIT', 5),
    'low_stock_threshold' => (int) env('LYP_LOW_STOCK_THRESHOLD', 10),

    'rnd' => [
        // Sample & statistical confidence rubric (RND_LAB_SPEC.md)
        'min_sample_info' => (int) env('LYP_RND_MIN_SAMPLE', 20),
        'confidence_medium_sample' => 30,
        'confidence_medium_p' => 0.05,
        'confidence_medium_z' => 1.96,
        'confidence_high_sample' => 100,
        'confidence_high_p' => 0.01,
        'confidence_high_z' => 2.58,
        'dq_high_min' => 85,
        'dq_low_max' => 60,

        // Severity rubric (% of gross sales over window)
        'severity_critical_pct' => 10.0,
        'severity_high_pct' => 5.0,
        'severity_medium_pct' => 1.0,

        // R01: Sales drop
        'r01_drop_pct' => 20.0,
        'r01_min_prev_orders' => 20,
        'r01_trailing_weeks' => 8,

        // R02: Cancellation spike
        'r02_min_rise_pp' => 5.0,
        'r02_min_orders' => 30,
        'r02_p_max' => 0.05,

        // R03: Return spike
        'r03_min_rise_pp' => 5.0,
        'r03_min_completed' => 30,
        'r03_p_max' => 0.05,

        // R04: Courier outlier
        'r04_min_shipments' => 30,
        'r04_p_max' => 0.05,

        // R05: Employee cancel outlier
        'r05_min_assigned' => 30,
        'r05_p_max' => 0.05,

        // R06: Stock risk
        'r06_cover_days' => 7.0,
        'r06_immediate_cover_days' => 2.0,
        'r06_top_share_fraction' => 0.20,
        'r06_min_sample' => 20,

        // R07: Dead stock
        'r07_dead_days' => 60,
        'r07_min_site_orders' => 20,

        // R08: Concentration
        'r08_top1_pct' => 40.0,
        'r08_top3_pct' => 70.0,
        'r08_min_orders' => 20,

        // R09: Margin compression
        'r09_margin_drop_pp' => 3.0,
        'r09_min_cost_coverage_pct' => 60.0,
        'r09_min_delivered' => 20,

        // R10: Discount impact
        'r10_min_completed_per_group' => 20,
        'r10_p_max' => 0.05,

        // R11: Repeat-rate decline
        'r11_drop_pp' => 5.0,
        'r11_min_buyers' => 50,

        // R13: Platform-vs-real ROAS gap (T15)
        'r13_min_coverage_pct' => 50.0,
        'r13_min_spend' => 1000.0,
        'r13_min_gap_ratio' => 1.5,
        'r13_min_gap_diff' => 0.5,
    ],
];
