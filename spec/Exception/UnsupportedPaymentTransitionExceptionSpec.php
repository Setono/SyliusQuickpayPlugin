<?php

declare(strict_types=1);

namespace spec\Setono\SyliusPickupPointPlugin\Exception;

use PhpSpec\ObjectBehavior;
use Setono\SyliusQuickpayPlugin\Exception\UnsupportedPaymentTransitionException;

final class UnsupportedPaymentTransitionExceptionSpec extends ObjectBehavior
{
    public function it_is_initializable(): void
    {
        $this->shouldHaveType(UnsupportedPaymentTransitionException::class);
    }

    public function it_is_an_exception_exception(): void
    {
        $this->shouldHaveType(\Exception::class);
    }
}
