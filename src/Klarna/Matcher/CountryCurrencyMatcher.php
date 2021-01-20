<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Klarna\Matcher;

class CountryCurrencyMatcher implements CountryCurrencyMatcherInterface
{
    protected ?array $map = null;

    public function __construct(array $map = null)
    {
        if (null === $map) {
            // @see https://learn.quickpay.net/tech-talk/appendixes/acquirer-details/#acquirer-details
            $map = [
                'DK' => 'DKK',
                'NO' => 'NOK',
                'SE' => 'SEK',
                'FI' => 'EUR',
                'AT' => 'EUR',
                'DE' => 'EUR',
                'NL' => 'EUR',
            ];
        }

        $this->map = $map;
    }

    public function isMatch(string $countryCode, string $currencyCode): bool
    {
        $countryCode = mb_strtoupper($countryCode);
        $currencyCode = mb_strtoupper($currencyCode);

        if (!isset($this->map[$countryCode])) {
            return false;
        }

        return $this->map[$countryCode] === $currencyCode;
    }
}
