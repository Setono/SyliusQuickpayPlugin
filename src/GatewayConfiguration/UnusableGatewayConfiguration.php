<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

use Setono\Payum\Quickpay\Exception\InvalidGatewayOptions;

/**
 * A stored gateway configuration the Quickpay gateway can't be built from: invalid by payum-quickpay's
 * rules, or unreadable. Thrown when a reader asks for what the configuration means, never when it is
 * listed or looked up, so each reader applies its own error policy.
 */
final class UnusableGatewayConfiguration extends \RuntimeException
{
    /**
     * @param array<string, string> $violations
     */
    private function __construct(
        public readonly string $gatewayName,
        private readonly array $violations,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalid(string $gatewayName, InvalidGatewayOptions $exception): self
    {
        return new self($gatewayName, $exception->getViolations(), sprintf(
            'The gateway configuration of %s is invalid: %s',
            self::describeGateway($gatewayName),
            implode('; ', $exception->getViolations()),
        ), $exception);
    }

    /**
     * A stored configuration that reads as none, as an encrypted one does until Payum decrypts it
     */
    public static function unreadable(string $gatewayName): self
    {
        return new self($gatewayName, [], sprintf(
            'The gateway configuration of %s reads as empty, as an encrypted one does before Payum decrypts it. Encrypting gateway configurations is not supported.',
            self::describeGateway($gatewayName),
        ));
    }

    /**
     * A configuration read without its gateway config has no gateway name, as a Payum gateway reads its own: Payum
     * passes none with it
     */
    private static function describeGateway(string $gatewayName): string
    {
        return '' === $gatewayName ? 'a Quickpay gateway' : sprintf('the Quickpay gateway "%s"', $gatewayName);
    }

    /**
     * Whether the configuration could not be read at all, as an encrypted one can't. Nothing can be said about
     * its gateway options then, and the message says why.
     */
    public function isUnreadable(): bool
    {
        // payum-quickpay reports at least one violation for a configuration it rejects
        return [] === $this->violations;
    }

    /**
     * The violations payum-quickpay found, a message per gateway option keyed by its current name. Empty when
     * the configuration is unreadable.
     *
     * @return array<string, string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
