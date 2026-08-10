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
        // An unresponsive Quickpay must not hang the admin, so a short timeout is applied
        // when the Symfony HTTP client is available; otherwise the client falls back to
        // whatever PSR-18 implementation discovery finds in the host application
        if (class_exists(Psr18Client::class)) {
            return new Client($apiKey, new Psr18Client(HttpClient::create([
                'timeout' => 5,
                'max_duration' => 5,
            ])));
        }

        return new Client($apiKey);
    }
}
