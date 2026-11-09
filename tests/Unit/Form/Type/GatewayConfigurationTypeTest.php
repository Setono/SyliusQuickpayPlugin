<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Form\Type;

use Setono\SyliusQuickpayPlugin\Form\Type\GatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class GatewayConfigurationTypeTest extends TypeTestCase
{
    /**
     * @return list<FormExtensionInterface>
     */
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
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
     * Saving the payment method validates the gateway configuration by the gateway's own rules (see
     * tests/Functional/Form/PaymentMethodFormTest), so the order prefix's length is the only constraint of the form's own
     *
     * @test
     */
    public function it_is_invalid_only_when_the_order_prefix_is_too_long(): void
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
        self::assertCount(1, $form->getErrors(true));
        self::assertCount(1, $form->get('order_prefix')->getErrors());
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
