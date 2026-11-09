<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Validator;

use Nyholm\Psr7\Response;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\UsableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\UsableGatewayConfigurationValidator;
use Sylius\Bundle\PayumBundle\Validator\GroupsGenerator\GatewayConfigGroupsGenerator;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Saving a payment method validates it with the groups Sylius picks for its gateway, in the admin form and the Admin
 * API alike: a Quickpay gateway configuration the gateway would reject is refused
 */
final class GatewayConfigurationValidationTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_refuses_a_quickpay_gateway_configuration_the_gateway_would_reject_with_a_violation_per_option(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', [
            'api_key' => 'the-api-key',
            'agreement_id' => 0,
            'branding_id' => 'Our brand',
        ]);

        self::assertEqualsCanonicalizing([
            'gatewayConfig.config[private_key]',
            'gatewayConfig.config[agreement_id]',
            'gatewayConfig.config[branding_id]',
        ], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame([], $this->quickpay()->sentRequests(), 'The api key of a configuration that is refused anyway is not verified');
    }

    /**
     * payum-quickpay's reasons are English: the admin reads, in their own language and on the field, what the field
     * accepts
     *
     * @test
     */
    public function it_says_what_each_rejected_option_accepts_in_the_admins_language(): void
    {
        self::bootKernel();
        $this->translatorIn('da');

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', [
            'api_key' => 'the-api-key',
            'agreement_id' => 0,
            'branding_id' => 'Our brand',
        ]);

        self::assertEquals([
            'gatewayConfig.config[private_key]' => 'Indtast din kontos private nøgle (Indstillinger → Integration i Quickpay manageren).',
            'gatewayConfig.config[agreement_id]' => 'Indtast et positivt heltal, eller lad feltet være tomt.',
            'gatewayConfig.config[branding_id]' => 'Indtast et positivt heltal, eller lad feltet være tomt.',
        ], $this->messagesOf($paymentMethod));
    }

    /**
     * Until the plugin says what an option a later payum-quickpay adds accepts, a rejected value of it still shows a
     * translated message, on its field
     *
     * @test
     */
    public function it_words_a_rejected_option_without_a_message_of_its_own_generically(): void
    {
        self::bootKernel();
        $this->translatorIn('da');

        // The branding id stands in for an option the plugin has no message for yet
        $constraint = new UsableGatewayConfiguration();
        unset($constraint->optionMessages['branding_id']);

        $violations = $this->validator()->validate(
            GatewayConfigurations::entity([...GatewayConfigurations::USABLE, 'branding_id' => 'Our brand'], 'quickpay_dk'),
            $constraint,
        );

        self::assertCount(1, $violations);
        self::assertSame('config[branding_id]', $violations->get(0)->getPropertyPath());
        self::assertSame('Quickpay-gatewayen accepterer ikke værdien af gateway-indstillingen "branding_id".', $violations->get(0)->getMessage());
    }

    /**
     * The admin reads what the field accepts; payum-quickpay's reason, which says what was wrong with the value, is
     * kept in the log
     *
     * @test
     */
    public function it_logs_payum_quickpays_reason_for_rejecting_an_option(): void
    {
        self::bootKernel();
        $logger = $this->recordValidatorLogs();

        $this->violationsOf($this->createPaymentMethod('quickpay_dk', [...GatewayConfigurations::USABLE, 'branding_id' => 'Our brand']));

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::INFO, $level);
        self::assertStringContainsString('The "branding_id" option must be a positive integer (or an integer string), got \'Our brand\'', $message);
        self::assertSame('branding_id', $context['option'] ?? null);
    }

    /**
     * @test
     */
    public function it_reports_a_missing_api_key_once_without_asking_quickpay(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', ['api_key' => '', 'private_key' => 'the-private-key']);

        self::assertSame(['gatewayConfig.config[api_key]'], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function it_leaves_the_gateway_configurations_of_other_gateways_alone(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('bank_transfer', ['branding_id' => 'Our brand'], factoryName: 'offline');

        self::assertSame([], $this->violationsOf($paymentMethod));
    }

    /**
     * @test
     */
    public function it_accepts_a_usable_quickpay_gateway_configuration_whose_api_key_quickpay_accepts(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);

        self::assertSame([], $this->violationsOf($paymentMethod));
        self::assertSame(['GET /ping'], $this->quickpay()->sentRequests(), 'The api key is verified with Quickpay');
    }

    /**
     * Quickpay answers 401 on /ping for a valid key whose api user lacks the /ping permission too, so only a
     * rejected /payments read as well rejects the key
     *
     * @test
     */
    public function it_refuses_an_api_key_quickpay_rejects(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));
        $this->quickpay()->respond('GET', '/payments', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);

        self::assertSame(['gatewayConfig.config[api_key]'], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame(['GET /ping', 'GET /payments'], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function it_accepts_an_api_key_whose_api_user_lacks_the_ping_permission(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));
        $this->quickpay()->respond('GET', '/payments', new Response(200, ['Content-Type' => 'application/json'], '[]'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);

        self::assertSame([], $this->violationsOf($paymentMethod));
        self::assertSame(['GET /ping', 'GET /payments'], $this->quickpay()->sentRequests());
    }

    /**
     * Checkout sends the agreement id when it creates the payment link, so an agreement the account doesn't have would
     * break it
     *
     * @test
     */
    public function it_refuses_an_agreement_quickpay_does_not_find(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));
        $this->quickpay()->respond('GET', '/agreements/12345', new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not found"}'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', [...GatewayConfigurations::USABLE, 'agreement_id' => 12345]);

        self::assertSame(['gatewayConfig.config[agreement_id]'], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame(['GET /ping', 'GET /agreements/12345'], $this->quickpay()->sentRequests());
    }

    /**
     * The api user may lack the /agreements permission, and an unreachable Quickpay never makes a payment method
     * unsaveable. Why the agreement couldn't be verified is logged.
     *
     * @test
     *
     * @dataProvider unverifiableAgreements
     */
    public function it_accepts_an_agreement_quickpay_could_not_verify(Response $response, string $why): void
    {
        self::bootKernel();
        $logger = $this->recordValidatorLogs();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));
        $this->quickpay()->respond('GET', '/agreements/12345', $response);

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', [...GatewayConfigurations::USABLE, 'agreement_id' => 12345]);

        self::assertSame([], $this->violationsOf($paymentMethod));
        self::assertSame(['GET /ping', 'GET /agreements/12345'], $this->quickpay()->sentRequests());
        self::assertSame([[
            LogLevel::INFO,
            sprintf('Could not verify agreement 12345 of the Quickpay gateway "quickpay_dk", so it was accepted unverified: %s', $why),
            ['gateway' => 'quickpay_dk'],
        ]], $logger->records);
    }

    /**
     * Quickpay then uses the account's default Payment Window agreement
     *
     * @test
     *
     * @dataProvider withoutAgreementId
     */
    public function it_asks_quickpay_nothing_about_the_agreement_without_an_agreement_id(mixed $agreementId): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', [...GatewayConfigurations::USABLE, 'agreement_id' => $agreementId]);

        self::assertSame([], $this->violationsOf($paymentMethod));
        self::assertSame(['GET /ping'], $this->quickpay()->sentRequests());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function withoutAgreementId(): iterable
    {
        yield 'none' => [null];
        yield 'an empty one' => [''];
    }

    /**
     * Quickpay's answer about the agreement would prove nothing, and a rejected api key is one error, not two
     *
     * @test
     *
     * @dataProvider apiKeysNotAccepted
     *
     * @param array<string, Response> $responses
     * @param list<string> $violations
     */
    public function it_asks_quickpay_nothing_about_the_agreement_unless_it_accepts_the_api_key(array $responses, array $violations): void
    {
        self::bootKernel();

        foreach ($responses as $path => $response) {
            $this->quickpay()->respond('GET', $path, $response);
        }

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', [...GatewayConfigurations::USABLE, 'agreement_id' => 12345]);

        self::assertSame($violations, array_keys($this->violationsOf($paymentMethod)));
        self::assertSame(array_map(static fn (string $path): string => 'GET ' . $path, array_keys($responses)), $this->quickpay()->sentRequests());
    }

    /**
     * @return iterable<string, array{array<string, Response>, list<string>}>
     */
    public static function apiKeysNotAccepted(): iterable
    {
        yield 'rejected' => [
            [
                '/ping' => new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'),
                '/payments' => new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'),
            ],
            ['gatewayConfig.config[api_key]'],
        ];
        yield 'not verifiable' => [
            ['/ping' => new Response(500, ['Content-Type' => 'application/json'], '{"message":"Internal server error"}')],
            [],
        ];
    }

    /**
     * @return iterable<string, array{Response, string}>
     */
    public static function unverifiableAgreements(): iterable
    {
        yield 'unauthorized' => [
            new Response(401, ['Content-Type' => 'application/json'], '{"message":"Not authorized"}'),
            'the api user may lack the /agreements permission',
        ];
        yield 'forbidden' => [
            new Response(403, ['Content-Type' => 'application/json'], '{"message":"Not authorized"}'),
            'the api user may lack the /agreements permission',
        ];
        yield 'a server error' => [
            new Response(500, ['Content-Type' => 'application/json'], '{"message":"Internal server error"}'),
            'The status code was: 500. [GET https://api.quickpay.net/agreements/12345] The body was: {"message":"Internal server error"}.',
        ];
    }

    /**
     * As one that PayumBundle encrypted is until something decrypts it: encrypting gateway configurations is not
     * supported
     *
     * @test
     */
    public function it_refuses_a_quickpay_gateway_configuration_that_reads_as_empty(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);
        $paymentMethod->setGatewayConfig(GatewayConfigurations::encryptedEntity('quickpay_dk'));

        self::assertSame(['gatewayConfig.config'], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * Only an explicit rejection of the key counts: an unreachable Quickpay never makes a payment method unsaveable.
     * Why the key couldn't be verified is logged.
     *
     * @test
     *
     * @dataProvider unanswered
     *
     * @param array<string, Response> $responses
     * @param list<string> $requests
     */
    public function it_accepts_an_api_key_quickpay_could_not_verify(array $responses, array $requests, string $quickpaysError): void
    {
        self::bootKernel();
        $logger = $this->recordValidatorLogs();

        foreach ($responses as $path => $response) {
            $this->quickpay()->respond('GET', $path, $response);
        }

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);

        self::assertSame([], $this->violationsOf($paymentMethod));
        self::assertSame($requests, $this->quickpay()->sentRequests());
        self::assertSame([[
            LogLevel::INFO,
            sprintf('Could not verify the api key of the Quickpay gateway "quickpay_dk", so it was accepted unverified: %s', $quickpaysError),
            ['gateway' => 'quickpay_dk'],
        ]], $logger->records);
    }

    /**
     * @return iterable<string, array{array<string, Response>, list<string>, string}>
     */
    public static function unanswered(): iterable
    {
        yield 'an error on /ping' => [
            ['/ping' => new Response(500, ['Content-Type' => 'application/json'], '{"message":"Internal server error"}')],
            ['GET /ping'],
            'The status code was: 500. [GET https://api.quickpay.net/ping] The body was: {"message":"Internal server error"}.',
        ];
        yield 'an error on the /payments read after /ping refused' => [
            [
                '/ping' => new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'),
                '/payments' => new Response(502, ['Content-Type' => 'application/json'], '{"message":"Bad gateway"}'),
            ],
            ['GET /ping', 'GET /payments'],
            'The status code was: 502. [GET https://api.quickpay.net/payments] The body was: {"message":"Bad gateway"}.',
        ];
    }

    /**
     * Every refusal is shown in the admin's language: a message per gateway option payum-quickpay rejects, the
     * fallback for an option without one, a rejected api key, an agreement Quickpay doesn't find and an unreadable
     * configuration
     *
     * @test
     *
     * @dataProvider locales
     */
    public function it_words_its_refusals_in_every_locale(string $locale): void
    {
        self::bootKernel();
        $translator = $this->translatorIn($locale);

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));
        $this->quickpay()->respond('GET', '/agreements/12345', new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not found"}'));
        $agreementNotFound = array_values($this->violationsOf($this->createPaymentMethod('quickpay_de', [...GatewayConfigurations::USABLE, 'agreement_id' => 12345])));

        $this->quickpay()->respond('GET', '/ping', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));
        $this->quickpay()->respond('GET', '/payments', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));

        $everyOptionRejected = $this->createPaymentMethod('quickpay_no', [
            'api_key' => '',
            'private_key' => '',
            'order_prefix' => ['ab'],
            'payment_methods' => ['visa', 1],
            'language' => ['da'],
            'auto_capture' => 'maybe',
            'synchronized' => 'maybe',
            'agreement_id' => 0,
            'branding_id' => 'Our brand',
        ]);
        $rejectedApiKey = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);
        $unreadable = $this->createPaymentMethod('quickpay_se', GatewayConfigurations::USABLE);
        $unreadable->setGatewayConfig(GatewayConfigurations::encryptedEntity('quickpay_se'));

        $withoutOptionMessages = new UsableGatewayConfiguration();
        $withoutOptionMessages->optionMessages = [];

        $violations = [
            ...array_values($this->violationsOf($everyOptionRejected)),
            ...$this->validator()->validate(
                GatewayConfigurations::entity([...GatewayConfigurations::USABLE, 'branding_id' => 'Our brand'], 'quickpay_fi'),
                $withoutOptionMessages,
            ),
            ...array_values($this->violationsOf($rejectedApiKey)),
            ...array_values($this->violationsOf($unreadable)),
            ...$agreementNotFound,
        ];
        self::assertCount(13, $violations);
        self::assertCount(13, array_unique(array_map(
            static fn (ConstraintViolationInterface $violation): string => $violation->getMessageTemplate(),
            $violations,
        )), 'Each gateway option has a message of its own');

        $catalogue = $translator->getCatalogue($locale);
        foreach ($violations as $violation) {
            self::assertTrue($catalogue->defines($violation->getMessageTemplate(), 'validators'), sprintf('"%s" is translated into "%s"', $violation->getMessageTemplate(), $locale));
            self::assertSame(
                strtr($catalogue->get($violation->getMessageTemplate(), 'validators'), $violation->getParameters()),
                $violation->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (['cs', 'da', 'de', 'en', 'es', 'fi', 'fr', 'hu', 'it', 'nl', 'no', 'pl', 'pt', 'ro', 'sv', 'uk'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * Gives the save validation's validator a logger recording what it logs
     *
     * @return object{records: list<array{mixed, string, array<array-key, mixed>}>}
     */
    private function recordValidatorLogs(): object
    {
        $logger = new class() extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<array-key, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        $validator = self::getContainer()->get(UsableGatewayConfigurationValidator::class);
        self::assertInstanceOf(UsableGatewayConfigurationValidator::class, $validator);
        $validator->setLogger($logger);

        return $logger;
    }

    /**
     * Switches the translator, and so the violations' messages, to the locale
     */
    private function translatorIn(string $locale): TranslatorBagInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        $translator->setLocale($locale);

        return $translator;
    }

    /**
     * The messages saving the payment method shows, keyed by their paths
     *
     * @return array<string, string>
     */
    private function messagesOf(PaymentMethodInterface $paymentMethod): array
    {
        return array_map(
            static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(),
            $this->violationsOf($paymentMethod),
        );
    }

    /**
     * The violations saving the payment method meets, keyed by their paths
     *
     * @return array<string, ConstraintViolationInterface>
     */
    private function violationsOf(PaymentMethodInterface $paymentMethod): array
    {
        $groupsGenerator = self::getContainer()->get(GatewayConfigGroupsGenerator::class);
        self::assertInstanceOf(GatewayConfigGroupsGenerator::class, $groupsGenerator);

        $violations = [];
        foreach ($this->validator()->validate($paymentMethod, groups: $groupsGenerator($paymentMethod)) as $violation) {
            $violations[$violation->getPropertyPath()] = $violation;
        }

        return $violations;
    }

    private function validator(): ValidatorInterface
    {
        $validator = self::getContainer()->get('validator');
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        return $validator;
    }
}
