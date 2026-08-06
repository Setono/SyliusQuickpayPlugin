<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Exception\UnsupportedPaymentTransitionException;

final class UnsupportedPaymentTransitionExceptionTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_be_thrown_with_a_message(): void
    {
        $this->expectException(UnsupportedPaymentTransitionException::class);
        $this->expectExceptionMessage('unsupported transition');

        throw new UnsupportedPaymentTransitionException('unsupported transition');
    }
}
