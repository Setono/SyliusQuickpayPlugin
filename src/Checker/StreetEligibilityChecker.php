<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Checker;

use Sylius\Component\Addressing\Model\AddressInterface;
use VIISON\AddressSplitter\AddressSplitter;
use VIISON\AddressSplitter\Exceptions\SplittingException;
use Webmozart\Assert\Assert;

class StreetEligibilityChecker implements StreetEligibilityCheckerInterface
{
    public function isEligible(AddressInterface $address): bool
    {
        $countryCode = $address->getCountryCode();
        Assert::notNull($countryCode);

        $street = $address->getStreet();
        if (null === $street) {
            return false;
        }

        try {
            $splittedStreet = AddressSplitter::splitAddress($street);
        } catch (SplittingException $e) {
            return false;
        }

        switch (mb_strtoupper($countryCode)) {
            case 'DE':
                if ('' === $splittedStreet['houseNumber']) {
                    return false;
                }

                break;
            case 'NL':
                if ('' === $splittedStreet['houseNumberParts']['base']) {
                    return false;
                }

                if ('' === $splittedStreet['houseNumberParts']['extension']) {
                    return false;
                }
        }

        return true;
    }
}
