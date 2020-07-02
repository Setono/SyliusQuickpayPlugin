<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command\Validator;

use Setono\SyliusQuickpayPlugin\Command\RefundUnits;
use Setono\SyliusQuickpayPlugin\Exception\PaymentNotFoundException;
use Setono\SyliusQuickpayPlugin\Exception\UnexpectedPaymentOrderException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\RefundPlugin\Command\RefundUnits as BaseRefundUnits;
use Sylius\RefundPlugin\Validator\RefundUnitsCommandValidatorInterface as BaseRefundUnitsCommandValidatorInterface;

final class RefundUnitsCommandValidator implements RefundUnitsCommandValidatorInterface
{
    /** @var BaseRefundUnitsCommandValidatorInterface */
    private $baseRefundUnitsCommandValidator;

    /** @var PaymentRepositoryInterface */
    private $paymentRepository;

    public function __construct(
        BaseRefundUnitsCommandValidatorInterface $baseRefundUnitsCommandValidator,
        PaymentRepositoryInterface $paymentRepository
    ) {
        $this->baseRefundUnitsCommandValidator = $baseRefundUnitsCommandValidator;
        $this->paymentRepository = $paymentRepository;
    }

    public function validate(RefundUnits $command): void
    {
        $this->baseRefundUnitsCommandValidator->validate($this->transformToBaseCommand($command));

        /** @var PaymentInterface|null $payment */
        $payment = $this->paymentRepository->find($command->paymentId());

        if (null === $payment) {
            throw PaymentNotFoundException::withId($command->paymentId());
        }

        if (null === $payment->getOrder() || $payment->getOrder()->getNumber() !== $command->orderNumber()) {
            throw UnexpectedPaymentOrderException::expectedOrder($command->orderNumber());
        }
    }

    private function transformToBaseCommand(RefundUnits $command): BaseRefundUnits
    {
        return new BaseRefundUnits(
            $command->orderNumber(),
            $command->units(),
            $command->shipments(),
            $command->paymentMethodId(),
            $command->comment()
        );
    }
}
