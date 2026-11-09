<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay\GatewayCheck;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\ResponseInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecks;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\VerdictOutcome;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProvider;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\ScriptedHttpClient;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\TemporaryCacheDir;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The gateway checks against Quickpay's HTTP, through the real client provider and SDK clients
 */
final class GatewayChecksTest extends TestCase
{
    use ProphecyTrait;
    use TemporaryCacheDir;

    private ScriptedHttpClient $quickpay;

    protected function setUp(): void
    {
        $this->quickpay = new ScriptedHttpClient();
    }

    /**
     * @test
     */
    public function it_passes_an_api_key_quickpay_accepts_on_ping(): void
    {
        $this->quickpay->respond('GET', '/ping', self::json(200, ['msg' => 'Pong from QuickPay API V10']));

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping'], $this->quickpay->sentRequests());
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
        $this->quickpay->respond('GET', '/ping', self::json($pingStatus, ['message' => 'Invalid API key']));
        $this->quickpay->respond('GET', '/payments', self::json(200, []));

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertSame('verified through /payments: the api user lacks the /ping permission, which is harmless', $verdict->note);
        self::assertSame(['GET /ping', 'GET /payments'], $this->quickpay->sentRequests());
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
        $this->quickpay->respond('GET', '/ping', self::json($pingStatus, ['message' => 'Invalid API key']));
        $this->quickpay->respond('GET', '/payments', self::json($paymentsStatus, ['message' => 'Invalid API key']));

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('Quickpay rejects the api key — use the API user\'s key (Settings → Users in the Quickpay manager), not a Payment Window agreement\'s', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping', 'GET /payments'], $this->quickpay->sentRequests());
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
    public function it_leaves_an_api_key_undetermined_when_quickpay_answers_ping_with_another_error(ResponseInterface|MockResponse $error, string $quickpaysError): void
    {
        $this->quickpay->respond('GET', '/ping', $error);

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame($quickpaysError, $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping'], $this->quickpay->sentRequests());
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_an_api_key_undetermined_when_quickpay_answers_the_payments_read_with_another_error(ResponseInterface|MockResponse $error, string $quickpaysError): void
    {
        $this->quickpay->respond('GET', '/ping', self::json(401, ['message' => 'Invalid API key']));
        $this->quickpay->respond('GET', '/payments', $error);

        $verdict = $this->checks()->apiKey(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('/ping', '/payments', $quickpaysError), $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /ping', 'GET /payments'], $this->quickpay->sentRequests());
    }

    /**
     * Quickpay's errors other than a refusal, each with the message the SDK reports it with (for the /ping request)
     *
     * @return iterable<string, array{ResponseInterface|MockResponse, string}>
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
        yield 'an answer that is not JSON' => [
            new Response(200, ['Content-Type' => 'text/html'], '<html>Maintenance</html>'),
            'Could not decode response body as JSON [GET https://api.quickpay.net/ping]: Syntax error.',
        ];
        // The connection drops after Quickpay's headers arrived: the SDK reads the body outside its transport error
        // handling, so the PSR-7 stream's own error reaches the check
        yield 'an answer that breaks off' => [
            new MockResponse((static function (): \Generator {
                yield '{"msg": "Pong';

                throw new \RuntimeException('Connection reset by peer');
            })(), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]),
            'Unable to read stream contents: Connection reset by peer',
        ];
    }

    /**
     * @test
     */
    public function it_sends_the_gateway_configurations_api_key(): void
    {
        $this->quickpay->respond('GET', '/ping', self::json(200, ['msg' => 'Pong from QuickPay API V10']));

        $this->checks()->apiKey(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'api_key' => 'the-dk-api-key']));

        $headers = $this->quickpay->sentRequestOptions()[0]['normalized_headers'] ?? null;
        self::assertIsArray($headers);
        self::assertSame(['Authorization: Basic ' . base64_encode(':the-dk-api-key')], $headers['authorization'] ?? null);
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

        self::assertSame([], $this->quickpay->sentRequests());
    }

    /**
     * @test
     */
    public function it_passes_the_payment_link_permission_when_quickpay_puts_a_link_on_a_test_payment(): void
    {
        $this->quickpay->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->quickpay->respond('PUT', '/payments/999/link', self::json(200, ['url' => 'https://payment.quickpay.net/payments/the-link']));
        $this->quickpay->respond('DELETE', '/payments/999/link', new Response(204));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'order_prefix' => 'qp_']));

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->quickpay->sentRequests());
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
        $this->quickpay->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->quickpay->respond('PUT', '/payments/999/link', self::json(200, ['url' => 'https://payment.quickpay.net/payments/the-link']));
        $this->quickpay->respond('DELETE', '/payments/999/link', new Response(204));

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
        $this->quickpay->respond('POST', '/payments', self::json($status, ['message' => 'Not authorized']));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('The api user may not create payments — check the POST permission for /payments (Settings → Users → User permissions)', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['POST /payments'], $this->quickpay->sentRequests());
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_the_payment_link_permission_undetermined_when_quickpay_answers_the_test_payment_with_another_error(ResponseInterface|MockResponse $error, string $quickpaysError): void
    {
        $this->quickpay->respond('POST', '/payments', $error);

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('[GET https://api.quickpay.net/ping]', '[POST https://api.quickpay.net/payments]', $quickpaysError), $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['POST /payments'], $this->quickpay->sentRequests());
    }

    /**
     * @test
     *
     * @dataProvider refusals
     */
    public function it_fails_the_payment_link_permission_when_quickpay_refuses_to_put_a_link_on_the_test_payment(int $status): void
    {
        $this->quickpay->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->quickpay->respond('PUT', '/payments/999/link', self::json($status, ['message' => 'Not authorized to PUT /payments/:id/link']));
        $this->quickpay->respond('DELETE', '/payments/999/link', new Response(204));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('The api user may not create payment links — check the PUT checkbox for "Create or update payment link" (Settings → Users → User permissions)', $verdict->reason);
        self::assertSame(sprintf('test payment 999 with order id "%s" remains on the account — it never carries money', $this->sentTestOrderId()), $verdict->note);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->quickpay->sentRequests());
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_the_payment_link_permission_undetermined_when_quickpay_answers_the_link_with_another_error(ResponseInterface|MockResponse $error, string $quickpaysError): void
    {
        $this->quickpay->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->quickpay->respond('PUT', '/payments/999/link', $error);
        $this->quickpay->respond('DELETE', '/payments/999/link', new Response(204));

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('[GET https://api.quickpay.net/ping]', '[PUT https://api.quickpay.net/payments/999/link]', $quickpaysError), $verdict->reason);
        self::assertSame(sprintf('test payment 999 with order id "%s" remains on the account — it never carries money', $this->sentTestOrderId()), $verdict->note);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->quickpay->sentRequests());
    }

    /**
     * Deleting the link only tidies up: the test payment stays either way
     *
     * @test
     *
     * @dataProvider errors
     */
    public function it_passes_the_payment_link_permission_when_quickpay_fails_to_delete_the_link_again(ResponseInterface|MockResponse $error): void
    {
        $this->quickpay->respond('POST', '/payments', self::json(201, self::testPayment()));
        $this->quickpay->respond('PUT', '/payments/999/link', self::json(200, ['url' => 'https://payment.quickpay.net/payments/the-link']));
        $this->quickpay->respond('DELETE', '/payments/999/link', $error);

        $verdict = $this->checks()->paymentLinkPermission(GatewayConfigurations::of());

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertSame(sprintf('test payment 999 with order id "%s" remains on the account — it never carries money', $this->sentTestOrderId()), $verdict->note);
        self::assertSame(['POST /payments', 'PUT /payments/999/link', 'DELETE /payments/999/link'], $this->quickpay->sentRequests());
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

        self::assertSame([], $this->quickpay->sentRequests());
    }

    /**
     * The gateway then sends no agreement id, and Quickpay uses the account's default agreement
     *
     * @test
     *
     * @dataProvider withoutAgreementId
     */
    public function it_passes_the_agreement_without_asking_quickpay_when_no_agreement_id_is_set(GatewayConfiguration $gatewayConfiguration): void
    {
        $verdict = $this->checks()->agreement($gatewayConfiguration);

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertSame('Quickpay uses the account\'s default Payment Window agreement', $verdict->note);
        self::assertSame([], $this->quickpay->sentRequests());
    }

    /**
     * @return iterable<string, array{GatewayConfiguration}>
     */
    public static function withoutAgreementId(): iterable
    {
        yield 'none stored' => [GatewayConfigurations::of()];
        yield 'a blank one' => [self::withAgreement('   ')];
    }

    /**
     * The one the gateway sends, as payum-quickpay reads it
     *
     * @test
     *
     * @dataProvider storedAgreementIds
     */
    public function it_asks_quickpay_for_the_agreement_the_gateway_sends(GatewayConfiguration $gatewayConfiguration): void
    {
        $this->quickpay->respond('GET', '/agreements/7', self::json(200, ['id' => 7, 'merchant_id' => 1]));

        $this->checks()->agreement($gatewayConfiguration);

        self::assertSame(['GET /agreements/7'], $this->quickpay->sentRequests());
    }

    /**
     * @return iterable<string, array{GatewayConfiguration}>
     */
    public static function storedAgreementIds(): iterable
    {
        yield 'an integer string' => [self::withAgreement('7')];
        yield 'under its 1.x name' => [GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'agreement' => '7'])];
    }

    /**
     * @test
     */
    public function it_passes_an_agreement_quickpay_reads(): void
    {
        $this->quickpay->respond('GET', '/agreements/12345', self::json(200, ['id' => 12345, 'merchant_id' => 1]));

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->quickpay->sentRequests());
    }

    /**
     * @test
     */
    public function it_fails_an_agreement_quickpay_does_not_find(): void
    {
        $this->quickpay->respond('GET', '/agreements/12345', self::json(404, ['message' => 'Not found']));

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('Agreement 12345 does not exist on this Quickpay account', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->quickpay->sentRequests());
    }

    /**
     * A refusal proves nothing about the agreement: the api user may lack the /agreements permission
     *
     * @test
     *
     * @dataProvider refusals
     */
    public function it_leaves_an_agreement_undetermined_when_quickpay_refuses_to_read_it(int $status): void
    {
        $this->quickpay->respond('GET', '/agreements/12345', self::json($status, ['message' => 'Not authorized']));

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame('the api user may lack the /agreements permission', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->quickpay->sentRequests());
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_an_agreement_undetermined_when_quickpay_answers_with_another_error(ResponseInterface|MockResponse $error, string $quickpaysError): void
    {
        $this->quickpay->respond('GET', '/agreements/12345', $error);

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('/ping', '/agreements/12345', $quickpaysError), $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->quickpay->sentRequests());
    }

    /**
     * @test
     */
    public function it_asks_quickpay_nothing_about_the_agreement_of_an_unusable_gateway_configuration(): void
    {
        $this->expectException(UnusableGatewayConfiguration::class);

        try {
            $this->checks()->agreement(GatewayConfigurations::of(['api_key' => 'the-api-key', 'agreement_id' => 12345]));
        } finally {
            self::assertSame([], $this->quickpay->sentRequests());
        }
    }

    private static function withAgreement(int|string $agreementId): GatewayConfiguration
    {
        return GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'agreement_id' => $agreementId]);
    }

    private function checks(): GatewayChecks
    {
        return new GatewayChecks(new QuickpayClientProvider(
            new MockHttpClient($this->quickpay),
            $this->cacheDir,
            $this->prophesize(GatewayConfigurationProviderInterface::class)->reveal(),
        ));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * The order id of the test payment the check asked Quickpay to create
     */
    private function sentTestOrderId(): string
    {
        $body = $this->quickpay->sentRequestOptions()[0]['body'] ?? null;
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
