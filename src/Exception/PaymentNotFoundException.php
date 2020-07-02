<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Exception;

final class PaymentNotFoundException extends \InvalidArgumentException
{
    public static function withId(int $id): self
    {
        return new self(sprintf('Payment with ID "%s" was not found.', $id));
    }
}
