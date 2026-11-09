<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ClientFactory implements ClientFactoryInterface
{
    private readonly Psr18Client $httpClient;

    /**
     * @param HttpClientInterface $httpClient the scoped HTTP client every request to Quickpay goes through, so the
     *        clients created here follow the same HTTP policy as the gateways' (ADR 0003)
     */
    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = new Psr18Client($httpClient);
    }

    public function create(string $apiKey): ClientInterface
    {
        return new Client($apiKey, $this->httpClient);
    }
}
