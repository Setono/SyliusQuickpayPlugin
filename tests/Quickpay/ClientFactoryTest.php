<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactory;

final class ClientFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_a_new_client_per_key(): void
    {
        $factory = new ClientFactory();

        self::assertNotSame($factory->create('first-key'), $factory->create('second-key'));
    }
}
