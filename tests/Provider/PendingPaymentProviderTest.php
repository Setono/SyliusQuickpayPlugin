<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Provider\PendingPaymentProvider;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;

final class PendingPaymentProviderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_filters_out_payments_without_a_quickpay_payment_id(): void
    {
        $pollable = $this->createPayment(['quickpayPaymentId' => 999999]);
        $linkless = $this->createPayment([]);

        $provider = $this->createProvider([$linkless, $pollable]);

        self::assertSame([$pollable], $provider->findPending(new \DateTimeImmutable('-7 days'), 100));
    }

    /**
     * @test
     */
    public function it_returns_an_empty_list_when_no_payments_match(): void
    {
        $provider = $this->createProvider([]);

        self::assertSame([], $provider->findPending(new \DateTimeImmutable('-7 days'), 100));
    }

    /**
     * @param list<PaymentInterface> $queryResult
     */
    private function createProvider(array $queryResult): PendingPaymentProvider
    {
        $query = $this->prophesize(Query::class);
        $query->setParameters(Argument::any())->willReturn($query);
        $query->setFirstResult(Argument::any())->willReturn($query);
        $query->setMaxResults(Argument::any())->willReturn($query);
        $query->getResult()->willReturn($queryResult);

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->createQuery(Argument::type('string'))->willReturn($query);
        $entityManager->createQueryBuilder()->will(fn (): QueryBuilder => new QueryBuilder($entityManager->reveal()));

        $managerRegistry = $this->prophesize(ManagerRegistry::class);
        $managerRegistry->getManagerForClass(Payment::class)->willReturn($entityManager);

        return new PendingPaymentProvider($managerRegistry->reveal(), Payment::class);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createPayment(array $details): PaymentInterface
    {
        $payment = new Payment();
        $payment->setDetails($details);

        return $payment;
    }
}
