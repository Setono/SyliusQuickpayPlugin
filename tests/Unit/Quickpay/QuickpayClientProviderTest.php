<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay;

use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Quickpay\Exception\NotFoundException;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Exception\ServerErrorException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProvider;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QuickpayClientProviderTest extends TestCase
{
    use ProphecyTrait;

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

    /**
     * @test
     */
    public function it_reads_the_quickpay_payment_of_a_sylius_payment_with_its_gateway_configurations_api_key(): void
    {
        $provider = $this->provider(paymentsGatewayConfiguration: GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'api_key' => 'the-dk-api-key']));

        $quickpayPayment = $provider->quickpayPaymentOf(self::syliusPayment(['quickpayPaymentId' => 501]));

        self::assertNotNull($quickpayPayment);
        self::assertSame(501, $quickpayPayment->id);
        self::assertSame('qp_000000001', $quickpayPayment->orderId);

        self::assertCount(1, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('GET', $method);
        self::assertSame('https://api.quickpay.net/payments/501', $url);
        self::assertContains('Authorization: Basic ' . base64_encode(':the-dk-api-key'), self::headersOf($options));
    }

    /**
     * @test
     */
    public function it_answers_no_quickpay_payment_for_a_payment_without_a_quickpay_gateway_configuration(): void
    {
        self::assertNull($this->provider(paymentsGatewayConfiguration: null)->quickpayPaymentOf(self::syliusPayment(['quickpayPaymentId' => 501])));
        self::assertSame([], $this->requests);
    }

    /**
     * @test
     *
     * @dataProvider detailsWithoutAQuickpayPaymentId
     *
     * @param array<string, mixed> $details
     */
    public function it_answers_no_quickpay_payment_for_a_payment_whose_details_carry_no_quickpay_payment_id(array $details): void
    {
        self::assertNull($this->provider(paymentsGatewayConfiguration: GatewayConfigurations::of())->quickpayPaymentOf(self::syliusPayment($details)));
        self::assertSame([], $this->requests);
    }

    /**
     * A payment that never reached Quickpay has no Quickpay payment to read, so nothing reads its gateway configuration
     *
     * @test
     */
    public function it_answers_no_quickpay_payment_for_a_payment_without_a_quickpay_payment_id_even_when_its_gateway_configuration_is_unusable(): void
    {
        self::assertNull($this->provider(paymentsGatewayConfiguration: GatewayConfigurations::encrypted())->quickpayPaymentOf(self::syliusPayment([])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function detailsWithoutAQuickpayPaymentId(): iterable
    {
        yield 'no details' => [[]];

        // What a payment that never reached Quickpay may carry
        yield 'a null id' => [['quickpayPaymentId' => null, 'order_id' => 'qp_000000001']];
    }

    /**
     * @test
     *
     * @dataProvider unusableGatewayConfigurations
     */
    public function it_throws_reading_the_quickpay_payment_of_a_payment_whose_gateway_configuration_is_unusable(GatewayConfiguration $gatewayConfiguration): void
    {
        try {
            $this->provider(paymentsGatewayConfiguration: $gatewayConfiguration)->quickpayPaymentOf(self::syliusPayment(['quickpayPaymentId' => 501]));
            self::fail('No exception was thrown');
        } catch (UnusableGatewayConfiguration $e) {
            self::assertSame('quickpay_dk', $e->gatewayName);
        }

        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{GatewayConfiguration}>
     */
    public static function unusableGatewayConfigurations(): iterable
    {
        yield 'invalid' => [GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'branding_id' => 'Our brand'], 'quickpay_dk')];

        yield 'encrypted' => [GatewayConfigurations::encrypted('quickpay_dk')];
    }

    /**
     * @test
     *
     * @dataProvider quickpayErrors
     *
     * @param class-string<QuickpayException> $expectedException
     */
    public function it_throws_the_sdks_exception_when_quickpay_does_not_answer_with_the_payment(MockResponse $response, string $expectedException): void
    {
        $this->expectException($expectedException);

        $this->provider(paymentsGatewayConfiguration: GatewayConfigurations::of(), response: $response)->quickpayPaymentOf(self::syliusPayment(['quickpayPaymentId' => 501]));
    }

    /**
     * @return iterable<string, array{MockResponse, class-string<QuickpayException>}>
     */
    public static function quickpayErrors(): iterable
    {
        yield 'unknown payment' => [self::quickpayError(404), NotFoundException::class];

        yield 'api key refused' => [self::quickpayError(401), UnauthorizedException::class];

        yield 'Quickpay failing' => [self::quickpayError(500), ServerErrorException::class];
    }

    /**
     * An id payum-quickpay can't read is no proof the payment never reached Quickpay, so it is no reason to answer null
     *
     * @test
     */
    public function it_throws_for_details_carrying_an_unusable_quickpay_payment_id(): void
    {
        $this->expectException(LogicException::class);

        try {
            $this->provider(paymentsGatewayConfiguration: GatewayConfigurations::of())->quickpayPaymentOf(self::syliusPayment(['quickpayPaymentId' => 'foo']));
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    /**
     * A provider whose HTTP client answers every request with the response, a Quickpay payment unless another is
     * given, and whose gateway configuration module gives every Sylius payment the given gateway configuration
     * (null: the payment isn't a Quickpay one)
     */
    private function provider(?GatewayConfiguration $paymentsGatewayConfiguration = null, ?MockResponse $response = null): QuickpayClientProvider
    {
        $gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $gatewayConfigurationProvider->forPayment(Argument::type(PaymentInterface::class))->willReturn($paymentsGatewayConfiguration);

        return new QuickpayClientProvider(new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            /** @var array<string, mixed> $options */
            $this->requests[] = [$method, $url, $options];

            return $response ?? new MockResponse(self::quickpayPayment(), ['response_headers' => ['Content-Type' => 'application/json']]);
        }), $this->cacheDir, $gatewayConfigurationProvider->reveal());
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function syliusPayment(array $details): PaymentInterface
    {
        $payment = new Payment();
        $payment->setDetails($details);

        return $payment;
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
     * Quickpay's answer to a request it fails or refuses
     */
    private static function quickpayError(int $statusCode): MockResponse
    {
        return new MockResponse('{"message": "Not this time"}', ['http_code' => $statusCode, 'response_headers' => ['Content-Type' => 'application/json']]);
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
