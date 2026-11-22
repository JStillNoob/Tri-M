<?php

use Laravel\Sanctum\Sanctum;

return [
    'stateful' => array_values(array_filter(array_map('trim', explode(',', env(
        'SANCTUM_STATEFUL_DOMAINS',
        (env('APP_ENV', 'production') === 'local'
            ? 'localhost:5173,127.0.0.1:5173,localhost:8000,127.0.0.1:8000'
            : '').Sanctum::currentApplicationUrlWithPort(),
    ))))),
];
