<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Form\Type;

use Setono\Payum\Quickpay\GatewayOptions;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\CaptureMode;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\QuickpayCredentials;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class GatewayConfigurationType extends AbstractType
{
    private const TRANSLATION_PREFIX = 'setono_sylius_quickpay.form.gateway_configuration.quickpay.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Credentials
            ->add('api_key', TextType::class, [
                'label' => self::TRANSLATION_PREFIX . 'api_key',
                'help' => self::TRANSLATION_PREFIX . 'api_key_help',
                'constraints' => [
                    new NotBlank(['groups' => 'sylius']),
                    new QuickpayCredentials(['groups' => 'sylius']),
                ],
            ])
            ->add('private_key', TextType::class, [
                'label' => self::TRANSLATION_PREFIX . 'private_key',
                'help' => self::TRANSLATION_PREFIX . 'private_key_help',
                'constraints' => [
                    new NotBlank(['groups' => 'sylius']),
                ],
            ])
            ->add('agreement_id', IntegerType::class, [
                'label' => self::TRANSLATION_PREFIX . 'agreement_id',
                'help' => self::TRANSLATION_PREFIX . 'agreement_id_help',
                'required' => false,
            ])
            // Payment behavior
            ->add('payment_methods', TextType::class, [
                'label' => self::TRANSLATION_PREFIX . 'payment_methods',
                'help' => self::TRANSLATION_PREFIX . 'payment_methods_help',
                'help_html' => true,
                'required' => false,
                // Sylius' admin form theme ignores help_html, so the docs link is rendered
                // through the plugin's own form theme, scoped to this block prefix
                'block_prefix' => 'setono_sylius_quickpay__gateway_configuration_payment_methods',
                'attr' => [
                    'placeholder' => 'creditcard, mobilepay',
                ],
            ])
            // Sylius core reads `use_authorize` to pick the checkout flow: true executes Authorize (the
            // payment window only holds the money; the plugin captures it on the payment's complete
            // transition), false executes Capture (Quickpay captures the moment the card is authorized).
            // Since payum-quickpay 2.0 that is how "capture immediately" is expressed — the deprecated
            // `auto_capture` gateway option is no longer written
            ->add('use_authorize', ChoiceType::class, [
                'label' => self::TRANSLATION_PREFIX . 'capture_mode',
                'help' => self::TRANSLATION_PREFIX . 'capture_mode_help',
                'choices' => [
                    self::TRANSLATION_PREFIX . 'capture_mode_option.on_completion' => true,
                    self::TRANSLATION_PREFIX . 'capture_mode_option.immediately' => false,
                ],
            ])
            ->add('synchronized', CheckboxType::class, [
                'label' => self::TRANSLATION_PREFIX . 'synchronized',
                'help' => self::TRANSLATION_PREFIX . 'synchronized_help',
                'required' => false,
            ])
            ->add('order_prefix', TextType::class, [
                'label' => self::TRANSLATION_PREFIX . 'order_prefix',
                'help' => self::TRANSLATION_PREFIX . 'order_prefix_help',
                'required' => false,
                'attr' => [
                    'placeholder' => 'qp_',
                ],
                'constraints' => [
                    new Length([
                        'max' => 11,
                        'groups' => 'sylius',
                    ]),
                ],
            ])
            // Presentation
            ->add('branding_id', TextType::class, [
                'label' => self::TRANSLATION_PREFIX . 'branding_id',
                'help' => self::TRANSLATION_PREFIX . 'branding_id_help',
                'required' => false,
            ])
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $config = $event->getData();
                if (is_array($config)) {
                    $event->setData(self::show($config));
                }
            })
        ;
    }

    /**
     * What the form shows of a stored gateway configuration: what checkout uses, under the current option
     * names. Submitting it saves those names without the deprecated ones, and with the deprecated
     * `auto_capture` folded into the capture mode, so re-saving an unchanged form never changes how a
     * working gateway behaves. The form has no rules of its own: payum-quickpay decides what each gateway
     * option means, and the gateway configuration module what the capture mode is (ADR 0002). Options
     * without a field, such as `language`, are kept as stored.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function show(array $config): array
    {
        // A new payment method: the defaults, holding the money until the payment completes
        if ([] === $config) {
            return ['use_authorize' => true];
        }

        $configuration = GatewayConfiguration::fromConfig($config);

        $shown = GatewayOptions::withCurrentNames($config);
        unset($shown['auto_capture']);

        try {
            $options = $configuration->options();
        } catch (UnusableGatewayConfiguration) {
            return self::showAsStored($shown);
        }

        return array_replace($shown, [
            'api_key' => $options->apiKey,
            'private_key' => $options->privateKey,
            'agreement_id' => $options->agreementId,
            'payment_methods' => $options->paymentMethods,
            'use_authorize' => CaptureMode::OnCompletion === $configuration->captureMode(),
            'synchronized' => $options->synchronized,
            'order_prefix' => $options->orderPrefix,
            'branding_id' => $options->brandingId,
        ]);
    }

    /**
     * A configuration the gateway rejects still opens, so it can be fixed here: each value is shown as
     * stored where its field shows it exactly (text, or an integer, in a text field; an integer in the
     * agreement id field; a boolean in the checkbox) and empty where it can't, and the capture mode is
     * Sylius' rule alone, as checkout reads it
     *
     * @param array<array-key, mixed> $config under the current names, without `auto_capture`
     *
     * @return array<array-key, mixed>
     */
    private static function showAsStored(array $config): array
    {
        foreach (['api_key', 'private_key', 'payment_methods', 'order_prefix', 'branding_id'] as $textOption) {
            $value = $config[$textOption] ?? null;
            if (!is_string($value) && !is_int($value)) {
                $config[$textOption] = null;
            }
        }

        if (!is_int($config['agreement_id'] ?? null)) {
            $config['agreement_id'] = null;
        }

        if (!is_bool($config['synchronized'] ?? null)) {
            $config['synchronized'] = null;
        }

        // Without `auto_capture`, the module's capture mode reads `use_authorize` alone and never the options
        $config['use_authorize'] = CaptureMode::OnCompletion === GatewayConfiguration::fromConfig($config)->captureMode();

        return $config;
    }
}
