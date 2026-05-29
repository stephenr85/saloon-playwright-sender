<?php

declare(strict_types=1);

namespace Rushing\SaloonPlaywright;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Saloon\Contracts\Sender;
use Saloon\Data\FactoryCollection;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Http\Senders\Factories\GuzzleMultipartBodyFactory;

class PlaywrightSender implements Sender
{
    private Client $guzzle;

    public function __construct(
        private readonly PlaywrightServiceConfig $config = new PlaywrightServiceConfig(),
        ?Client $guzzle = null,
    ) {
        $this->guzzle = $guzzle ?? new Client(['timeout' => $this->config->timeout]);
    }

    public function getFactoryCollection(): FactoryCollection
    {
        $factory = new HttpFactory();

        return new FactoryCollection(
            requestFactory: $factory,
            uriFactory: $factory,
            streamFactory: $factory,
            responseFactory: $factory,
            multipartBodyFactory: new GuzzleMultipartBodyFactory(),
        );
    }

    public function send(PendingRequest $pendingRequest): Response
    {
        if ($this->config->autoStart) {
            $this->ensureServiceRunning();
        }

        $psrRequest = $pendingRequest->createPsrRequest();

        /** @var string|null $script */
        $script = $pendingRequest->config()->get('playwright_script');

        $serviceResponse = $this->guzzle->post($this->config->serviceUrl.'/navigate', [
            'json' => array_filter([
                'url' => (string) $pendingRequest->getUrl(),
                'method' => $pendingRequest->getMethod()->value,
                'headers' => $this->flattenHeaders($pendingRequest->headers()->all()),
                'mode' => $this->config->responseMode,
                'script' => $script,
            ], fn (mixed $v) => $v !== null),
        ]);

        $data = json_decode((string) $serviceResponse->getBody(), true);

        $status = is_array($data) ? (int) ($data['status'] ?? 200) : 200;
        $headers = is_array($data) && is_array($data['headers'] ?? null) ? $data['headers'] : [];
        $body = is_array($data)
            ? match ($this->config->responseMode) {
                'body' => (string) ($data['body'] ?? ''),
                default => (string) ($data['html'] ?? ''),
            }
        : '';

        $psrResponse = new GuzzleResponse(
            status: $status,
            headers: $headers,
            body: $body,
        );

        /** @var class-string<Response> $responseClass */
        $responseClass = $pendingRequest->getResponseClass();

        return $responseClass::fromPsrResponse($psrResponse, $pendingRequest, $psrRequest);
    }

    public function sendAsync(PendingRequest $pendingRequest): PromiseInterface
    {
        return Create::promiseFor($this->send($pendingRequest));
    }

    private function ensureServiceRunning(): void
    {
        try {
            $this->guzzle->get($this->config->serviceUrl.'/health', ['timeout' => 2]);

            return;
        } catch (\Throwable) {
            // Service not responding; attempt to start it
        }

        $lockPath = sys_get_temp_dir().'/playwright-sender-'.md5($this->config->serviceUrl).'.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            return;
        }

        flock($lock, LOCK_EX);

        try {
            // Another worker may have started the service while we waited for the lock
            try {
                $this->guzzle->get($this->config->serviceUrl.'/health', ['timeout' => 2]);

                return;
            } catch (\Throwable) {
                // Still down; we start it
            }

            $serviceDir = dirname(__DIR__).'/playwright-service';
            $port = (int) (parse_url($this->config->serviceUrl, PHP_URL_PORT) ?? 3000);

            exec(sprintf(
                'PORT=%d nohup node %s/index.js > /dev/null 2>&1 &',
                $port,
                escapeshellarg($serviceDir),
            ));

            $ready = false;

            for ($i = 0; $i < 20; $i++) {
                usleep(500_000);

                try {
                    $this->guzzle->get($this->config->serviceUrl.'/health', ['timeout' => 1]);
                    $ready = true;
                    break;
                } catch (\Throwable) {
                    // Still starting up
                }
            }

            if (! $ready) {
                throw new \RuntimeException(
                    "Playwright service at {$this->config->serviceUrl} could not be started. ".
                    "Run manually: cd {$serviceDir} && npm install && node index.js",
                );
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Flatten PSR-7 multi-value headers into single string values.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        return array_map(
            fn (mixed $value) => is_array($value) ? implode(', ', $value) : (string) $value,
            $headers,
        );
    }
}
