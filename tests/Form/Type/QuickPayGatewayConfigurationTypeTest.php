<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Form\Type;

use Setono\SyliusQuickpayPlugin\Form\Type\QuickPayGatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class QuickPayGatewayConfigurationTypeTest extends TypeTestCase
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
        $form = $this->factory->create(QuickPayGatewayConfigurationType::class);

        $form->submit([
            'apikey' => 'api-key',
            'privatekey' => 'private-key',
            'merchant' => '12345',
            'agreement' => '67890',
            'order_prefix' => 'qp_',
            'payment_methods' => 'creditcard, klarna-payments',
            'auto_capture' => '0',
        ]);

        self::assertTrue($form->isSynchronized());

        $data = $form->getData();
        self::assertIsArray($data);
        self::assertSame('api-key', $data['apikey']);
        self::assertSame('private-key', $data['privatekey']);
        self::assertSame('12345', $data['merchant']);
        self::assertSame('67890', $data['agreement']);
        self::assertSame('qp_', $data['order_prefix']);
        self::assertSame('creditcard, klarna-payments', $data['payment_methods']);
        self::assertSame(0, $data['auto_capture']);
    }

    /**
     * @test
     */
    public function it_is_invalid_when_required_fields_are_blank(): void
    {
        $form = $this->factory->create(QuickPayGatewayConfigurationType::class, null, [
            'validation_groups' => ['sylius'],
        ]);

        $form->submit([
            'apikey' => '',
            'privatekey' => '',
            'merchant' => '',
            'agreement' => '',
            'order_prefix' => 'longer_than_eleven_characters',
        ]);

        self::assertFalse($form->isValid());
        foreach (['apikey', 'privatekey', 'merchant', 'agreement', 'order_prefix'] as $field) {
            self::assertGreaterThan(0, \count($form->get($field)->getErrors()), sprintf('Expected a validation error on the "%s" field', $field));
        }
    }
}
