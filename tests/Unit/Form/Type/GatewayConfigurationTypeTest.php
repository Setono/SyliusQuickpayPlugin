<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Form\Type;

use Nyholm\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\Form\Type\GatewayConfigurationType;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerification;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifierInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\QuickpayCredentialsValidator;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Validation;

final class GatewayConfigurationTypeTest extends TypeTestCase
{
    use ProphecyTrait;

    /**
     * Quickpay accepts every api key except 'rejected-api-key'
     *
     * @return list<FormExtensionInterface>
     */
    protected function getExtensions(): array
    {
        $apiKeyVerifier = $this->prophesize(ApiKeyVerifierInterface::class);
        $apiKeyVerifier->verify(Argument::type('string'))->willReturn(ApiKeyVerification::ViaPing);
        $apiKeyVerifier->verify('rejected-api-key')->willThrow(new UnauthorizedException(new Response(401)));

        $credentialsValidator = new QuickpayCredentialsValidator($apiKeyVerifier->reveal());

        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new class($credentialsValidator) implements ConstraintValidatorFactoryInterface {
                private readonly ConstraintValidatorFactory $fallback;

                public function __construct(private readonly QuickpayCredentialsValidator $credentialsValidator)
                {
                    $this->fallback = new ConstraintValidatorFactory();
                }

                public function getInstance(Constraint $constraint): ConstraintValidatorInterface
                {
                    if (QuickpayCredentialsValidator::class === $constraint->validatedBy()) {
                        return $this->credentialsValidator;
                    }

                    return $this->fallback->getInstance($constraint);
                }
            })
            ->getValidator();

        return [
            new ValidatorExtension($validator),
        ];
    }

    /**
     * @test
     */
    public function it_is_invalid_when_quickpay_rejects_the_api_key(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, null, [
            'validation_groups' => ['sylius'],
        ]);

        $form->submit([
            'api_key' => 'rejected-api-key',
            'private_key' => 'private-key',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, \count($form->get('api_key')->getErrors()));
    }

    /**
     * @test
     */
    public function it_submits_gateway_configuration_data(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class);

        $form->submit([
            'api_key' => 'api-key',
            'private_key' => 'private-key',
            'agreement_id' => '67890',
            'order_prefix' => 'qp_',
            'payment_methods' => 'creditcard, mobilepay',
            'use_authorize' => '0',
            'synchronized' => '1',
            'branding_id' => '42',
        ]);

        self::assertTrue($form->isSynchronized());

        $data = $form->getData();
        self::assertIsArray($data);
        self::assertSame('api-key', $data['api_key']);
        self::assertSame('private-key', $data['private_key']);
        self::assertSame(67890, $data['agreement_id']);
        self::assertSame('qp_', $data['order_prefix']);
        self::assertSame('creditcard, mobilepay', $data['payment_methods']);
        self::assertFalse($data['use_authorize']);
        self::assertTrue($data['synchronized']);
        self::assertSame('42', $data['branding_id']);
    }

    /**
     * @test
     */
    public function it_shows_the_defaults_for_a_new_payment_method(): void
    {
        // Sylius' gateway config of a new payment method stores an empty configuration
        $form = $this->factory->create(GatewayConfigurationType::class, []);

        self::assertSame(self::ON_COMPLETION, self::shownCaptureMode($form));
        self::assertSame([
            'api_key' => '',
            'private_key' => '',
            'agreement_id' => '',
            'payment_methods' => '',
            'use_authorize' => '1',
            'synchronized' => null,
            'order_prefix' => '',
            'branding_id' => '',
        ], self::shownValues($form));
    }

    /**
     * @test
     *
     * @dataProvider provideStoredCaptureModes
     *
     * @param array<string, mixed> $stored
     */
    public function it_shows_the_capture_mode_checkout_uses(array $stored, string $expectedCaptureMode): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [...GatewayConfigurations::USABLE, ...$stored]);

        self::assertSame($expectedCaptureMode, self::shownCaptureMode($form));
    }

    /**
     * Sylius' checkout authorizes when `use_authorize` is set and true under PHP's bool cast, and executes
     * `Capture` otherwise; an authorization is captured on completion unless the deprecated `auto_capture`
     * (read like the gateway reads it) makes Quickpay capture it right away
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideStoredCaptureModes(): iterable
    {
        yield 'use_authorize true' => [['use_authorize' => true], self::ON_COMPLETION];
        yield 'use_authorize false' => [['use_authorize' => false], self::IMMEDIATELY];
        yield "use_authorize 'false', which checkout reads as true" => [['use_authorize' => 'false'], self::ON_COMPLETION];
        yield 'no use_authorize' => [[], self::IMMEDIATELY];
        yield "auto_capture 'yes' with use_authorize true" => [['use_authorize' => true, 'auto_capture' => 'yes'], self::IMMEDIATELY];
        yield "auto_capture 'yes' with use_authorize 'false'" => [['use_authorize' => 'false', 'auto_capture' => 'yes'], self::IMMEDIATELY];
        yield "auto_capture 'yes' without use_authorize" => [['auto_capture' => 'yes'], self::IMMEDIATELY];
        yield 'auto_capture 1 with use_authorize true' => [['use_authorize' => true, 'auto_capture' => 1], self::IMMEDIATELY];
        yield "auto_capture '1' with use_authorize true" => [['use_authorize' => true, 'auto_capture' => '1'], self::IMMEDIATELY];
        yield 'auto_capture true with use_authorize true' => [['use_authorize' => true, 'auto_capture' => true], self::IMMEDIATELY];
        yield 'auto_capture 0 with use_authorize true' => [['use_authorize' => true, 'auto_capture' => 0], self::ON_COMPLETION];
        yield "auto_capture 'no' with use_authorize true" => [['use_authorize' => true, 'auto_capture' => 'no'], self::ON_COMPLETION];
        yield 'auto_capture false with use_authorize true' => [['use_authorize' => true, 'auto_capture' => false], self::ON_COMPLETION];
        yield 'auto_capture 0 without use_authorize' => [['auto_capture' => 0], self::IMMEDIATELY];
    }

    /**
     * @test
     */
    public function it_is_invalid_when_required_fields_are_blank(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, null, [
            'validation_groups' => ['sylius'],
        ]);

        $form->submit([
            'api_key' => '',
            'private_key' => '',
            'agreement_id' => '',
            'order_prefix' => 'longer_than_eleven_characters',
            'payment_methods' => '',
        ]);

        self::assertFalse($form->isValid());
        foreach (['api_key', 'private_key', 'order_prefix'] as $field) {
            self::assertGreaterThan(0, \count($form->get($field)->getErrors()), sprintf('Expected a validation error on the "%s" field', $field));
        }

        // Quickpay only requires the api and private keys; an empty payment_methods
        // makes the payment window offer every method enabled on the agreement
        foreach (['agreement_id', 'payment_methods'] as $field) {
            self::assertCount(0, $form->get($field)->getErrors(), sprintf('Expected no validation error on the "%s" field', $field));
        }
    }

    /**
     * @test
     */
    public function it_shows_the_options_of_a_usable_configuration_as_the_gateway_reads_them(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [
            // the 1.x name applies, as the current name is null
            'api_key' => null,
            'apikey' => 'stored-api-key',
            'privatekey' => 'stored-private-key',
            'agreement' => '12345',
            'payment_methods' => [' creditcard', '', 'mobilepay '],
            'synchronized' => 'yes',
            'order_prefix' => 42,
            'branding_id' => ' 7 ',
            'use_authorize' => true,
        ]);

        self::assertSame([
            'api_key' => 'stored-api-key',
            'private_key' => 'stored-private-key',
            'agreement_id' => '12345',
            'payment_methods' => 'creditcard,mobilepay',
            'use_authorize' => '1',
            'synchronized' => '1',
            'order_prefix' => '42',
            'branding_id' => '7',
        ], self::shownValues($form));
    }

    /**
     * @test
     */
    public function it_opens_a_configuration_the_gateway_rejects_with_the_values_as_stored_where_a_field_can_hold_them(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [
            'apikey' => 'stored-api-key',
            'private_key' => '',
            'agreement_id' => '1e3',
            'payment_methods' => ['creditcard', 3],
            'synchronized' => 'maybe',
            'order_prefix' => 'qp_',
            'branding_id' => 'Brand name',
            'use_authorize' => true,
            'auto_capture' => 'abc',
        ]);

        self::assertSame([
            'api_key' => 'stored-api-key',
            'private_key' => '',
            'agreement_id' => '',
            'payment_methods' => '',
            'use_authorize' => '1',
            'synchronized' => null,
            'order_prefix' => 'qp_',
            'branding_id' => 'Brand name',
        ], self::shownValues($form));
    }

    /**
     * @test
     *
     * @dataProvider provideValuesTheFieldsHold
     */
    public function it_keeps_a_value_its_field_holds_when_a_configuration_the_gateway_rejects_is_fixed(string $option, mixed $stored, ?string $expectedShown, mixed $expectedSaved): void
    {
        // the branding id makes the gateway reject the configuration
        $form = $this->factory->create(GatewayConfigurationType::class, [
            ...GatewayConfigurations::USABLE,
            $option => $stored,
            'branding_id' => 'abc',
        ]);

        $shown = self::shownValues($form);
        self::assertSame($expectedShown, $shown[$option]);

        // the admin fixes the branding id and saves
        $form->submit(['branding_id' => '7'] + $shown);

        $saved = $form->getData();
        self::assertIsArray($saved);
        self::assertSame($expectedSaved, $saved[$option]);
    }

    /**
     * @return iterable<string, array{string, mixed, ?string, mixed}>
     */
    public static function provideValuesTheFieldsHold(): iterable
    {
        yield 'an agreement id stored as a string of digits' => ['agreement_id', '5', '5', 5];
        yield 'an agreement id stored as an int' => ['agreement_id', 5, '5', 5];
        yield 'synchronized stored as 1' => ['synchronized', 1, '1', true];
        yield "synchronized stored as '1'" => ['synchronized', '1', '1', true];
        yield "synchronized stored as '0'" => ['synchronized', '0', null, false];
    }

    /**
     * @test
     *
     * @dataProvider provideRejectedCaptureModes
     *
     * @param array<string, mixed> $stored
     */
    public function it_shows_the_capture_mode_of_a_configuration_the_gateway_rejects_by_sylius_rule(array $stored, string $expectedCaptureMode): void
    {
        // no private key: the gateway rejects the configuration
        $form = $this->factory->create(GatewayConfigurationType::class, ['api_key' => 'the-api-key', ...$stored]);

        self::assertSame($expectedCaptureMode, self::shownCaptureMode($form));
    }

    /**
     * Sylius' checkout authorizes when `use_authorize` is set and true under PHP's bool cast
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideRejectedCaptureModes(): iterable
    {
        yield 'use_authorize true' => [['use_authorize' => true], self::ON_COMPLETION];
        yield "use_authorize 'false'" => [['use_authorize' => 'false'], self::ON_COMPLETION];
        yield 'use_authorize false' => [['use_authorize' => false], self::IMMEDIATELY];
        yield 'no use_authorize' => [[], self::IMMEDIATELY];
        yield 'use_authorize true and auto_capture on' => [['use_authorize' => true, 'auto_capture' => 1], self::ON_COMPLETION];
    }

    /**
     * @test
     */
    public function it_saves_the_current_names_and_folds_auto_capture_into_the_capture_mode(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [
            'apikey' => 'stored-api-key',
            'privatekey' => 'stored-private-key',
            'agreement' => '12345',
            'auto_capture' => 1,
            'use_authorize' => true,
            'language' => 'da',
        ]);

        $form->submit(self::shownValues($form));

        self::assertTrue($form->isSynchronized());
        self::assertSame([
            'use_authorize' => false,
            'language' => 'da',
            'api_key' => 'stored-api-key',
            'private_key' => 'stored-private-key',
            'agreement_id' => 12345,
            'payment_methods' => null,
            'synchronized' => false,
            'order_prefix' => null,
            'branding_id' => null,
        ], $form->getData());
    }

    /**
     * @test
     *
     * @dataProvider provideUsableConfigurations
     *
     * @param array<string, mixed> $stored
     */
    public function it_changes_nothing_the_gateway_uses_when_an_unchanged_configuration_is_saved_again(array $stored): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, $stored);

        $form->submit(self::shownValues($form));

        $saved = $form->getData();
        self::assertIsArray($saved);
        self::assertSame(GatewayConfiguration::fromConfig($stored)->captureMode(), GatewayConfiguration::fromConfig($saved)->captureMode());
        self::assertSame(self::optionsBesidesAutoCapture($stored), self::optionsBesidesAutoCapture($saved));
    }

    /**
     * The normalized gateway options, as the gateway reads them. The deprecated `auto_capture` is left out:
     * saving folds it into the capture mode
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function optionsBesidesAutoCapture(array $config): array
    {
        $options = get_object_vars(GatewayConfiguration::fromConfig($config)->options());
        unset($options['autoCapture']);

        return $options;
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideUsableConfigurations(): iterable
    {
        yield 'the current names' => [[
            ...GatewayConfigurations::USABLE,
            'agreement_id' => 12345,
            'payment_methods' => 'creditcard, mobilepay',
            'use_authorize' => true,
            'synchronized' => true,
            'order_prefix' => 'qp_',
            'branding_id' => '7',
        ]];
        yield 'the 1.x names, with auto_capture on' => [[
            'apikey' => 'stored-api-key',
            'privatekey' => 'stored-private-key',
            'agreement' => '12345',
            'auto_capture' => 1,
            'use_authorize' => true,
        ]];
        yield 'a null current name next to a 1.x name' => [['api_key' => null, 'apikey' => 'stored-api-key', 'private_key' => 'stored-private-key']];
        yield "auto_capture 'yes' with use_authorize true" => [[...GatewayConfigurations::USABLE, 'use_authorize' => true, 'auto_capture' => 'yes']];
        yield "auto_capture 'yes' without use_authorize" => [[...GatewayConfigurations::USABLE, 'auto_capture' => 'yes']];
        yield "use_authorize 'false'" => [[...GatewayConfigurations::USABLE, 'use_authorize' => 'false']];
        yield 'no use_authorize' => [GatewayConfigurations::USABLE];
        yield 'list-shaped payment methods' => [[...GatewayConfigurations::USABLE, 'payment_methods' => [' creditcard', '', 'mobilepay ']]];
        yield 'other spellings of ids, booleans and the order prefix' => [[
            ...GatewayConfigurations::USABLE,
            'agreement_id' => ' 12345 ',
            'branding_id' => 7,
            'synchronized' => 'on',
            'order_prefix' => 42,
        ]];
        yield 'blank optional options' => [[
            ...GatewayConfigurations::USABLE,
            'agreement_id' => '',
            'branding_id' => '   ',
            'payment_methods' => [],
            'synchronized' => '',
        ]];
        yield 'an option without a field' => [[...GatewayConfigurations::USABLE, 'language' => 'da']];
    }

    /**
     * The capture mode field's choices, as the admin reads them
     */
    private const ON_COMPLETION = 'setono_sylius_quickpay.form.gateway_configuration.quickpay.capture_mode_option.on_completion';

    private const IMMEDIATELY = 'setono_sylius_quickpay.form.gateway_configuration.quickpay.capture_mode_option.immediately';

    /**
     * What each field shows: its view value ('1' for a checked checkbox and for the capture mode "on
     * completion", '0' for "immediately")
     *
     * @param FormInterface<array<string, mixed>> $form
     *
     * @return array<string, mixed>
     */
    private static function shownValues(FormInterface $form): array
    {
        $values = [];
        foreach ($form as $name => $field) {
            $values[$name] = $field->getViewData();
        }

        return $values;
    }

    /**
     * The label of the capture mode the form shows as chosen
     *
     * @param FormInterface<array<string, mixed>> $form
     */
    private static function shownCaptureMode(FormInterface $form): string
    {
        /** @var array{value: string, choices: list<ChoiceView>} $vars */
        $vars = $form->get('use_authorize')->createView()->vars;
        foreach ($vars['choices'] as $choice) {
            if ($choice->value === $vars['value']) {
                self::assertIsString($choice->label);

                return $choice->label;
            }
        }

        self::fail('The form shows no capture mode');
    }
}
