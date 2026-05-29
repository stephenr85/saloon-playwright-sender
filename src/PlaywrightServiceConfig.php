<?php

declare(strict_types=1);

namespace Rushing\SaloonPlaywright;

class PlaywrightServiceConfig
{
    /**
     * @param  string  $serviceUrl  Base URL of the Playwright Node.js microservice.
     * @param  int  $timeout  Request timeout in seconds.
     * @param  string  $responseMode  'html' returns the rendered DOM; 'body' returns the raw network response body.
     */
    public function __construct(
        public readonly string $serviceUrl = 'http://localhost:3000',
        public readonly int $timeout = 30,
        public readonly string $responseMode = 'html',
        public readonly bool $autoStart = false,
    ) {
    }
}
