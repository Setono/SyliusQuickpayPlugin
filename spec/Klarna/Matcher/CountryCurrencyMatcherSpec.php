<?php

declare(strict_types=1);

namespace spec\Setono\SyliusQuickpayPlugin\Klarna\Matcher;

use PhpSpec\ObjectBehavior;
use Setono\SyliusQuickpayPlugin\Klarna\Matcher\CountryCurrencyMatcher;
use Setono\SyliusQuickpayPlugin\Klarna\Matcher\CountryCurrencyMatcherInterface;

class CountryCurrencyMatcherSpec extends ObjectBehavior
{
    public function it_is_initializable(): void
    {
        $this->shouldHaveType(CountryCurrencyMatcher::class);
    }

    public function it_implements_interface(): void
    {
        $this->shouldImplement(CountryCurrencyMatcherInterface::class);
    }

    public function it_checks_match(): void
    {
        $this->isMatch('DK', 'DKK')->shouldReturn(true);
        $this->isMatch('dk', 'DKK')->shouldReturn(true);

        $this->isMatch('DK', 'EUR')->shouldReturn(false);
    }
}
