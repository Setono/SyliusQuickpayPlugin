<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\OrderId;

use Setono\SyliusQuickpayPlugin\OrderId\LatestOrderNumberProviderInterface;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;

final class LatestOrderNumberProviderTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_gives_the_number_of_the_newest_order(): void
    {
        self::bootKernel();
        $channel = $this->channel();
        $this->createOrder($channel, 'awaiting_payment', '000000122');
        $this->createOrder($channel, 'paid', '000000123');
        $this->entityManager()->flush();

        self::assertSame('000000123', $this->provider()->getLatestOrderNumber());
    }

    /**
     * A cart has no number until checkout completes, and carts are created all the time
     *
     * @test
     */
    public function it_passes_over_carts(): void
    {
        self::bootKernel();
        $channel = $this->channel();
        $this->createOrder($channel, 'awaiting_payment', '000000123');

        $cart = $this->createResource('sylius.factory.order');
        self::assertInstanceOf(OrderInterface::class, $cart);
        $cart->setChannel($channel);
        $cart->setCurrencyCode(self::CURRENCY);
        $cart->setLocaleCode(self::LOCALE);
        $cart->setState(BaseOrderInterface::STATE_CART);
        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();

        self::assertSame('000000123', $this->provider()->getLatestOrderNumber());
    }

    /**
     * @test
     */
    public function it_gives_no_number_while_the_store_has_no_order(): void
    {
        self::bootKernel();

        self::assertNull($this->provider()->getLatestOrderNumber());
    }

    private function provider(): LatestOrderNumberProviderInterface
    {
        $provider = self::getContainer()->get(LatestOrderNumberProviderInterface::class);
        self::assertInstanceOf(LatestOrderNumberProviderInterface::class, $provider);

        return $provider;
    }
}
