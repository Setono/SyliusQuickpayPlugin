<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

final class AddressStreetEligibility extends Constraint
{
    /** @var string */
    public $message = 'setono_sylius_quickpay.address.street_eligibility';

    public function validatedBy(): string
    {
        return 'setono_sylius_quickpay_address_street_eligibility_validator';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
