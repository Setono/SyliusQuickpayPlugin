<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final class ClientFactory implements ClientFactoryInterface
{
    public function create(string $apiKey): ClientInterface
    {
        // An unresponsive Quickpay must not hang the admin
        return new Client($apiKey, new Psr18Client(HttpClient::create([
            'timeout' => 5,
            'max_duration' => 5,
        ])));
    }
}
