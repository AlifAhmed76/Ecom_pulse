<?php

return [
    'cache_ttl' => (int) env('LYP_CACHE_TTL', 1800),
    'cache_lock_wait' => (int) env('LYP_CACHE_LOCK_WAIT', 5),
    'low_stock_threshold' => (int) env('LYP_LOW_STOCK_THRESHOLD', 10),
];
