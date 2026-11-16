<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\OrderId;

use Doctrine\Persistence\ManagerRegistry;
use Setono\Doctrine\ORMTrait;
use Sylius\Component\Core\Model\OrderInterface;

final class LatestOrderNumberProvider implements LatestOrderNumberProviderInterface
{
    use ORMTrait;

    /**
     * @param class-string<OrderInterface> $orderClass
     */
    public function __construct(ManagerRegistry $managerRegistry, private readonly string $orderClass)
    {
        $this->managerRegistry = $managerRegistry;
    }

    public function getLatestOrderNumber(): ?string
    {
        /** @var list<array{number: string}> $rows */
        $rows = $this->getManager($this->orderClass)->createQueryBuilder()
            ->select('o.number')
            ->from($this->orderClass, 'o')
            ->andWhere('o.number IS NOT NULL')
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult()
        ;

        return [] === $rows ? null : $rows[0]['number'];
    }
}
