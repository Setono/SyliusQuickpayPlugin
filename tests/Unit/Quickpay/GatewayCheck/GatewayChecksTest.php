<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay\GatewayCheck;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
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
        self::assertSame([], $this->requests);
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
        $this->respond('GET', '/agreements/7', self::json(200, ['id' => 7, 'merchant_id' => 1]));

        $this->checks()->agreement($gatewayConfiguration);

        self::assertSame(['GET /agreements/7'], $this->requests);
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
        $this->respond('GET', '/agreements/12345', self::json(200, ['id' => 12345, 'merchant_id' => 1]));

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Passed, $verdict->outcome);
        self::assertNull($verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->requests);
    }

    /**
     * @test
     */
    public function it_fails_an_agreement_quickpay_does_not_find(): void
    {
        $this->respond('GET', '/agreements/12345', self::json(404, ['message' => 'Not found']));

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Failed, $verdict->outcome);
        self::assertSame('Agreement 12345 does not exist on this Quickpay account', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->requests);
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
        $this->respond('GET', '/agreements/12345', self::json($status, ['message' => 'Not authorized']));

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame('the api user may lack the /agreements permission', $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->requests);
    }

    /**
     * @test
     *
     * @dataProvider errors
     */
    public function it_leaves_an_agreement_undetermined_when_quickpay_answers_with_another_error(MockResponse $error, string $quickpaysError): void
    {
        $this->respond('GET', '/agreements/12345', $error);

        $verdict = $this->checks()->agreement(self::withAgreement(12345));

        self::assertSame(VerdictOutcome::Undetermined, $verdict->outcome);
        self::assertSame(str_replace('/ping', '/agreements/12345', $quickpaysError), $verdict->reason);
        self::assertNull($verdict->note);
        self::assertSame(['GET /agreements/12345'], $this->requests);
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
            self::assertSame([], $this->requests);
        }
    }

    private static function withAgreement(int|string $agreementId): GatewayConfiguration
    {
        return GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'agreement_id' => $agreementId]);
    }

    private function checks(): GatewayChecks
    {
        return new GatewayChecks(new QuickpayClientProvider(new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $key = sprintf('%s %s', $method, (string) parse_url($url, \PHP_URL_PATH));
            $this->requests[] = $key;
            $headers = $options['normalized_headers'] ?? [];
            self::assertIsArray($headers);
            $this->requestHeaders[] = $headers;

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
}
