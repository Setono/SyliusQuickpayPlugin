<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\GatewayConfiguration;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\CaptureMode;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;

final class GatewayConfigurationTest extends TestCase
{
    /**
     * @test
     */
    public function it_reads_a_stored_gateway_configuration_with_the_gateways_own_rules(): void
    {
        $configuration = GatewayConfigurations::of([
            'apikey' => 'the-1.x-api-key',
            'api_key' => null,
            'private_key' => 'the-private-key',
            'order_prefix' => 42,
            'agreement_id' => '5',
            'payment_methods' => ['visa', ' mobilepay ', ''],
        ], gatewayName: 'quickpay_dk');

        self::assertSame('quickpay_dk', $configuration->gatewayName);

        $options = $configuration->options();
        self::assertSame('the-1.x-api-key', $options->apiKey);
        self::assertSame('42', $options->orderPrefix);
        self::assertSame(5, $options->agreementId);
        self::assertSame('visa,mobilepay', $options->paymentMethods);
    }

    /**
     * @test
     */
    public function it_reads_a_stored_config_array_like_the_gateway_config_holding_it(): void
    {
        $config = ['apikey' => 'the-1.x-api-key', 'private_key' => 'the-private-key', 'branding_id' => ' 7 '];

        $configuration = GatewayConfiguration::fromConfig($config);

        self::assertEquals(GatewayConfigurations::of($config)->options(), $configuration->options());
        self::assertSame(7, $configuration->options()->brandingId);
    }

    /**
     * @test
     */
    public function an_invalid_gateway_configuration_is_unusable_and_lists_every_violation_the_gateway_finds(): void
    {
        $configuration = GatewayConfigurations::of(['api_key' => 'the-api-key', 'agreement_id' => 0, 'branding_id' => 'Our brand'], gatewayName: 'quickpay_dk');

        try {
            $configuration->options();
            self::fail('The configuration was read');
        } catch (UnusableGatewayConfiguration $e) {
            self::assertSame('quickpay_dk', $e->gatewayName);
            self::assertFalse($e->isUnreadable());
            self::assertSame(['private_key', 'agreement_id', 'branding_id'], array_keys($e->getViolations()));
            self::assertSame('The "private_key" option is required', $e->getViolations()['private_key']);
            self::assertStringContainsString('"quickpay_dk"', $e->getMessage());
            self::assertStringContainsString('The "private_key" option is required', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function it_reads_a_configuration_the_gateway_rejects_with_each_rejected_option_as_stored(): void
    {
        $values = GatewayConfigurations::of([
            // no api key, which the gateway requires
            'privatekey' => 'the-private-key',
            'payment_methods' => [' creditcard', '', 'mobilepay '],
            'synchronized' => 'yes',
            'agreement_id' => '1e3',
            'branding_id' => ['Our brand'],
            'language' => 'da',
        ])->optionsWithRejectedAsStored();

        self::assertEqualsCanonicalizing(['api_key', 'agreement_id', 'branding_id'], $values->rejected);
        self::assertTrue($values->isRejected('agreement_id'));
        self::assertFalse($values->isRejected('payment_methods'));
        self::assertSame([
            'agreement_id' => '1e3',
            'api_key' => null,
            'auto_capture' => false,
            'branding_id' => ['Our brand'],
            'language' => 'da',
            'order_prefix' => '',
            'payment_methods' => 'creditcard,mobilepay',
            'private_key' => 'the-private-key',
            'synchronized' => true,
        ], self::sortedByOption($values->values));
    }

    /**
     * @test
     */
    public function it_reads_a_configuration_the_gateway_accepts_as_the_gateway_reads_it_when_reading_rejected_options_as_stored(): void
    {
        $values = GatewayConfigurations::of([
            'apikey' => 'the-1.x-api-key',
            'private_key' => 'the-private-key',
            'order_prefix' => 42,
            'agreement' => ' 5 ',
            'branding_id' => 7,
            'auto_capture' => 'yes',
        ])->optionsWithRejectedAsStored();

        self::assertSame([], $values->rejected);
        self::assertSame([
            'agreement_id' => 5,
            'api_key' => 'the-1.x-api-key',
            'auto_capture' => true,
            'branding_id' => 7,
            'language' => 'en',
            'order_prefix' => '42',
            'payment_methods' => null,
            'private_key' => 'the-private-key',
            'synchronized' => false,
        ], self::sortedByOption($values->values));
    }

    /**
     * @test
     */
    public function it_reads_an_encrypted_configuration_as_unusable_when_reading_rejected_options_as_stored(): void
    {
        $this->expectException(UnusableGatewayConfiguration::class);
        $this->expectExceptionMessage('Encrypting gateway configurations is not supported');

        GatewayConfigurations::encrypted()->optionsWithRejectedAsStored();
    }

    /**
     * @test
     *
     * @dataProvider rejectedCaptureModes
     *
     * @param array<string, mixed> $stored
     */
    public function it_reads_the_capture_mode_of_a_configuration_the_gateway_rejects_from_the_options_it_accepts(array $stored, CaptureMode $expected): void
    {
        // no private key: the gateway rejects the configuration
        $values = GatewayConfigurations::of(['api_key' => 'the-api-key'] + $stored)->optionsWithRejectedAsStored();

        self::assertSame($expected, $values->captureMode);
    }

    /**
     * As for a usable configuration, unless the gateway rejects `auto_capture` too
     *
     * @return iterable<string, array{array<string, mixed>, CaptureMode}>
     */
    public static function rejectedCaptureModes(): iterable
    {
        yield 'use_authorize true' => [['use_authorize' => true], CaptureMode::OnCompletion];
        yield "use_authorize 'false'" => [['use_authorize' => 'false'], CaptureMode::OnCompletion];
        yield 'use_authorize false' => [['use_authorize' => false], CaptureMode::Immediately];
        yield 'no use_authorize' => [[], CaptureMode::Immediately];
        yield 'use_authorize true and auto_capture on' => [['use_authorize' => true, 'auto_capture' => 1], CaptureMode::Immediately];
        yield 'use_authorize true and auto_capture off' => [['use_authorize' => true, 'auto_capture' => 'no'], CaptureMode::OnCompletion];
        yield 'use_authorize true and an auto_capture the gateway rejects' => [['use_authorize' => true, 'auto_capture' => 'maybe'], CaptureMode::OnCompletion];
    }

    /**
     * @test
     */
    public function it_reads_the_capture_mode_of_a_usable_configuration_as_checkout_uses_it_when_reading_rejected_options_as_stored(): void
    {
        self::assertSame(
            CaptureMode::Immediately,
            GatewayConfigurations::of(GatewayConfigurations::USABLE + ['use_authorize' => true, 'auto_capture' => 'yes'])->optionsWithRejectedAsStored()->captureMode,
        );
    }

    /**
     * @test
     */
    public function it_replaces_the_deprecated_gateway_options_as_saving_the_payment_method_again_does(): void
    {
        $configuration = GatewayConfigurations::of([
            'apikey' => 'the-1.x-api-key',
            'privatekey' => 'the-1.x-private-key',
            'agreement' => '5',
            'auto_capture' => 1,
            'use_authorize' => true,
            'language' => 'da',
        ]);

        self::assertSame([
            'use_authorize' => false,
            'language' => 'da',
            'api_key' => 'the-1.x-api-key',
            'private_key' => 'the-1.x-private-key',
            'agreement_id' => '5',
        ], $configuration->withoutDeprecatedOptions());
    }

    /**
     * @test
     */
    public function it_replaces_the_deprecated_gateway_options_of_a_configuration_the_gateway_rejects_too(): void
    {
        $configuration = GatewayConfigurations::of(['apikey' => 'the-1.x-api-key', 'branding_id' => 'Our brand', 'auto_capture' => 'maybe', 'use_authorize' => true]);

        self::assertSame([
            'branding_id' => 'Our brand',
            'use_authorize' => true,
            'api_key' => 'the-1.x-api-key',
        ], $configuration->withoutDeprecatedOptions());
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function sortedByOption(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * @test
     */
    public function an_encrypted_gateway_configuration_is_unusable_because_encryption_is_not_supported(): void
    {
        $configuration = GatewayConfigurations::encrypted(gatewayName: 'quickpay_dk');

        try {
            $configuration->options();
            self::fail('The configuration was read');
        } catch (UnusableGatewayConfiguration $e) {
            self::assertSame('quickpay_dk', $e->gatewayName);
            self::assertTrue($e->isUnreadable());
            self::assertSame([], $e->getViolations());
            self::assertStringContainsString('"quickpay_dk"', $e->getMessage());
            self::assertStringContainsString('Encrypting gateway configurations is not supported', $e->getMessage());
        }
    }

    /**
     * @test
     *
     * @dataProvider captureModes
     *
     * @param array<string, mixed> $stored
     */
    public function its_capture_mode_is_the_one_checkout_uses(array $stored, CaptureMode $expected): void
    {
        self::assertSame($expected, GatewayConfigurations::of(GatewayConfigurations::USABLE + $stored)->captureMode());
    }

    /**
     * Sylius' checkout authorizes when `use_authorize` is set and true under PHP's bool cast, and captures
     * otherwise. An authorization is captured on completion, unless payum-quickpay's `auto_capture` makes
     * Quickpay capture it right away.
     *
     * @return iterable<string, array{array<string, mixed>, CaptureMode}>
     */
    public static function captureModes(): iterable
    {
        $autoCaptures = ['absent' => [], '1' => ['auto_capture' => 1], '0' => ['auto_capture' => 0], "'yes'" => ['auto_capture' => 'yes']];

        $authorizing = [
            'use_authorize true' => ['use_authorize' => true],
            // (bool) 'false' is true, so Sylius' checkout authorizes
            "use_authorize 'false'" => ['use_authorize' => 'false'],
        ];
        foreach ($authorizing as $useAuthorize => $stored) {
            yield "$useAuthorize, auto_capture absent" => [$stored, CaptureMode::OnCompletion];
            yield "$useAuthorize, auto_capture 1" => [$stored + $autoCaptures['1'], CaptureMode::Immediately];
            yield "$useAuthorize, auto_capture 0" => [$stored + $autoCaptures['0'], CaptureMode::OnCompletion];
            yield "$useAuthorize, auto_capture 'yes'" => [$stored + $autoCaptures["'yes'"], CaptureMode::Immediately];
        }

        $capturing = ['use_authorize false' => ['use_authorize' => false], 'use_authorize absent' => []];
        foreach ($capturing as $useAuthorize => $stored) {
            foreach ($autoCaptures as $autoCapture => $storedAutoCapture) {
                yield "$useAuthorize, auto_capture $autoCapture" => [$stored + $storedAutoCapture, CaptureMode::Immediately];
            }
        }
    }

    /**
     * @test
     */
    public function its_capture_mode_needs_the_gateway_options_only_for_a_stored_auto_capture(): void
    {
        self::assertSame(CaptureMode::OnCompletion, GatewayConfigurations::of(['use_authorize' => true])->captureMode());
        self::assertSame(CaptureMode::Immediately, GatewayConfigurations::of(['use_authorize' => false, 'auto_capture' => 'maybe'])->captureMode());
        // Sylius' checkout reads an encrypted configuration before Payum decrypts it, finds no use_authorize and captures
        self::assertSame(CaptureMode::Immediately, GatewayConfigurations::encrypted()->captureMode());

        $this->expectException(UnusableGatewayConfiguration::class);
        GatewayConfigurations::of(['use_authorize' => true, 'auto_capture' => 1])->captureMode();
    }

    /**
     * @test
     */
    public function it_knows_whether_the_capture_mode_is_set(): void
    {
        self::assertTrue(GatewayConfigurations::of(['use_authorize' => true])->isCaptureModeSet());
        self::assertTrue(GatewayConfigurations::of(['use_authorize' => false])->isCaptureModeSet());
        self::assertFalse(GatewayConfigurations::of(['use_authorize' => null])->isCaptureModeSet());
        self::assertFalse(GatewayConfigurations::of(GatewayConfigurations::USABLE)->isCaptureModeSet());
        self::assertFalse(GatewayConfigurations::encrypted()->isCaptureModeSet());
    }

    /**
     * @test
     *
     * @dataProvider deprecatedOptions
     *
     * @param array<string, mixed> $stored
     */
    public function it_knows_whether_a_deprecated_gateway_option_is_stored(array $stored, bool $expected): void
    {
        self::assertSame($expected, GatewayConfigurations::of($stored)->usesDeprecatedOptions());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function deprecatedOptions(): iterable
    {
        yield 'the current names' => [GatewayConfigurations::USABLE + ['agreement_id' => 5], false];
        yield 'the 1.x apikey' => [['apikey' => 'the-api-key', 'private_key' => 'the-private-key'], true];
        yield 'the 1.x privatekey' => [['api_key' => 'the-api-key', 'privatekey' => 'the-private-key'], true];
        yield 'the 1.x agreement' => [GatewayConfigurations::USABLE + ['agreement' => 5], true];
        yield 'a 1.x name that is null' => [GatewayConfigurations::USABLE + ['agreement' => null], false];
        yield 'auto_capture on' => [GatewayConfigurations::USABLE + ['auto_capture' => 'yes'], true];
        // off is payum-quickpay's default, in every configuration seeded from its default options
        yield 'auto_capture off' => [GatewayConfigurations::USABLE + ['auto_capture' => 0], false];
    }

    /**
     * @test
     */
    public function an_encrypted_gateway_configuration_shows_no_deprecated_gateway_option(): void
    {
        self::assertFalse(GatewayConfigurations::encrypted()->usesDeprecatedOptions());
    }

    /**
     * @test
     */
    public function a_stored_config_array_needs_no_gateway_name(): void
    {
        self::assertSame('', GatewayConfiguration::fromConfig(GatewayConfigurations::USABLE)->gatewayName);
    }

    /**
     * As a Payum gateway reads its own config: Payum passes no gateway name with it
     *
     * @test
     */
    public function an_invalid_stored_config_array_is_unusable_without_naming_a_gateway(): void
    {
        $this->expectException(UnusableGatewayConfiguration::class);
        $this->expectExceptionMessage('The gateway configuration of a Quickpay gateway is invalid: The "private_key" option is required');

        GatewayConfiguration::fromConfig(['api_key' => 'the-api-key'])->options();
    }
}
