<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * A Quickpay gateway configuration the gateway can be built from: valid by payum-quickpay's rules, with an api key
 * Quickpay doesn't reject. Declared on Sylius' gateway config entity in the `sylius_quickpay` validation group, which
 * Sylius picks for a Quickpay payment method in the admin form and the Admin API alike.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UsableGatewayConfiguration extends Constraint
{
    public string $apiKeyRejectedMessage = 'setono_sylius_quickpay.gateway_configuration.api_key.invalid';

    public string $unreadableMessage = 'setono_sylius_quickpay.gateway_configuration.unreadable';

    /**
     * The message for each gateway option payum-quickpay can reject, keyed by the option's name, saying what the option
     * accepts. An option missing here, one a later payum-quickpay adds, gets $invalidOptionMessage until it has its own.
     *
     * @var array<string, string>
     */
    public array $optionMessages = [
        'api_key' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.api_key',
        'private_key' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.private_key',
        'order_prefix' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.order_prefix',
        'payment_methods' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.payment_methods',
        'language' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.language',
        'auto_capture' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.auto_capture',
        'synchronized' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.synchronized',
        'agreement_id' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.agreement_id',
        'branding_id' => 'setono_sylius_quickpay.gateway_configuration.gateway_option.branding_id',
    ];

    /**
     * Shown for a rejected gateway option without a message of its own; `{{ option }}` is the option's name
     */
    public string $invalidOptionMessage = 'setono_sylius_quickpay.gateway_configuration.invalid_gateway_option';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
