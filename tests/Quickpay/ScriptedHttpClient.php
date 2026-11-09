<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client standing in for Quickpay's API: a test scripts the response per method and path,
 * and asserts on the requests sent. In the test application every Quickpay gateway and the plugin's
 * client factory send their requests here (see {@see ScriptedGatewayFactoryBuilder} and
 * {@see ScriptedClientFactory}).
 */
final class ScriptedHttpClient implements ClientInterface
{
    /** @var array<string, ResponseInterface> */
    private array $responses = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * Answers every later `$method $path` request with the response, until scripted again
     */
    public function respond(string $method, string $path, ResponseInterface $response): void
    {
        $this->responses[self::key($method, $path)] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $key = self::key($request->getMethod(), $request->getUri()->getPath());
        if (!isset($this->responses[$key])) {
            throw new \LogicException(sprintf('No response scripted for "%s"', $key));
        }

        $response = $this->responses[$key];

        // The same response answers repeated requests, so each one reads the body from the start
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $response;
    }

    /**
     * @return list<string> the requests sent so far, as "METHOD /path"
     */
    public function sentRequests(): array
    {
        return array_map(
            static fn (RequestInterface $request): string => self::key($request->getMethod(), $request->getUri()->getPath()),
            $this->requests,
        );
    }

    private static function key(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }
}
