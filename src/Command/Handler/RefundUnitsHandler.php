<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command\Handler;

use Setono\SyliusQuickpayPlugin\Command\RefundUnits;
use Setono\SyliusQuickpayPlugin\Command\Validator\RefundUnitsCommandValidatorInterface;
use Setono\SyliusQuickpayPlugin\Event\UnitsRefunded;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Refunder\RefunderInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class RefundUnitsHandler
{
    /** @var RefunderInterface */
    private $orderUnitsRefunder;

    /** @var RefunderInterface */
    private $orderShipmentsRefunder;

    /** @var MessageBusInterface */
    private $eventBus;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var RefundUnitsCommandValidatorInterface */
    private $refundUnitsCommandValidator;

    public function __construct(
        RefunderInterface $orderUnitsRefunder,
        RefunderInterface $orderShipmentsRefunder,
        MessageBusInterface $eventBus,
        OrderRepositoryInterface $orderRepository,
        RefundUnitsCommandValidatorInterface $refundUnitsCommandValidator
    ) {
        $this->orderUnitsRefunder = $orderUnitsRefunder;
        $this->orderShipmentsRefunder = $orderShipmentsRefunder;
        $this->eventBus = $eventBus;
        $this->orderRepository = $orderRepository;
        $this->refundUnitsCommandValidator = $refundUnitsCommandValidator;
    }

    public function __invoke(RefundUnits $command): void
    {
        $this->refundUnitsCommandValidator->validate($command);

        $orderNumber = $command->orderNumber();

        /** @var OrderInterface $order */
        $order = $this->orderRepository->findOneByNumber($orderNumber);

        $refundedTotal = 0;
        $refundedTotal += $this->orderUnitsRefunder->refundFromOrder($command->units(), $orderNumber);
        $refundedTotal += $this->orderShipmentsRefunder->refundFromOrder($command->shipments(), $orderNumber);

        $this->eventBus->dispatch(new UnitsRefunded(
            $orderNumber,
            $command->units(),
            $command->shipments(),
            $command->paymentMethodId(),
            $refundedTotal,
            $order->getCurrencyCode(),
            $command->comment(),
            $command->paymentId()
        ));
    }
}
