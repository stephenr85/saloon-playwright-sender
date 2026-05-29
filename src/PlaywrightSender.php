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
        $psrRequest = $pendingRequest->createPsrRequest();

        $serviceResponse = $this->guzzle->post($this->config->serviceUrl.'/navigate', [
            'json' => [
                'url' => (string) $pendingRequest->getUrl(),
                'method' => $pendingRequest->getMethod()->value,
                'headers' => $this->flattenHeaders($pendingRequest->headers()->all()),
                'mode' => $this->config->responseMode,
            ],
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
