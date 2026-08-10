<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Provider;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
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
        // Doctrine\ORM\Query is final in parts of the supported ORM version range, so the
        // builder chain is doubled instead of executed against a real QueryBuilder
        $query = $this->prophesize(AbstractQuery::class);
        $query->getResult()->willReturn($queryResult);

        $queryBuilder = $this->prophesize(QueryBuilder::class);
        $queryBuilder->select(Argument::cetera())->willReturn($queryBuilder);
        $queryBuilder->from(Argument::cetera())->willReturn($queryBuilder);
        $queryBuilder->join(Argument::cetera())->willReturn($queryBuilder);
        $queryBuilder->andWhere(Argument::cetera())->willReturn($queryBuilder);
        $queryBuilder->orderBy(Argument::cetera())->willReturn($queryBuilder);
        $queryBuilder->setParameter(Argument::cetera())->willReturn($queryBuilder);
        $queryBuilder->setMaxResults(Argument::any())->willReturn($queryBuilder);
        $queryBuilder->getQuery()->willReturn($query);

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->createQueryBuilder()->willReturn($queryBuilder);

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
