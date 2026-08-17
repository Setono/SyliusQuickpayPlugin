<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Form\Type;

use Nyholm\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\Form\Type\GatewayConfigurationType;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\QuickpayCredentialsValidator;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
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
        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);

        $rejectingClient = $this->prophesize(ClientInterface::class);
        $rejectingClient->ping()->willThrow(new UnauthorizedException(new Response(401)));

        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create(Argument::type('string'))->willReturn($client);
        $clientFactory->create('rejected-api-key')->willReturn($rejectingClient);

        $credentialsValidator = new QuickpayCredentialsValidator($clientFactory->reveal());

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
    public function it_defaults_a_new_configuration_to_the_authorize_flow(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, []);

        self::assertTrue($form->get('use_authorize')->getData());
        self::assertSame('1', $form->get('use_authorize')->getViewData());
    }

    /**
     * @test
     *
     * @dataProvider provideStoredAutoCaptureValues
     */
    public function it_migrates_the_deprecated_auto_capture_option_into_the_capture_mode(mixed $autoCapture, bool $expectedUseAuthorize): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [
            'auto_capture' => $autoCapture,
            'use_authorize' => true,
        ]);

        self::assertSame($expectedUseAuthorize, $form->get('use_authorize')->getData());

        $data = $form->getData();
        self::assertIsArray($data);
        self::assertArrayNotHasKey('auto_capture', $data);
        self::assertSame($expectedUseAuthorize, $data['use_authorize']);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function provideStoredAutoCaptureValues(): iterable
    {
        yield 'auto capture enabled as int' => [1, false];
        yield 'auto capture enabled as string' => ['1', false];
        yield 'auto capture enabled as bool' => [true, false];
        yield 'auto capture disabled as int' => [0, true];
        yield 'auto capture disabled as string' => ['0', true];
        yield 'auto capture disabled as bool' => [false, true];
    }

    /**
     * @test
     */
    public function it_keeps_a_stored_capture_mode(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [
            'use_authorize' => false,
        ]);

        self::assertFalse($form->get('use_authorize')->getData());
        self::assertSame('0', $form->get('use_authorize')->getViewData());
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
    public function it_migrates_options_stored_under_the_old_names(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [
            'apikey' => 'stored-api-key',
            'privatekey' => 'stored-private-key',
            'agreement' => '12345',
        ]);

        self::assertSame('stored-api-key', $form->get('api_key')->getData());
        self::assertSame('stored-private-key', $form->get('private_key')->getData());
        self::assertSame(12345, $form->get('agreement_id')->getData());

        $data = $form->getData();
        self::assertIsArray($data);
        self::assertArrayNotHasKey('apikey', $data);
        self::assertArrayNotHasKey('privatekey', $data);
        self::assertArrayNotHasKey('agreement', $data);
    }

    /**
     * @test
     */
    public function it_normalizes_a_non_numeric_stored_agreement_id(): void
    {
        $form = $this->factory->create(GatewayConfigurationType::class, [
            'agreement' => '',
        ]);

        self::assertNull($form->get('agreement_id')->getData());
    }
}
