<?php

return [
    'service_url' => env('PLAYWRIGHT_SERVICE_URL', 'http://localhost:3000'),
    'timeout' => env('PLAYWRIGHT_TIMEOUT', 30),
    'response_mode' => env('PLAYWRIGHT_RESPONSE_MODE', 'html'),
    'auto_start' => env('PLAYWRIGHT_AUTO_START', false),
];
