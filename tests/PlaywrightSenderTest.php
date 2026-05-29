<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Rushing\SaloonPlaywright\PlaywrightSender;
use Rushing\SaloonPlaywright\PlaywrightServiceConfig;
use Saloon\Data\FactoryCollection;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeGuzzleMock(int $status, array $body): Client
{
    $mock = new MockHandler([
        new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode($body)),
    ]);

    return new Client(['handler' => HandlerStack::create($mock)]);
}

function makePendingRequest(string $baseUrl = 'https://example.com', string $endpoint = '/test'): PendingRequest
{
    $connector = new class ($baseUrl) extends Connector {
        public function __construct(private string $base)
        {
        }

        public function resolveBaseUrl(): string
        {
            return $this->base;
        }
    };

    $request = new class ($endpoint) extends Request {
        protected Method $method = Method::GET;

        public function __construct(private string $endpoint)
        {
        }

        public function resolveEndpoint(): string
        {
            return $this->endpoint;
        }
    };

    return new PendingRequest($connector, $request);
}

// ---------------------------------------------------------------------------
// PlaywrightServiceConfig
// ---------------------------------------------------------------------------

describe('PlaywrightServiceConfig', function () {
    it('uses sensible defaults', function () {
        $config = new PlaywrightServiceConfig();

        expect($config->serviceUrl)->toBe('http://localhost:3000')
            ->and($config->timeout)->toBe(30)
            ->and($config->responseMode)->toBe('html');
    });

    it('accepts custom values', function () {
        $config = new PlaywrightServiceConfig(
            serviceUrl: 'http://playwright:4000',
            timeout: 60,
            responseMode: 'body',
        );

        expect($config->serviceUrl)->toBe('http://playwright:4000')
            ->and($config->timeout)->toBe(60)
            ->and($config->responseMode)->toBe('body');
    });
});

// ---------------------------------------------------------------------------
// PlaywrightSender
// ---------------------------------------------------------------------------

describe('PlaywrightSender', function () {
    it('returns a valid FactoryCollection', function () {
        $sender = new PlaywrightSender();

        expect($sender->getFactoryCollection())->toBeInstanceOf(FactoryCollection::class);
    });

    it('sends a request and returns a Saloon Response with html body', function () {
        $servicePayload = [
            'status' => 200,
            'headers' => ['content-type' => 'text/html'],
            'html' => '<html><body>Hello</body></html>',
            'body' => null,
        ];

        $sender = new PlaywrightSender(
            config: new PlaywrightServiceConfig(responseMode: 'html'),
            guzzle: makeGuzzleMock(200, $servicePayload),
        );

        $response = $sender->send(makePendingRequest());

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('<html><body>Hello</body></html>');
    });

    it('sends a request and returns a Saloon Response with raw body', function () {
        $servicePayload = [
            'status' => 200,
            'headers' => ['content-type' => 'application/json'],
            'html' => null,
            'body' => '{"id":1}',
        ];

        $sender = new PlaywrightSender(
            config: new PlaywrightServiceConfig(responseMode: 'body'),
            guzzle: makeGuzzleMock(200, $servicePayload),
        );

        $response = $sender->send(makePendingRequest());

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('{"id":1}');
    });

    it('passes non-200 status codes through from the service', function () {
        $servicePayload = [
            'status' => 404,
            'headers' => [],
            'html' => '<html>Not Found</html>',
            'body' => null,
        ];

        $sender = new PlaywrightSender(
            guzzle: makeGuzzleMock(200, $servicePayload),
        );

        $response = $sender->send(makePendingRequest());

        expect($response->status())->toBe(404)
            ->and($response->failed())->toBeTrue();
    });

    it('forwards the target URL to the Node service', function () {
        $capturedBody = null;

        $mock = new MockHandler([
            function (\Psr\Http\Message\RequestInterface $request) use (&$capturedBody) {
                $capturedBody = json_decode((string) $request->getBody(), true);

                return new GuzzleResponse(200, [], json_encode([
                    'status' => 200,
                    'headers' => [],
                    'html' => '',
                    'body' => null,
                ]));
            },
        ]);

        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $sender = new PlaywrightSender(guzzle: $guzzle);

        $sender->send(makePendingRequest('https://imdb.com', '/title/tt0111161/locations/'));

        expect($capturedBody['url'])->toBe('https://imdb.com/title/tt0111161/locations/');
    });

    it('returns a fulfilled promise from sendAsync', function () {
        $servicePayload = [
            'status' => 200,
            'headers' => [],
            'html' => '<html></html>',
            'body' => null,
        ];

        $sender = new PlaywrightSender(
            guzzle: makeGuzzleMock(200, $servicePayload),
        );

        $promise = $sender->sendAsync(makePendingRequest());
        $response = $promise->wait();

        expect($response->status())->toBe(200);
    });
});
