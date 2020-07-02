<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command\Factory;

use Setono\SyliusQuickpayPlugin\Command\RefundUnits;
use Symfony\Component\HttpFoundation\Request;

interface RefundUnitsCommandFactoryInterface
{
    public function fromRequest(Request $request): RefundUnits;
}
