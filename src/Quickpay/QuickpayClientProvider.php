<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use CuyZ\Valinor\Cache\FileSystemCache;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class QuickpayClientProvider implements QuickpayClientProviderInterface
{
    private readonly Psr18Client $httpClient;

    /** @var FileSystemCache<mixed> */
    private readonly FileSystemCache $cache;

    /**
     * @param HttpClientInterface $httpClient the scoped HTTP client every request to Quickpay goes through
     * @param string $cacheDir where the SDK keeps its mapper cache, the setup payum-quickpay recommends for production
     */
    public function __construct(HttpClientInterface $httpClient, string $cacheDir)
    {
        $this->httpClient = new Psr18Client($httpClient);
        $this->cache = new FileSystemCache($cacheDir);
    }

    public function forGatewayConfiguration(GatewayConfiguration $gatewayConfiguration): ClientInterface
    {
        $options = $gatewayConfiguration->options();

        // The SDK client is a cheap wrapper around the shared HTTP client, so a new one per call needs no
        // invalidation when an api key changes
        return new Client($options->apiKey, $this->httpClient, synchronized: $options->synchronized, cache: $this->cache);
    }
}
