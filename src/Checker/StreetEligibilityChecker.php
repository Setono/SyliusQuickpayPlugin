<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Checker;

use Sylius\Component\Addressing\Model\AddressInterface;
use VIISON\AddressSplitter\AddressSplitter;
use VIISON\AddressSplitter\Exceptions\SplittingException;

class StreetEligibilityChecker implements StreetEligibilityCheckerInterface
{
    public function isEligible(AddressInterface $address): bool
    {
        $countryCode = $address->getCountryCode();
        if (null === $countryCode) {
            return false;
        }

        $street = $address->getStreet();
        if (null === $street) {
            return false;
        }

        try {
            switch (mb_strtoupper($countryCode)) {
                case 'DE':
                    $splittedStreet = AddressSplitter::splitAddress($street);
                    if ('' === $splittedStreet['houseNumber']) {
                        return false;
                    }

                    break;
                case 'NL':
                    $splittedStreet = AddressSplitter::splitAddress($street);
                    if ('' === $splittedStreet['houseNumberParts']['base']) {
                        return false;
                    }

                    if ('' === $splittedStreet['houseNumberParts']['extension']) {
                        return false;
                    }
            }
        } catch (SplittingException $e) {
            return false;
        }

        return true;
    }
}
