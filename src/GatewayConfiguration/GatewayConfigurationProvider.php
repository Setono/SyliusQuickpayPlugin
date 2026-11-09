<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

/**
 * Backed by Sylius' gateway config repository. A gateway configuration is a Quickpay one when its gateway is
 * built by payum-quickpay's gateway factory.
 */
final class GatewayConfigurationProvider implements GatewayConfigurationProviderInterface
{
    /**
     * @param RepositoryInterface<GatewayConfigInterface> $gatewayConfigRepository
     */
    public function __construct(private readonly RepositoryInterface $gatewayConfigRepository)
    {
    }

    public function all(): array
    {
        $configurations = [];

        foreach ($this->gatewayConfigRepository->findBy(['factoryName' => QuickpayGatewayFactory::NAME]) as $gatewayConfig) {
            $configurations[] = GatewayConfiguration::fromGatewayConfig($gatewayConfig);
        }

        return $configurations;
    }

    /**
     * Looked up the way Payum finds the gateway it builds for a gateway name: among the stored gateway configs
     */
    public function forGatewayName(string $gatewayName): ?GatewayConfiguration
    {
        return self::quickpayConfigurationOf($this->gatewayConfigRepository->findOneBy(['gatewayName' => $gatewayName]));
    }

    public function forPaymentMethod(PaymentMethodInterface $paymentMethod): ?GatewayConfiguration
    {
        return self::quickpayConfigurationOf($paymentMethod->getGatewayConfig());
    }

    public function forPayment(PaymentInterface $payment): ?GatewayConfiguration
    {
        $paymentMethod = $payment->getMethod();

        return $paymentMethod instanceof PaymentMethodInterface ? $this->forPaymentMethod($paymentMethod) : null;
    }

    private static function quickpayConfigurationOf(mixed $gatewayConfig): ?GatewayConfiguration
    {
        if (!$gatewayConfig instanceof GatewayConfigInterface || QuickpayGatewayFactory::NAME !== $gatewayConfig->getFactoryName()) {
            return null;
        }

        return GatewayConfiguration::fromGatewayConfig($gatewayConfig);
    }
}
