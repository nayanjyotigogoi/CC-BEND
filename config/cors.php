<?php

/*
 * Which websites may call this API from the browser. Locally any origin; in production set
 * CORS_ALLOWED_ORIGINS to the café sites, comma separated (e.g. https://tohfaah.com,https://www.tohfaah.com).
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '*'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
