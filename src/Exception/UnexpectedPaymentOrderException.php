<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Exception;

final class UnexpectedPaymentOrderException extends \InvalidArgumentException
{
    public static function expectedOrder(string $orderNumber): self
    {
        return new self(sprintf('The payment does not belong to the order number "%s".', $orderNumber));
    }
}
