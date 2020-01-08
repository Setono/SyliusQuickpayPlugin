<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Klarna\Matcher;

interface CountryCurrencyMatcherInterface
{
    public function isMatch(string $countryCode, string $currencyCode): bool;
}
