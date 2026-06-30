<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Klarna\Matcher;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Klarna\Matcher\CountryCurrencyMatcher;

final class CountryCurrencyMatcherTest extends TestCase
{
    /**
     * @test
     */
    public function it_matches_country_and_currency_case_insensitively(): void
    {
        $matcher = new CountryCurrencyMatcher();

        self::assertTrue($matcher->isMatch('DK', 'DKK'));
        self::assertTrue($matcher->isMatch('dk', 'DKK'));
    }

    /**
     * @test
     */
    public function it_does_not_match_unsupported_pairs(): void
    {
        $matcher = new CountryCurrencyMatcher();

        self::assertFalse($matcher->isMatch('DK', 'EUR'));
        self::assertFalse($matcher->isMatch('XX', 'DKK'));
    }
}
