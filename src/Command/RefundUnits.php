<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Sylius\RefundPlugin\Model\OrderItemUnitRefund;
use Sylius\RefundPlugin\Model\ShipmentRefund;
use Webmozart\Assert\Assert;

class RefundUnits
{
    /** @var string */
    private $orderNumber;

    /** @var array|OrderItemUnitRefund[] */
    private $units;

    /** @var array|ShipmentRefund[] */
    private $shipments;

    /** @var int */
    private $paymentMethodId;

    /** @var string */
    private $comment;

    /** @var int|null */
    private $paymentId;

    public function __construct(
        string $orderNumber,
        array $units,
        array $shipments,
        int $paymentMethodId,
        string $comment,
        ?int $paymentId = null
    ) {
        Assert::allIsInstanceOf($units, OrderItemUnitRefund::class);
        Assert::allIsInstanceOf($shipments, ShipmentRefund::class);

        $this->orderNumber = $orderNumber;
        $this->units = $units;
        $this->shipments = $shipments;
        $this->paymentMethodId = $paymentMethodId;
        $this->comment = $comment;
        $this->paymentId = $paymentId;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    /** @return array|OrderItemUnitRefund[] */
    public function units(): array
    {
        return $this->units;
    }

    /** @return array|ShipmentRefund[] */
    public function shipments(): array
    {
        return $this->shipments;
    }

    public function paymentMethodId(): int
    {
        return $this->paymentMethodId;
    }

    public function comment(): string
    {
        return $this->comment;
    }

    public function paymentId(): ?int
    {
        return $this->paymentId;
    }
}
