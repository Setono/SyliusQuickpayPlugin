<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

use Payum\Core\Model\GatewayConfigInterface;
use Setono\Payum\Quickpay\Exception\InvalidGatewayOptions;
use Setono\Payum\Quickpay\GatewayOptions;

/**
 * A Quickpay gateway's gateway configuration, as a payment method stores it (see GLOSSARY.md). What each
 * gateway option means is payum-quickpay's to decide ({@see GatewayOptions}, the rules the gateway is built
 * from; ADR 0002), so reading the options here never disagrees with the gateway.
 */
final class GatewayConfiguration
{
    /**
     * @param array<array-key, mixed>|null $config null when the stored configuration reads as none
     */
    private function __construct(
        public readonly string $gatewayName,
        private readonly ?array $config,
    ) {
    }

    public static function fromGatewayConfig(GatewayConfigInterface $gatewayConfig): self
    {
        // Payum's model reads an encrypted configuration as its decrypted copy, which is null on an entity
        // Doctrine loaded and nothing decrypted: only Payum's storage and Sylius' admin form decrypt
        $config = $gatewayConfig->getConfig();

        return new self((string) $gatewayConfig->getGatewayName(), is_array($config) ? $config : null);
    }

    /**
     * For a stored configuration without its gateway config, as the admin form's `config` field holds it. It
     * has no gateway name.
     *
     * @param array<array-key, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self('', $config);
    }

    /**
     * The gateway options, typed and normalized by the rules the gateway is built from
     *
     * @throws UnusableGatewayConfiguration
     */
    public function options(): GatewayOptions
    {
        if (null === $this->config) {
            throw UnusableGatewayConfiguration::unreadable($this->gatewayName);
        }

        try {
            return GatewayOptions::fromConfig($this->config);
        } catch (InvalidGatewayOptions $e) {
            throw UnusableGatewayConfiguration::invalid($this->gatewayName, $e);
        }
    }

    /**
     * The capture mode checkout uses. It is Sylius' `use_authorize` gateway option, which payum-quickpay
     * never reads ({@see CaptureMode::fromUseAuthorize()}). An authorization is then captured on completion,
     * unless the deprecated `auto_capture`, read by payum-quickpay's rule, makes Quickpay capture it right away.
     *
     * @throws UnusableGatewayConfiguration only when `auto_capture` is stored and the configuration is unusable
     */
    public function captureMode(): CaptureMode
    {
        $captureMode = CaptureMode::fromUseAuthorize($this->config['use_authorize'] ?? null);

        if (CaptureMode::OnCompletion === $captureMode &&
            null !== ($this->config['auto_capture'] ?? null) &&
            $this->options()->autoCapture
        ) {
            return CaptureMode::Immediately;
        }

        return $captureMode;
    }

    /**
     * Whether the capture mode (Sylius' `use_authorize` gateway option) is stored. A configuration written by
     * fixtures or the Admin API may not have it, and checkout then captures immediately.
     */
    public function isCaptureModeSet(): bool
    {
        return isset($this->config['use_authorize']);
    }

    /**
     * Whether a deprecated gateway option is stored, by payum-quickpay's rule: a 1.x option name, or an
     * `auto_capture` that is on. Re-saving the payment method in the admin replaces them.
     */
    public function usesDeprecatedOptions(): bool
    {
        return GatewayOptions::usesDeprecatedOptions($this->config ?? []);
    }
}
