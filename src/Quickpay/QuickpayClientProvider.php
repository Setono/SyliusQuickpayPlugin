<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use CuyZ\Valinor\Cache\FileSystemCache;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Response\Payment\Payment;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final class QuickpayClientProvider implements QuickpayClientProviderInterface
{
    private readonly Psr18Client $httpClient;

    /** @var FileSystemCache<mixed> */
    private readonly FileSystemCache $cache;

    /**
     * @param HttpClientInterface $httpClient the scoped HTTP client every request to Quickpay goes through
     * @param string $cacheDir where the SDK keeps its mapper cache, the setup payum-quickpay recommends for production
     */
    public function __construct(
        HttpClientInterface $httpClient,
        string $cacheDir,
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
        private readonly QuickpayPaymentIdProviderInterface $quickpayPaymentIdProvider,
    ) {
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

    public function quickpayPaymentOf(PaymentInterface $payment): ?Payment
    {
        // Checked before the gateway configuration is read, so a payment without one never throws
        if (!$this->quickpayPaymentIdProvider->has($payment)) {
            return null;
        }

        $gatewayConfiguration = $this->gatewayConfigurationProvider->forPayment($payment);
        Assert::notNull($gatewayConfiguration);

        return $this->forGatewayConfiguration($gatewayConfiguration)->payments()->getById($this->quickpayPaymentIdProvider->idOf($payment));
    }
}
