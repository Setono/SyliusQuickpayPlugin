<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Setono\Quickpay\Client\ClientInterface;

interface ClientFactoryInterface
{
    public function create(string $apiKey): ClientInterface;
}
