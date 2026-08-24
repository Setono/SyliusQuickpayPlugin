<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client answering requests with queued responses in FIFO order, for tests that run the
 * real SDK client through a multi-request flow
 */
final class QueuedResponsesHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = array_shift($this->responses);

        if (null === $response) {
            throw new \LogicException(sprintf('No response queued for "%s %s"', $request->getMethod(), $request->getUri()->getPath()));
        }

        return $response;
    }
}
