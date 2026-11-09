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

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
