# saloon-playwright-sender

A [Saloon](https://docs.saloon.dev) `Sender` that dispatches requests through a Playwright browser microservice. Use it when a target site requires JavaScript rendering, bot-detection bypass, or any browser interaction before the page is ready to scrape.

## How it works

1. Your Saloon connector sends a request through `PlaywrightSender` instead of the default Guzzle sender.
2. `PlaywrightSender` forwards the request to a local Node.js service.
3. The Node.js service uses a Playwright browser to navigate to the URL, optionally runs an interaction script, then returns the rendered HTML (or raw response body) to PHP.
4. Saloon receives a normal `Response` — DTOs, plugins, retries, and `MockClient` all work as expected.

## Requirements

- PHP 8.2+
- [Saloon v3](https://docs.saloon.dev)
- Node.js 18+

## Installation

```bash
composer require rushing/saloon-playwright-sender
```

If you are using Laravel, publish the config:

```bash
php artisan vendor:publish --tag=playwright-sender-config
```

## Starting the Playwright service

```bash
cd vendor/rushing/saloon-playwright-sender/playwright-service
npm install
npx playwright install chromium
node index.js
```

The service binds to `127.0.0.1` (loopback only) by default and is not reachable from outside the host.

### Environment variables (Node service)

| Variable | Default       | Description                        |
|----------|---------------|------------------------------------|
| `PORT`   | `3000`        | Port the service listens on        |
| `HOST`   | `127.0.0.1`   | Interface to bind — keep as loopback unless behind a trusted internal network |

## Configuration

### PHP / Laravel

Set these in `.env` (or pass a `PlaywrightServiceConfig` directly — see below):

| Variable                    | Default                  | Description                              |
|-----------------------------|--------------------------|------------------------------------------|
| `PLAYWRIGHT_SERVICE_URL`    | `http://localhost:3000`  | URL of the running Node service          |
| `PLAYWRIGHT_TIMEOUT`        | `30`                     | Request timeout in seconds               |
| `PLAYWRIGHT_RESPONSE_MODE`  | `html`                   | `html` returns rendered DOM; `body` returns the raw HTTP response body |

## Basic usage

Set `PlaywrightSender` as the default sender on any Saloon connector:

```php
use Rushing\SaloonPlaywright\PlaywrightSender;
use Saloon\Contracts\Sender;
use Saloon\Http\Connector;

class MyConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://example.com';
    }

    protected function defaultSender(): Sender
    {
        return new PlaywrightSender();
    }
}
```

To override the service URL or timeout at runtime:

```php
use Rushing\SaloonPlaywright\PlaywrightServiceConfig;

protected function defaultSender(): Sender
{
    return new PlaywrightSender(new PlaywrightServiceConfig(
        serviceUrl: 'http://playwright-service:3000',
        timeout: 60,
        responseMode: 'html',
    ));
}
```

Parse the rendered HTML in your request's `createDtoFromResponse()` using Saloon's built-in `$response->dom()`, which returns a [`Symfony\Component\DomCrawler\Crawler`](https://symfony.com/doc/current/components/dom_crawler.html):

```php
public function createDtoFromResponse(Response $response): array
{
    $items = [];

    $response->dom()->filter('.product-card')->each(function (Crawler $node) use (&$items) {
        $items[] = [
            'name'  => trim($node->filter('h2')->text()),
            'price' => trim($node->filter('.price')->text()),
        ];
    });

    return $items;
}
```

## Browser interactions

Some pages require an action before their full content is available — expanding a collapsed section, clicking a "load more" button, or waiting for a lazy-loaded element.

Define a `playwright_script` in your request's `defaultConfig()`. The script runs after the initial page load (after `networkidle`) and has access to the full [Playwright `page` API](https://playwright.dev/docs/api/class-page):

```php
public function defaultConfig(): array
{
    return [
        'playwright_script' => "
            const btn = await page.$('.load-more');
            if (btn) {
                await btn.click();
                await page.waitForLoadState('networkidle');
            }
        ",
    ];
}
```

The script runs as an `async` function body — `await` works at the top level. The `page` variable is the Playwright `Page` object.

### Common patterns

**Wait for an element to appear before capturing:**
```js
await page.waitForSelector('.results-container');
```

**Scroll to the bottom to trigger lazy loading:**
```js
await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
await page.waitForLoadState('networkidle');
```

**Dismiss a cookie banner:**
```js
const dismiss = await page.$('[data-testid="accept-cookies"]');
if (dismiss) await dismiss.click();
```

**Multiple sequential interactions:**
```js
await page.click('#expand-section');
await page.waitForSelector('.expanded-content');
await page.click('.load-all-results');
await page.waitForLoadState('networkidle');
```

### Security note

The `playwright_script` string is executed server-side via `new Function()`. The Node service binds to `127.0.0.1` by default precisely because of this — the script should always originate from your PHP application code, never from external user input.

## Testing

`PlaywrightSender` works with Saloon's `MockClient` — no Node service needed for tests:

```php
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

$connector = new MyConnector();
$connector->withMockClient(new MockClient([
    MyRequest::class => MockResponse::make(
        body: file_get_contents(__DIR__ . '/fixtures/page.html'),
        headers: ['Content-Type' => 'text/html'],
    ),
]));

$result = $connector->send(new MyRequest())->dto();
```
