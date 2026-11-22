<?php

return [
    'timezone' => env('POS_TIMEZONE', 'Asia/Manila'),
    'low_stock_threshold' => (int) env('POS_LOW_STOCK_THRESHOLD', 5),
    'dashboard_cache_seconds' => (int) env('POS_DASHBOARD_CACHE_SECONDS', 30),
];
