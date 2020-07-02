<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command\Validator;

use Setono\SyliusQuickpayPlugin\Command\RefundUnits;

interface RefundUnitsCommandValidatorInterface
{
    public function validate(RefundUnits $command): void;
}
