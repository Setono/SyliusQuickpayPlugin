<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Form\Type;

use Setono\SyliusQuickpayPlugin\Form\Type\QuickpayGatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class QuickpayGatewayConfigurationTypeTest extends TypeTestCase
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
        $form = $this->factory->create(QuickpayGatewayConfigurationType::class);

        $form->submit([
            'api_key' => 'api-key',
            'private_key' => 'private-key',
            'agreement' => '67890',
            'order_prefix' => 'qp_',
            'payment_methods' => 'creditcard, mobilepay',
            'auto_capture' => '0',
            'synchronized' => '1',
            'branding_id' => '42',
        ]);

        self::assertTrue($form->isSynchronized());

        $data = $form->getData();
        self::assertIsArray($data);
        self::assertSame('api-key', $data['api_key']);
        self::assertSame('private-key', $data['private_key']);
        self::assertSame('67890', $data['agreement']);
        self::assertSame('qp_', $data['order_prefix']);
        self::assertSame('creditcard, mobilepay', $data['payment_methods']);
        self::assertSame(0, $data['auto_capture']);
        self::assertTrue($data['synchronized']);
        self::assertSame('42', $data['branding_id']);
    }

    /**
     * @test
     */
    public function it_is_invalid_when_required_fields_are_blank(): void
    {
        $form = $this->factory->create(QuickpayGatewayConfigurationType::class, null, [
            'validation_groups' => ['sylius'],
        ]);

        $form->submit([
            'api_key' => '',
            'private_key' => '',
            'agreement' => '',
            'order_prefix' => 'longer_than_eleven_characters',
        ]);

        self::assertFalse($form->isValid());
        foreach (['api_key', 'private_key', 'order_prefix'] as $field) {
            self::assertGreaterThan(0, \count($form->get($field)->getErrors()), sprintf('Expected a validation error on the "%s" field', $field));
        }

        // The agreement id is optional since Quickpay only requires the api and private keys
        self::assertCount(0, $form->get('agreement')->getErrors());
    }

    /**
     * @test
     */
    public function it_migrates_credentials_stored_under_the_old_option_names(): void
    {
        $form = $this->factory->create(QuickpayGatewayConfigurationType::class, [
            'apikey' => 'stored-api-key',
            'privatekey' => 'stored-private-key',
        ]);

        self::assertSame('stored-api-key', $form->get('api_key')->getData());
        self::assertSame('stored-private-key', $form->get('private_key')->getData());

        $data = $form->getData();
        self::assertIsArray($data);
        self::assertArrayNotHasKey('apikey', $data);
        self::assertArrayNotHasKey('privatekey', $data);
    }
}
