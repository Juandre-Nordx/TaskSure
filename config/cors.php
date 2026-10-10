<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', env('MOBILE_ALLOWED_ORIGINS', 'capacitor://localhost,https://localhost'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
