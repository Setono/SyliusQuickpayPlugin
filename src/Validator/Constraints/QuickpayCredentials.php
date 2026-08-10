<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class QuickpayCredentials extends Constraint
{
    public string $message = 'setono_sylius_quickpay.gateway_configuration.api_key.invalid';
}
