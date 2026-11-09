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
     * Stands in for a gateway option payum-quickpay requires while the options it accepts are read
     */
    private const PLACEHOLDER = 'placeholder';

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
        $options = self::optionsOrViolationsOf($this->readableConfig());

        if ($options instanceof InvalidGatewayOptions) {
            throw UnusableGatewayConfiguration::invalid($this->gatewayName, $options);
        }

        return $options;
    }

    /**
     * The gateway options even when payum-quickpay rejects some of them, for showing or checking a configuration
     * that may still need fixing: each option it accepts as it reads it, and each one it rejects as stored, under
     * the option's current name. Never throws for a readable configuration.
     *
     * The options it accepts are read again without the rejected ones, so its defaults apply to those. Only an
     * option it requires has no default it accepts: a placeholder stands in for each one that read reports, and is
     * never among the values. Should payum-quickpay reject even that, every option is as stored.
     *
     * @throws UnusableGatewayConfiguration only when the configuration is unreadable
     */
    public function optionsWithRejectedAsStored(): GatewayOptionValues
    {
        $stored = GatewayOptions::withCurrentNames($this->readableConfig());

        $rejected = [];
        $options = self::optionsOrViolationsOf($stored);

        if ($options instanceof InvalidGatewayOptions) {
            $rejected = $options->getViolations();
            $accepted = array_diff_key($stored, $rejected);

            $options = self::optionsOrViolationsOf($accepted);
            if ($options instanceof InvalidGatewayOptions) {
                $options = self::optionsOrViolationsOf(
                    $accepted + array_fill_keys(array_keys($options->getViolations()), self::PLACEHOLDER),
                );
            }
        }

        $captureMode = CaptureMode::fromUseAuthorize($stored['use_authorize'] ?? null);
        // A rejected auto_capture was left out of the read, so it is off there
        if (CaptureMode::OnCompletion === $captureMode && $options instanceof GatewayOptions && $options->autoCapture) {
            $captureMode = CaptureMode::Immediately;
        }

        // Each option is a GatewayOptions property, named in camelCase
        $read = $options instanceof GatewayOptions ? get_object_vars($options) : [];

        $values = [];
        $asStored = [];
        foreach (array_keys(GatewayOptions::DEFAULTS) as $option) {
            $property = lcfirst(str_replace('_', '', ucwords($option, '_')));

            if (!isset($rejected[$option]) && array_key_exists($property, $read)) {
                $values[$option] = $read[$property];
            } else {
                $values[$option] = $stored[$option] ?? null;
                $asStored[] = $option;
            }
        }

        return new GatewayOptionValues($values, $asStored, $captureMode);
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

    /**
     * The stored configuration as saving the payment method in the admin again stores it, before any option is
     * changed: the 1.x option names replaced by the current ones, and the capture mode stored as `use_authorize`, with
     * the deprecated `auto_capture` folded in ({@see GatewayOptionValues::$captureMode}). Every other option is as
     * stored, also one the gateway rejects. Never throws for a readable configuration.
     *
     * @return array<array-key, mixed>
     *
     * @throws UnusableGatewayConfiguration only when the configuration is unreadable
     */
    public function withoutDeprecatedOptions(): array
    {
        $captureMode = $this->optionsWithRejectedAsStored()->captureMode;

        $config = GatewayOptions::withCurrentNames($this->readableConfig());
        unset($config['auto_capture']);
        $config['use_authorize'] = $captureMode->useAuthorize();

        return $config;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnusableGatewayConfiguration when the stored configuration reads as none
     */
    private function readableConfig(): array
    {
        if (null === $this->config) {
            throw UnusableGatewayConfiguration::unreadable($this->gatewayName);
        }

        return $this->config;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function optionsOrViolationsOf(array $config): GatewayOptions|InvalidGatewayOptions
    {
        try {
            return GatewayOptions::fromConfig($config);
        } catch (InvalidGatewayOptions $e) {
            return $e;
        }
    }
}
