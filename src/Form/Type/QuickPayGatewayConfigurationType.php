<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @author jdk
 */
class QuickPayGatewayConfigurationType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apikey', TextType::class, [
                'label' => 'setono.form.gateway_configuration.quickpay.apikey',
                'constraints' => [
                    new NotBlank([
                        'message' => 'setono.form.gateway_configuration.error.apikey.not_blank',
                        'groups' => 'sylius',
                    ]),
                ],
            ])
            ->add('privatekey', TextType::class, [
                'label' => 'setono.form.gateway_configuration.quickpay.privatekey',
                'constraints' => [
                    new NotBlank([
                        'message' => 'setono.form.gateway_configuration.error.privatekey.not_blank',
                        'groups' => 'sylius',
                    ]),
                ],
            ])
            ->add('merchant', TextType::class, [
                'label' => 'setono.form.gateway_configuration.quickpay.merchant',
                'constraints' => [
                    new NotBlank([
                        'message' => 'setono.form.gateway_configuration.error.merchant.not_blank',
                        'groups' => 'sylius',
                    ]),
                ],
            ])
            ->add('agreement', TextType::class, [
                'label' => 'setono.form.gateway_configuration.quickpay.agreement',
                'constraints' => [
                    new NotBlank([
                        'message' => 'setono.form.gateway_configuration.error.agreement.not_blank',
                        'groups' => 'sylius',
                    ]),
                ],
            ])
            ->add('order_prefix', TextType::class, [
                'label' => 'setono.form.gateway_configuration.quickpay.order_prefix',
            ])
            ->add('payment_methods', TextType::class, [
                'label' => 'setono.form.gateway_configuration.quickpay.payment_methods',
            ])
            ->add('auto_capture', ChoiceType::class, [
                'label' => 'setono.form.gateway_configuration.quickpay.auto_capture',
                'choices' => [
                    'setono.form.gateway_configuration.quickpay.auto_capture_option.no' => 0,
                    'setono.form.gateway_configuration.quickpay.auto_capture_option.yes' => 1,
                ],
            ])
            ->add('use_authorize', HiddenType::class, [
                'data' => 1,
            ])
        ;
    }
}
