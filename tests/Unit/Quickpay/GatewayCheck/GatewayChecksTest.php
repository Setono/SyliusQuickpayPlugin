<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay\GatewayCheck;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecks;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\VerdictOutcome;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProvider;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The gateway checks against Quickpay's HTTP, through the real client provider and SDK clients
 */
final class GatewayChecksTest extends TestCase
{
    use ProphecyTrait;

    /** @var array<string, MockResponse> Quickpay's answer per "METHOD /path" */
    private array $responses = [];

    /** @var list<string> the requests sent, as "METHOD /path" */
    private array $requests = [];

    /** @var list<array<array-key, mixed>> each request's headers, in the order of the requests, as "Name: value" lines per lowercase name */
    private array $requestHeaders = [];

    /** @var list<mixed> each request's body, in the order of the requests */
    private array $requestBodies = [];

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
    public function it_passes_an_api_key_quickpay_accepts_on_ping(): void
    {
        $this->respond('GET', '/ping', self::json(200, ['msg' => 'Pong from QuickPay API V10']));

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping'], $this->requests);
    }

    /**
     * Quickpay answers 401 on /ping both for an invalid key and for a valid key whose api user lacks the /ping
     * permission (verified live), so a one-item /payments read, a permission every integration needs, decides
     *
     * @test
     *
     * @dataProvider refusals
     */
    public function it_passes_an_api_key_quickpay_refuses_on_ping_but_accepts_on_payments(int $pingStatus): void
    {
        $this->respond('GET', '/ping', self::json($pingStatus, ['message' => 'Invalid API key']));
        $this->respond('GET', '/payments', self::json(200, []));

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertSame('verified through /payments: the api user lacks the /ping permission, which is harmless', $verdict->note);
        self::assertSame(['GET /ping', 'GET /payments'], $this->requests);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusals(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    /**
     * @test
     *
     * @dataProvider refusalsOnBoth
     */
    public function it_fails_an_api_key_quickpay_refuses_on_ping_and_on_payments(int $pingStatus, int $paymentsStatus): void
    {
        $this->respond('GET', '/ping', self::json($pingStatus, ['message' => 'Invalid API key']));
        $this->respond('GET', '/payments', self::json($paymentsStatus, ['message' => 'Invalid API key']));

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('Quickpay rejects the api key — use the API user\'s key (Settings → Users in the Quickpay manager), not a Payment Window agreement\'s', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping', 'GET /payments'], $this->requests);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function refusalsOnBoth(): iterable
    {
        yield 'unauthorized on both' => [401, 401];
        yield 'unauthorized on ping, forbidden on payments' => [401, 403];
        yield 'forbidden on ping, unauthorized on payments' => [403, 401];
    }

    /**
     * Only a refusal says something about the api key: any other error proves nothing either way
     *
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_an_api_key_undetermined_when_quickpay_answers_ping_with_another_error(MockResponse $error, string $quickpaysError): void
    {
        $this->respond('GET', '/ping', $error);

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame($quickpaysError, $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping'], $this->requests);
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_an_api_key_undetermined_when_quickpay_answers_the_payments_read_with_another_error(MockResponse $error, string $quickpaysError): void
    {
        $this->respond('GET', '/ping', self::json(401, ['message' => 'Invalid API key']));
        $this->respond('GET', '/payments', $error);

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('/ping', '/payments', $quickpaysError), $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping', 'GET /payments'], $this->requests);
    }

    /**
     * Quickpay's errors other than a refusal, each with the message the SDK reports it with (for the /ping request)
     *
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function errors(): iterable
    {
        yield 'a server error' => [
            self::json(500, ['message' => 'Internal server error']),
            'The status code was: 500. [GET https://api.quickpay.net/ping] The body was: {"message":"Internal server error"}.',
        ];
        yield 'too many requests' => [
            self::json(429, ['message' => 'Too many requests']),
            'The status code was: 429. [GET https://api.quickpay.net/ping] The body was: {"message":"Too many requests"}.',
        ];
        yield 'no answer' => [
            new MockResponse('', ['error' => 'Idle timeout reached']),
            'The request could not be sent [GET https://api.quickpay.net/ping]: Idle timeout reached',
        ];
    }

    /**
     * @test
     */
    public function it_sends_the_gateway_configurations_api_key(): void
    {
        $this->respond('GET', '/ping', self::json(200, ['msg' => 'Pong from QuickPay API V10']));

        $this->checks()->apiKey(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'api_key' => 'the-dk-api-key']));

        self::assertSame(['Authorization: Basic ' . base64_encode(':the-dk-api-key')], $this->requestHeaders[0]['authorization'] ?? null);
    }

    /**
     * @test
     */
    public function it_asks_quickpay_nothing_for_an_unusable_gateway_configuration(): void
    {
        try {
            $this->checks()->apiKey(GatewayConfigurations::of(['api_key' => 'the-api-key'], 'quickpay_dk'));
            self::fail('No exception was thrown');
        } catch (UnusableGatewayConfiguration $e) {
            self::assertSame('quickpay_dk', $e->gatewayName);
        }

        self::assertSame([], $this->requests);
    }

    /**
     * @test
     */
    public function it_passes_the_payment_link_permission_when_quickpay_puts_a_link_on_a_test_payment(): void
    {
        $this->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->respond('PUT', '/payments/999/link', self::json(200, ['url' => 'https://payment.quickpay.net/payments/the-link']));
        $this->respond('DELETE', '/payments/999/link', new MockResponse('', ['http_code' => 204]));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'order_prefix' => 'qp_']));

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->requests);
        self::assertSame(sprintf('test payment 999 with order id "%s" remains on the account — it never carries money', $this->sentTestOrderId()), $verdict->note);
    }

    /**
     * The gateway's order prefix, "dr" and six random hex digits: unique per run, and told apart from orders by the
     * prefix the account's orders carry
     *
     * @test
     *
     * @dataProvider orderPrefixes
     */
    public function it_creates_the_test_payment_with_an_order_id_of_the_gateways_order_prefix(?string $orderPrefix, string $orderIdPattern): void
    {
        $this->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->respond('PUT', '/payments/999/link', self::json(200, ['url' => 'https://payment.quickpay.net/payments/the-link']));
        $this->respond('DELETE', '/payments/999/link', new MockResponse('', ['http_code' => 204]));

        $this->checks()->paymentLinkPermission(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'order_prefix' => $orderPrefix]));

        self::assertMatchesRegularExpression($orderIdPattern, $this->sentTestOrderId());
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function orderPrefixes(): iterable
    {
        yield 'an order prefix' => ['qp_', '/^qp_dr[0-9a-f]{6}$/'];
        yield 'no order prefix' => [null, '/^dr[0-9a-f]{6}$/'];
    }

    /**
     * @test
     *
     * @dataProvider refusals
     */
    public function it_fails_the_payment_link_permission_when_quickpay_refuses_to_create_the_test_payment(int $status): void
    {
        $this->respond('POST', '/payments', self::json($status, ['message' => 'Not authorized']));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('The api user may not create payments — check the POST permission for /payments (Settings → Users → User permissions)', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['POST /payments'], $this->requests);
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_the_payment_link_permission_undetermined_when_quickpay_answers_the_test_payment_with_another_error(MockResponse $error, string $quickpaysError): void
    {
        $this->respond('POST', '/payments', $error);

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('[GET https://api.quickpay.net/ping]', '[POST https://api.quickpay.net/payments]', $quickpaysError), $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['POST /payments'], $this->requests);
    }

    /**
     * @test
     *
     * @dataProvider refusals
     */
    public function it_fails_the_payment_link_permission_when_quickpay_refuses_to_put_a_link_on_the_test_payment(int $status): void
    {
        $this->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->respond('PUT', '/payments/999/link', self::json($status, ['message' => 'Not authorized to PUT /payments/:id/link']));
        $this->respond('DELETE', '/payments/999/link', new MockResponse('', ['http_code' => 204]));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('The api user may not create payment links — check the PUT checkbox for "Create or update payment link" (Settings → Users → User permissions)', $verdict->reason);
        self::assertSame(sprintf('test payment 999 with order id "%s" remains on the account — it never carries money', $this->sentTestOrderId()), $verdict->note);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->requests);
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_the_payment_link_permission_undetermined_when_quickpay_answers_the_link_with_another_error(MockResponse $error, string $quickpaysError): void
    {
        $this->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->respond('PUT', '/payments/999/link', $error);
        $this->respond('DELETE', '/payments/999/link', new MockResponse('', ['http_code' => 204]));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('[GET https://api.quickpay.net/ping]', '[PUT https://api.quickpay.net/payments/999/link]', $quickpaysError), $verdict->reason);
        self::assertSame(sprintf('test payment 999 with order id "%s" remains on the account — it never carries money', $this->sentTestOrderId()), $verdict->note);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->requests);
    }

    /**
     * Deleting the link only tidies up: the test payment stays either way
     *
     * @test
     *
     * @dataProvider errors
     */
    public function it_passes_the_payment_link_permission_when_quickpay_fails_to_delete_the_link_again(MockResponse $error): void
    {
        $this->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->respond('PUT', '/payments/999/link', self::json(200, ['url' => 'https://payment.quickpay.net/payments/the-link']));
        $this->respond('DELETE', '/payments/999/link', $error);

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertSame(sprintf('test payment 999 with order id "%s" remains on the account — it never carries money', $this->sentTestOrderId()), $verdict->note);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->requests);
    }

    /**
     * @test
     */
    public function it_creates_no_test_payment_for_an_unusable_gateway_configuration(): void
    {
        try {
            $this->checks()->paymentLinkPermission(GatewayConfigurations::of(['api_key' => 'the-api-key'], 'quickpay_dk'));
            self::fail('No exception was thrown');
        } catch (UnusableGatewayConfiguration $e) {
            self::assertSame('quickpay_dk', $e->gatewayName);
        }

        self::assertSame([], $this->requests);
    }

    private function checks(): GatewayChecks
    {
        return new GatewayChecks(new QuickpayClientProvider(new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $key = sprintf('%s %s', $method, (string) parse_url($url, \PHP_URL_PATH));
            $this->requests[] = $key;
            $headers = $options['normalized_headers'] ?? [];
            self::assertIsArray($headers);
            $this->requestHeaders[] = $headers;
            $this->requestBodies[] = $options['body'] ?? null;

            return $this->responses[$key] ?? throw new \LogicException(sprintf('No response scripted for "%s"', $key));
        }), $this->cacheDir, $this->prophesize(GatewayConfigurationProviderInterface::class)->reveal()));
    }

    private function respond(string $method, string $path, MockResponse $response): void
    {
        $this->responses[sprintf('%s %s', $method, $path)] = $response;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function json(int $status, array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * The order id of the test payment the check asked Quickpay to create
     */
    private function sentTestOrderId(): string
    {
        $body = $this->requestBodies[0] ?? null;
        self::assertIsString($body);
        $payment = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payment);
        self::assertIsString($payment['order_id'] ?? null);

        return $payment['order_id'];
    }

    /**
     * Quickpay's answer to creating the test payment
     *
     * @return array<string, mixed>
     */
    private static function testPayment(): array
    {
        return [
            'id' => 999,
            'order_id' => 'qp_dr1a2b3c',
            'currency' => 'DKK',
            'state' => 'initial',
            'merchant_id' => 1,
        ];
    }
}
