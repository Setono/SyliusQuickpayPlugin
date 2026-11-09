<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_clients_sending_their_api_key_through_the_http_client(): void
    {
        $authorizations = [];
        $factory = new ClientFactory(new MockHttpClient(static function (string $method, string $url, array $options) use (&$authorizations): MockResponse {
            /** @var array{normalized_headers: array<string, list<string>>} $options */
            $authorizations[] = $options['normalized_headers']['authorization'] ?? [];

            return new MockResponse('{}');
        }));

        $factory->create('the-dk-api-key')->ping();
        $factory->create('the-se-api-key')->ping();

        self::assertSame([
            ['Authorization: Basic ' . base64_encode(':the-dk-api-key')],
            ['Authorization: Basic ' . base64_encode(':the-se-api-key')],
        ], $authorizations);
    }
}
