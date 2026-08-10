<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Provider;

use Doctrine\Persistence\ManagerRegistry;
use Setono\Doctrine\ORMTrait;
use Sylius\Component\Core\Model\PaymentInterface;

final class PendingPaymentProvider implements PendingPaymentProviderInterface
{
    use ORMTrait;

    /**
     * @param class-string<PaymentInterface> $paymentClass
     */
    public function __construct(
        ManagerRegistry $managerRegistry,
        private readonly string $paymentClass,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function findPending(\DateTimeImmutable $createdSince, int $limit): array
    {
        /** @var list<PaymentInterface> $payments */
        $payments = $this->getManager($this->paymentClass)->createQueryBuilder()
            ->select('payment')
            ->from($this->paymentClass, 'payment')
            ->join('payment.method', 'method')
            ->join('method.gatewayConfig', 'gatewayConfig')
            ->andWhere('gatewayConfig.factoryName = :factoryName')
            ->andWhere('payment.state IN (:states)')
            ->andWhere('payment.createdAt >= :createdSince')
            ->orderBy('payment.id', 'ASC')
            ->setParameter('factoryName', 'quickpay')
            ->setParameter('states', [
                PaymentInterface::STATE_NEW,
                PaymentInterface::STATE_PROCESSING,
                PaymentInterface::STATE_AUTHORIZED,
            ])
            ->setParameter('createdSince', $createdSince)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // A payment without a quickpayPaymentId never got a payment link — there is nothing to poll
        return array_values(array_filter(
            $payments,
            static fn (PaymentInterface $payment): bool => isset($payment->getDetails()['quickpayPaymentId']),
        ));
    }
}
