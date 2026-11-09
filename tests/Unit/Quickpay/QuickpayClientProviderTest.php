<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProvider;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QuickpayClientProviderTest extends TestCase
{
    /**
     * The requests the HTTP client was sent, each as the method, the url and its options
     *
     * @var list<array{string, string, array<string, mixed>}>
     */
    private array $requests = [];

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/setono_sylius_quickpay_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    /**
     * @test
     */
    public function it_builds_a_client_sending_the_gateway_configurations_api_key_through_the_http_client(): void
    {
        $client = $this->provider()->forGatewayConfiguration(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'api_key' => 'the-dk-api-key']));

        $client->payments()->getById(501);

        self::assertCount(1, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('GET', $method);
        self::assertSame('https://api.quickpay.net/payments/501', $url);
        self::assertContains('Authorization: Basic ' . base64_encode(':the-dk-api-key'), self::headersOf($options));
    }

    /**
     * @test
     */
    public function it_builds_a_client_as_synchronized_as_the_gateway_configuration(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->forGatewayConfiguration(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'synchronized' => true]))->isSynchronized());
        self::assertFalse($provider->forGatewayConfiguration(GatewayConfigurations::of())->isSynchronized());
    }

    /**
     * A client is built on every call, so a changed api key is used from the next call on
     *
     * @test
     */
    public function it_builds_each_client_from_the_gateway_configuration_it_is_given(): void
    {
        $provider = $this->provider();

        $provider->forGatewayConfiguration(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'api_key' => 'the-old-api-key']))->payments()->getById(501);
        $provider->forGatewayConfiguration(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'api_key' => 'the-new-api-key']))->payments()->getById(501);

        self::assertCount(2, $this->requests);
        self::assertContains('Authorization: Basic ' . base64_encode(':the-old-api-key'), self::headersOf($this->requests[0][2]));
        self::assertContains('Authorization: Basic ' . base64_encode(':the-new-api-key'), self::headersOf($this->requests[1][2]));
    }

    /**
     * @test
     */
    public function it_throws_for_an_unusable_gateway_configuration(): void
    {
        try {
            $this->provider()->forGatewayConfiguration(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'branding_id' => 'Our brand'], 'quickpay_dk'));
            self::fail('No exception was thrown');
        } catch (UnusableGatewayConfiguration $e) {
            self::assertSame('quickpay_dk', $e->gatewayName);
            self::assertArrayHasKey('branding_id', $e->getViolations());
        }

        self::assertSame([], $this->requests);
    }

    /**
     * @test
     */
    public function it_throws_for_an_unreadable_gateway_configuration(): void
    {
        $this->expectException(UnusableGatewayConfiguration::class);

        $this->provider()->forGatewayConfiguration(GatewayConfigurations::encrypted());
    }

    /**
     * @test
     */
    public function it_gives_each_client_the_sdks_mapper_cache_in_the_cache_directory(): void
    {
        $this->provider()->forGatewayConfiguration(GatewayConfigurations::of())->payments()->getById(501);

        self::assertNotSame([], glob($this->cacheDir . '/*.php'), 'The SDK mapped the payment without its cache');
    }

    private function provider(): QuickpayClientProvider
    {
        return new QuickpayClientProvider(new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, mixed> $options */
            $this->requests[] = [$method, $url, $options];

            return new MockResponse(self::quickpayPayment(), ['response_headers' => ['Content-Type' => 'application/json']]);
        }), $this->cacheDir);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private static function headersOf(array $options): array
    {
        $headers = $options['headers'] ?? [];
        self::assertIsArray($headers);

        return array_values(array_filter($headers, is_string(...)));
    }

    /**
     * A Quickpay payment as `GET /payments/{id}` returns it
     */
    private static function quickpayPayment(): string
    {
        return json_encode([
            'id' => 501,
            'order_id' => 'qp_000000001',
            'currency' => 'DKK',
            'state' => 'new',
            'merchant_id' => 1,
            'accepted' => true,
            'test_mode' => true,
            'operations' => [],
        ], \JSON_THROW_ON_ERROR);
    }
}
