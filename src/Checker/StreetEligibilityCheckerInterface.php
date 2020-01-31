<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Checker;

use Sylius\Component\Addressing\Model\AddressInterface;

interface StreetEligibilityCheckerInterface
{
    public function isEligible(AddressInterface $address): bool;
}
