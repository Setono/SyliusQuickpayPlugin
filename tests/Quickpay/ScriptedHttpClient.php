<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for Quickpay's API: a test scripts the response per method and path, and asserts on the requests sent.
 * It is what Symfony's mock HTTP client calls with each request. In the test application it is the framework's
 * `http_client.mock_response_factory`, so it answers below the plugin's scoped HTTP client
 * `setono_sylius_quickpay.http_client`: every request to Quickpay, the gateways' and the plugin's own, reaches it
 * through the real client provider, SDK clients and HTTP policy, and arrives with the options the scoped client gave
 * it. A unit test puts it in a `MockHttpClient` itself.
 */
final class ScriptedHttpClient
{
    /** @var array<string, ResponseInterface|MockResponse> */
    private array $responses = [];

    /** @var list<array{key: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * Answers every later `$method $path` request with the response, until scripted again. A `MockResponse` scripts
     * what a PSR-7 response can't: no answer at all (its `error` option), or an answer that breaks off (a body
     * generator that throws, which answers one request only)
     */
    public function respond(string $method, string $path, ResponseInterface|MockResponse $response): void
    {
        $this->responses[self::key($method, $path)] = $response;
    }

    /**
     * Symfony's mock HTTP client calls this with each request, its options resolved as the HTTP client sends them
     *
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $key = self::key($method, (string) parse_url($url, \PHP_URL_PATH));
        $this->requests[] = ['key' => $key, 'options' => $options];

        if (!isset($this->responses[$key])) {
            throw new \LogicException(sprintf('No response scripted for "%s"', $key));
        }

        $response = $this->responses[$key];
        if ($response instanceof MockResponse) {
            return $response;
        }

        // The same response answers repeated requests, so each one reads the body from the start
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return new MockResponse($body->getContents(), [
            'http_code' => $response->getStatusCode(),
            'response_headers' => $response->getHeaders(),
        ]);
    }

    /**
     * @return list<string> the requests sent so far, as "METHOD /path"
     */
    public function sentRequests(): array
    {
        return array_column($this->requests, 'key');
    }

    /**
     * The options each request was sent with, in the order of {@see self::sentRequests()}, as the HTTP client
     * resolved them: e.g. `timeout` and `max_duration` (floats), `normalized_headers` (lowercase header name =>
     * list of "Name: value" lines) and `body`
     *
     * @return list<array<string, mixed>>
     */
    public function sentRequestOptions(): array
    {
        return array_column($this->requests, 'options');
    }

    private static function key(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }
}
