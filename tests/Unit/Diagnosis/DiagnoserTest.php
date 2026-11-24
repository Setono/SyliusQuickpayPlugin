<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Diagnosis;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusQuickpayPlugin\Diagnosis\Diagnoser;
use Setono\SyliusQuickpayPlugin\Diagnosis\Finding;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingKind;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingOutcome;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\OrderId\LatestOrderNumberProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\Verdict;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;

/**
 * The diagnosis owns which check runs when; the doctor and save validation only show its findings
 */
final class DiagnoserTest extends TestCase
{
    use ProphecyTrait;

    private const TEST_PAYMENT_NOTE = 'test payment 999 with order id "qp_dr1a2b3c" remains on the account — it never carries money';

    private const SKIPPED = 'Skipped the checks against Quickpay of this gateway — they need a gateway configuration the gateway can be built from';

    private const NO_AGREEMENT_ID = 'No agreement id configured — Quickpay uses the account\'s default Payment Window agreement';

    /** @var ObjectProphecy<GatewayChecksInterface> */
    private ObjectProphecy $gatewayChecks;

    /** @var ObjectProphecy<LatestOrderNumberProviderInterface> */
    private ObjectProphecy $latestOrderNumberProvider;

    protected function setUp(): void
    {
        $this->gatewayChecks = $this->prophesize(GatewayChecksInterface::class);
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::passed());

        $this->latestOrderNumberProvider = $this->prophesize(LatestOrderNumberProviderInterface::class);
        $this->latestOrderNumberProvider->getLatestOrderNumber()->willReturn('000000123');
    }

    /**
     * @test
     */
    public function it_diagnoses_a_healthy_configuration(): void
    {
        $configuration = GatewayConfigurations::of(self::healthyConfig());
        $this->gatewayChecks->apiKey($configuration)->shouldBeCalledOnce()->willReturn(Verdict::passed());

        $findings = $this->diagnose($configuration);

        self::assertFindings([
            [FindingKind::OrderId, FindingOutcome::Passed, 'The order id of the latest order, "qp_000000123", is one Quickpay accepts'],
            [FindingKind::ApiKey, FindingOutcome::Passed, 'The api key is accepted by Quickpay'],
            [FindingKind::Agreement, FindingOutcome::Passed, self::NO_AGREEMENT_ID],
        ], $findings);
        self::assertSame('qp_000000123', $findings[0]->orderId);
        self::assertSame('000000123', $findings[0]->orderNumber);
    }

    /**
     * @test
     */
    public function it_passes_an_api_key_with_the_note_saying_how_it_was_proven(): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::passed('checked through /payments: the api user lacks the /ping permission, which is harmless'));

        self::assertFinding(
            FindingOutcome::Passed,
            'The api key is accepted by Quickpay (checked through /payments: the api user lacks the /ping permission, which is harmless)',
            $this->findingOf(FindingKind::ApiKey, $this->diagnose(GatewayConfigurations::of(self::healthyConfig()))),
        );
    }

    /**
     * @test
     */
    public function it_fails_an_api_key_quickpay_rejects(): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::failed('Quickpay rejects the api key'));

        self::assertFinding(
            FindingOutcome::Failed,
            'Quickpay rejects the api key',
            $this->findingOf(FindingKind::ApiKey, $this->diagnose(GatewayConfigurations::of(self::healthyConfig()))),
        );
    }

    /**
     * Quickpay's answer proved nothing, so nothing is known to be wrong
     *
     * @test
     */
    public function it_warns_about_an_api_key_whose_check_is_undetermined(): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::undetermined('The request could not be sent [GET https://api.quickpay.net/ping]: Idle timeout reached'));

        self::assertFinding(
            FindingOutcome::Warning,
            'Could not check the api key: The request could not be sent [GET https://api.quickpay.net/ping]: Idle timeout reached',
            $this->findingOf(FindingKind::ApiKey, $this->diagnose(GatewayConfigurations::of(self::healthyConfig()))),
        );
    }

    /**
     * Each of these passed the doctor's own rules once: the agreement as id 12, the branding id unchecked. Nothing is
     * asked of Quickpay: the gateway can't be built, and the checks' errors would only follow from the configuration's.
     *
     * @test
     */
    public function it_fails_each_gateway_option_the_gateway_rejects_and_asks_quickpay_nothing(): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->shouldNotBeCalled();
        $this->gatewayChecks->agreement(Argument::any())->shouldNotBeCalled();
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->shouldNotBeCalled();

        $findings = $this->diagnose(GatewayConfigurations::of([
            'private_key' => 'the-private-key',
            'agreement_id' => 12.0,
            'branding_id' => 'Our brand',
            'use_authorize' => true,
        ]), live: true);

        self::assertSame([
            [FindingKind::RejectedOption, FindingOutcome::Failed, 'api_key'],
            [FindingKind::RejectedOption, FindingOutcome::Failed, 'agreement_id'],
            [FindingKind::RejectedOption, FindingOutcome::Failed, 'branding_id'],
            [FindingKind::OrderId, FindingOutcome::Passed, null],
            [FindingKind::NoOrderPrefix, FindingOutcome::Passed, null],
            [FindingKind::QuickpayChecksSkipped, FindingOutcome::Warning, null],
        ], array_map(static fn (Finding $finding): array => [$finding->kind, $finding->outcome, $finding->option], $findings));
        self::assertSame('The "api_key" option is required', $findings[0]->text);
        self::assertStringStartsWith('The "agreement_id" option must be a positive integer', $findings[1]->text);
        self::assertStringStartsWith('The "branding_id" option must be a positive integer', $findings[2]->text);
        self::assertSame(self::SKIPPED, $findings[5]->text);
    }

    /**
     * Nothing can be said about the stored options of a configuration that reads as empty
     *
     * @test
     */
    public function it_fails_an_encrypted_gateway_configuration_as_unsupported(): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->shouldNotBeCalled();
        $this->latestOrderNumberProvider->getLatestOrderNumber()->shouldNotBeCalled();

        $findings = $this->diagnose(GatewayConfigurations::encrypted());

        self::assertSame(
            [[FindingKind::Unreadable, FindingOutcome::Failed], [FindingKind::QuickpayChecksSkipped, FindingOutcome::Warning]],
            array_map(static fn (Finding $finding): array => [$finding->kind, $finding->outcome], $findings),
        );
        self::assertStringEndsWith(
            'reads as empty, as an encrypted one does before Payum decrypts it. Encrypting gateway configurations is not supported. See the README\'s Configuration section.',
            $findings[0]->text,
        );
    }

    /**
     * They say what is stored, whether or not the gateway can be built from it
     *
     * @test
     */
    public function it_warns_about_the_stored_options_of_a_configuration_the_gateway_rejects(): void
    {
        $findings = $this->diagnose(GatewayConfigurations::of(['apikey' => 'the-api-key']));

        self::assertFindings([
            [FindingKind::RejectedOption, FindingOutcome::Failed, 'The "private_key" option is required'],
            [FindingKind::DeprecatedOptions, FindingOutcome::Warning, 'The gateway configuration uses a deprecated gateway option (a 1.x option name, or auto_capture) — re-save the payment method in the admin to replace it before 3.0 stops reading it'],
            [FindingKind::CaptureModeNotSet, FindingOutcome::Warning, 'The capture mode is not set, so checkout captures immediately — choose a capture mode on the payment method in the admin'],
            [FindingKind::OrderId, FindingOutcome::Passed, 'The order id of the latest order, "000000123", is one Quickpay accepts'],
            [FindingKind::NoOrderPrefix, FindingOutcome::Passed, 'No order prefix configured — make sure order numbers are unique on the Quickpay account'],
            [FindingKind::QuickpayChecksSkipped, FindingOutcome::Warning, self::SKIPPED],
        ], $findings);
    }

    /**
     * @test
     *
     * @dataProvider deprecatedGatewayOptions
     *
     * @param array<array-key, mixed> $config
     */
    public function it_warns_when_a_deprecated_gateway_option_is_stored(array $config): void
    {
        $findings = $this->diagnose(GatewayConfigurations::of($config));

        self::assertSame(FindingOutcome::Warning, $this->findingOf(FindingKind::DeprecatedOptions, $findings)->outcome);
        self::assertSame(FindingKind::DeprecatedOptions, $findings[0]->kind, 'The stored options come before the checks');
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function deprecatedGatewayOptions(): iterable
    {
        yield 'a 1.x option name' => [['apikey' => 'the-api-key', 'private_key' => 'the-private-key', 'use_authorize' => true]];
        yield 'auto_capture on' => [self::healthyConfig(['auto_capture' => true])];
    }

    /**
     * As fixtures and the Admin API may store it
     *
     * @test
     */
    public function it_warns_when_the_capture_mode_is_not_set(): void
    {
        $findings = $this->diagnose(GatewayConfigurations::of(GatewayConfigurations::USABLE));

        self::assertSame(FindingOutcome::Warning, $this->findingOf(FindingKind::CaptureModeNotSet, $findings)->outcome);
        self::assertSame(FindingKind::CaptureModeNotSet, $findings[0]->kind, 'The stored options come before the checks');
    }

    /**
     * The order id needs no gateway, only the order prefix
     *
     * @test
     */
    public function it_checks_the_order_id_of_a_configuration_the_gateway_rejects(): void
    {
        $findings = $this->diagnose(GatewayConfigurations::of(['api_key' => 'the-api-key', 'order_prefix' => 'qp_way_too_long_', 'use_authorize' => true]));

        $orderId = $this->findingOf(FindingKind::OrderId, $findings);
        self::assertSame(FindingOutcome::Failed, $orderId->outcome);
        self::assertStringStartsWith('The Quickpay order id "qp_way_too_long_000000123" (25 characters) is not one Quickpay accepts', $orderId->text);
        self::assertSame('qp_way_too_long_000000123', $orderId->orderId);
        self::assertSame('000000123', $orderId->orderNumber);
    }

    /**
     * payum-quickpay's violation already says what is wrong with the order prefix, and no order id can be built from it
     *
     * @test
     */
    public function it_checks_no_order_id_for_an_order_prefix_the_gateway_rejects(): void
    {
        $this->latestOrderNumberProvider->getLatestOrderNumber()->shouldNotBeCalled();

        $findings = $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['order_prefix' => 'qp#'])));

        self::assertSame(
            [[FindingKind::RejectedOption, 'order_prefix'], [FindingKind::QuickpayChecksSkipped, null]],
            array_map(static fn (Finding $finding): array => [$finding->kind, $finding->option], $findings),
        );
        self::assertStringStartsWith('The "order_prefix" option must be at most 19 letters', $findings[0]->text);
    }

    /**
     * @test
     */
    public function it_fails_an_order_id_quickpay_refuses(): void
    {
        $orderId = $this->findingOf(FindingKind::OrderId, $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['order_prefix' => 'qp_shop_2026_']))));

        self::assertFinding(
            FindingOutcome::Failed,
            'The Quickpay order id "qp_shop_2026_000000123" (22 characters) is not one Quickpay accepts: 4–20 characters of '
            . 'letters, digits, space, ".", "_" and "-". It is built from the order prefix "qp_shop_2026_" and the payment '
            . 'number "000000123" — adjust the prefix so the two together fit. The number is the latest order\'s, and checkout '
            . 'fails until the two fit.',
            $orderId,
        );
        self::assertSame('qp_shop_2026_000000123', $orderId->orderId);
        self::assertSame('000000123', $orderId->orderNumber);
    }

    /**
     * A store numbering its orders its own way is held to its own numbers, not to Sylius' 9 digits
     *
     * @test
     */
    public function it_passes_an_order_prefix_that_fits_the_stores_own_order_numbers(): void
    {
        $this->latestOrderNumberProvider->getLatestOrderNumber()->willReturn('1234');

        $orderId = $this->findingOf(FindingKind::OrderId, $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['order_prefix' => 'qp_shop_2026_']))));

        self::assertFinding(FindingOutcome::Passed, 'The order id of the latest order, "qp_shop_2026_1234", is one Quickpay accepts', $orderId);
    }

    /**
     * No prefix fits a number Quickpay refuses
     *
     * @test
     */
    public function it_fails_when_the_latest_order_number_holds_a_character_quickpay_refuses(): void
    {
        $this->latestOrderNumberProvider->getLatestOrderNumber()->willReturn('2026/123');

        $orderId = $this->findingOf(FindingKind::OrderId, $this->diagnose(GatewayConfigurations::of(self::healthyConfig())));

        self::assertSame(FindingOutcome::Failed, $orderId->outcome);
        self::assertStringStartsWith('The Quickpay order id "qp_2026/123" (11 characters) is not one Quickpay accepts', $orderId->text);
    }

    /**
     * @test
     */
    public function it_checks_sylius_default_first_order_number_while_there_are_no_orders(): void
    {
        $this->latestOrderNumberProvider->getLatestOrderNumber()->willReturn(null);

        $orderId = $this->findingOf(FindingKind::OrderId, $this->diagnose(GatewayConfigurations::of(self::healthyConfig())));

        self::assertFinding(
            FindingOutcome::Passed,
            'No orders yet: a Sylius order number like "000000001" gives the order id "qp_000000001", which Quickpay accepts',
            $orderId,
        );
        self::assertSame('qp_000000001', $orderId->orderId);
        self::assertSame('000000001', $orderId->orderNumber);
    }

    /**
     * @test
     */
    public function it_fails_with_sylius_default_first_order_number_while_there_are_no_orders(): void
    {
        $this->latestOrderNumberProvider->getLatestOrderNumber()->willReturn(null);

        $orderId = $this->findingOf(FindingKind::OrderId, $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['order_prefix' => 'qp_shop_2026_']))));

        self::assertSame(FindingOutcome::Failed, $orderId->outcome);
        self::assertStringStartsWith('The Quickpay order id "qp_shop_2026_000000001" (22 characters) is not one Quickpay accepts', $orderId->text);
        self::assertStringEndsWith('There are no orders yet, so the number is Sylius\' default first order number, and checkout fails until the two fit.', $orderId->text);
        self::assertSame('000000001', $orderId->orderNumber);
    }

    /**
     * A store's newest order changes between saves, so each diagnosis reads it
     *
     * @test
     */
    public function it_reads_the_latest_order_number_for_each_diagnosis(): void
    {
        $this->latestOrderNumberProvider->getLatestOrderNumber()->willReturn('000000123', '000000124');
        $diagnoser = $this->diagnoser();
        $configuration = GatewayConfigurations::of(self::healthyConfig());

        self::assertSame('qp_000000123', $this->findingOf(FindingKind::OrderId, $diagnoser->diagnose($configuration))->orderId);
        self::assertSame('qp_000000124', $this->findingOf(FindingKind::OrderId, $diagnoser->diagnose($configuration))->orderId);
    }

    /**
     * @test
     */
    public function it_reminds_that_order_numbers_must_be_unique_without_an_order_prefix(): void
    {
        $findings = $this->diagnose(GatewayConfigurations::of([...GatewayConfigurations::USABLE, 'use_authorize' => true]));

        self::assertFindings([
            [FindingKind::OrderId, FindingOutcome::Passed, 'The order id of the latest order, "000000123", is one Quickpay accepts'],
            [FindingKind::NoOrderPrefix, FindingOutcome::Passed, 'No order prefix configured — make sure order numbers are unique on the Quickpay account'],
            [FindingKind::ApiKey, FindingOutcome::Passed, 'The api key is accepted by Quickpay'],
            [FindingKind::Agreement, FindingOutcome::Passed, self::NO_AGREEMENT_ID],
        ], $findings);
    }

    /**
     * @test
     */
    public function it_checks_the_agreement_the_gateway_sends(): void
    {
        $configuration = GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345]));
        $this->gatewayChecks->agreement($configuration)->shouldBeCalledOnce()->willReturn(Verdict::passed());

        $agreement = $this->findingOf(FindingKind::Agreement, $this->diagnose($configuration));

        self::assertFinding(FindingOutcome::Passed, 'Agreement 12345 exists on the account', $agreement);
        self::assertSame(12345, $agreement->agreementId);
    }

    /**
     * @test
     */
    public function it_names_the_agreement_the_gateway_sends(): void
    {
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::passed());

        $agreement = $this->findingOf(FindingKind::Agreement, $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => null, 'agreement' => '7']))));

        self::assertFinding(FindingOutcome::Passed, 'Agreement 7 exists on the account', $agreement);
        self::assertSame(7, $agreement->agreementId);
    }

    /**
     * Quickpay then uses the account's default agreement, and there is nothing to ask it, so the api key's verdict
     * doesn't matter. The gateway sends no agreement id for a blank one either.
     *
     * @test
     *
     * @dataProvider withoutAgreementIdUnderEachApiKeyVerdict
     */
    public function it_passes_without_an_agreement_id_whatever_the_api_keys_verdict(string $agreementId, Verdict $apiKeyVerdict): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->willReturn($apiKeyVerdict);
        $this->gatewayChecks->agreement(Argument::any())->shouldNotBeCalled();

        $agreement = $this->findingOf(FindingKind::Agreement, $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => $agreementId]))));

        self::assertFinding(FindingOutcome::Passed, self::NO_AGREEMENT_ID, $agreement);
        self::assertNull($agreement->agreementId);
    }

    /**
     * @return iterable<string, array{string, Verdict}>
     */
    public static function withoutAgreementIdUnderEachApiKeyVerdict(): iterable
    {
        foreach (['none' => '', 'a blank one' => '   '] as $name => $agreementId) {
            yield sprintf('%s, api key passed', $name) => [$agreementId, Verdict::passed()];
            yield sprintf('%s, api key failed', $name) => [$agreementId, Verdict::failed('Quickpay rejects the api key')];
            yield sprintf('%s, api key undetermined', $name) => [$agreementId, Verdict::undetermined('The request could not be sent')];
        }
    }

    /**
     * @test
     */
    public function it_fails_an_agreement_quickpay_does_not_find(): void
    {
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::failed('Agreement 12345 does not exist on this Quickpay account'));

        $agreement = $this->findingOf(FindingKind::Agreement, $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345]))));

        self::assertFinding(FindingOutcome::Failed, 'Agreement 12345 does not exist on this Quickpay account', $agreement);
        self::assertSame(12345, $agreement->agreementId);
    }

    /**
     * Quickpay's answer proved nothing, so nothing is known to be wrong
     *
     * @test
     */
    public function it_warns_about_an_agreement_whose_check_is_undetermined(): void
    {
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::undetermined('the api user may lack the /agreements permission'));

        self::assertFinding(
            FindingOutcome::Warning,
            'Could not check agreement 12345: the api user may lack the /agreements permission',
            $this->findingOf(FindingKind::Agreement, $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])))),
        );
    }

    /**
     * Without an api key Quickpay accepts, their answers would prove nothing
     *
     * @test
     *
     * @dataProvider apiKeyVerdictsOtherThanPassed
     */
    public function it_skips_the_agreement_and_payment_link_checks_unless_quickpay_accepts_the_api_key(Verdict $apiKeyVerdict): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->willReturn($apiKeyVerdict);
        $this->gatewayChecks->agreement(Argument::any())->shouldNotBeCalled();
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->shouldNotBeCalled();

        $findings = $this->diagnose(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])), live: true);

        $agreement = $this->findingOf(FindingKind::Agreement, $findings);
        self::assertFinding(FindingOutcome::Warning, 'Skipped checking agreement 12345 — no usable api key', $agreement);
        self::assertSame(12345, $agreement->agreementId);
        self::assertFinding(FindingOutcome::Warning, 'Skipped checking the payment link permission — no usable api key', $this->findingOf(FindingKind::PaymentLinkPermission, $findings));
    }

    /**
     * @return iterable<string, array{Verdict}>
     */
    public static function apiKeyVerdictsOtherThanPassed(): iterable
    {
        yield 'failed' => [Verdict::failed('Quickpay rejects the api key')];
        yield 'undetermined' => [Verdict::undetermined('The request could not be sent')];
    }

    /**
     * It writes to the Quickpay account, so only on request
     *
     * @test
     */
    public function it_checks_the_payment_link_permission_only_when_live(): void
    {
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->shouldNotBeCalled();

        $findings = $this->diagnose(GatewayConfigurations::of(self::healthyConfig()));

        self::assertNotContains(FindingKind::PaymentLinkPermission, array_map(static fn (Finding $finding): FindingKind => $finding->kind, $findings));
    }

    /**
     * @test
     */
    public function it_checks_the_payment_link_permission_last_when_live(): void
    {
        $configuration = GatewayConfigurations::of(self::healthyConfig());
        $this->gatewayChecks->paymentLinkPermission($configuration)->shouldBeCalledOnce()->willReturn(Verdict::passed(self::TEST_PAYMENT_NOTE));

        $findings = $this->diagnose($configuration, live: true);

        self::assertFindings([
            [FindingKind::OrderId, FindingOutcome::Passed, 'The order id of the latest order, "qp_000000123", is one Quickpay accepts'],
            [FindingKind::ApiKey, FindingOutcome::Passed, 'The api key is accepted by Quickpay'],
            [FindingKind::Agreement, FindingOutcome::Passed, self::NO_AGREEMENT_ID],
            [FindingKind::PaymentLinkPermission, FindingOutcome::Passed, sprintf('The api user may create payment links (%s)', self::TEST_PAYMENT_NOTE)],
        ], $findings);
    }

    /**
     * @test
     */
    public function it_fails_a_payment_link_permission_quickpay_refuses(): void
    {
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->willReturn(Verdict::failed('The api user may not create payment links', self::TEST_PAYMENT_NOTE));

        self::assertFinding(
            FindingOutcome::Failed,
            sprintf('The api user may not create payment links (%s)', self::TEST_PAYMENT_NOTE),
            $this->findingOf(FindingKind::PaymentLinkPermission, $this->diagnose(GatewayConfigurations::of(self::healthyConfig()), live: true)),
        );
    }

    /**
     * @test
     */
    public function it_warns_about_a_payment_link_permission_whose_check_is_undetermined(): void
    {
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->willReturn(Verdict::undetermined('The request could not be sent [PUT https://api.quickpay.net/payments/999/link]: Idle timeout reached', self::TEST_PAYMENT_NOTE));

        self::assertFinding(
            FindingOutcome::Warning,
            sprintf('Could not check the payment link permission: The request could not be sent [PUT https://api.quickpay.net/payments/999/link]: Idle timeout reached (%s)', self::TEST_PAYMENT_NOTE),
            $this->findingOf(FindingKind::PaymentLinkPermission, $this->diagnose(GatewayConfigurations::of(self::healthyConfig()), live: true)),
        );
    }

    /**
     * @return list<Finding>
     */
    private function diagnose(GatewayConfiguration $configuration, bool $live = false): array
    {
        return $this->diagnoser()->diagnose($configuration, $live);
    }

    private function diagnoser(): Diagnoser
    {
        return new Diagnoser($this->gatewayChecks->reveal(), $this->latestOrderNumberProvider->reveal());
    }

    /**
     * The one finding of the kind
     *
     * @param list<Finding> $findings
     */
    private function findingOf(FindingKind $kind, array $findings): Finding
    {
        $ofKind = array_values(array_filter($findings, static fn (Finding $finding): bool => $kind === $finding->kind));
        self::assertCount(1, $ofKind, sprintf('The diagnosis has one finding of the kind "%s"', $kind->value));

        return $ofKind[0];
    }

    private static function assertFinding(FindingOutcome $outcome, string $text, Finding $finding): void
    {
        self::assertSame([$outcome, $text], [$finding->outcome, $finding->text]);
    }

    /**
     * The findings, in order
     *
     * @param list<array{FindingKind, FindingOutcome, string}> $expected
     * @param list<Finding> $findings
     */
    private static function assertFindings(array $expected, array $findings): void
    {
        self::assertSame($expected, array_map(
            static fn (Finding $finding): array => [$finding->kind, $finding->outcome, $finding->text],
            $findings,
        ));
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private static function healthyConfig(array $overrides = []): array
    {
        return $overrides + GatewayConfigurations::USABLE + [
            'order_prefix' => 'qp_',
            'use_authorize' => true,
        ];
    }
}
