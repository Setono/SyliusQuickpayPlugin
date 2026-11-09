<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Form\Type;

use Setono\SyliusQuickpayPlugin\GatewayConfiguration\CaptureMode;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Saving the payment method validates the whole gateway configuration by the gateway's own rules, in the admin and the
 * Admin API alike, and puts each error on its field
 * ({@see \Setono\SyliusQuickpayPlugin\Validator\Constraints\UsableGatewayConfiguration}), so the fields carry no
 * constraints of their own but the order prefix's length
 *
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
            ])
            ->add('private_key', TextType::class, [
                'label' => self::TRANSLATION_PREFIX . 'private_key',
                'help' => self::TRANSLATION_PREFIX . 'private_key_help',
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
                    self::TRANSLATION_PREFIX . 'capture_mode_option.on_completion' => CaptureMode::OnCompletion->useAuthorize(),
                    self::TRANSLATION_PREFIX . 'capture_mode_option.immediately' => CaptureMode::Immediately->useAuthorize(),
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
                    $event->setData(self::formDataOf($event->getForm(), $config));
                }
            })
        ;
    }

    /**
     * The form's data for a stored gateway configuration, as the gateway configuration module reads it (ADR 0002): the
     * form only puts each gateway option's value on the field of the same name. Saving it stores the current option
     * names, with the deprecated `auto_capture` folded into the capture mode, so re-saving an unchanged form never
     * changes how a working gateway behaves. Options without a field, such as `language`, are kept as stored.
     *
     * A configuration the gateway rejects still opens, so it can be fixed here, and fixing one option keeps the
     * others: each option the gateway accepts is shown as the gateway reads it, and each rejected one as stored where
     * its field holds it.
     *
     * @param FormInterface<mixed> $form
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function formDataOf(FormInterface $form, array $config): array
    {
        // A new payment method: the defaults, holding the money until the payment completes
        if ([] === $config) {
            return ['use_authorize' => CaptureMode::OnCompletion->useAuthorize()];
        }

        $configuration = GatewayConfiguration::fromConfig($config);
        $options = $configuration->optionsWithRejectedAsStored();
        $data = $configuration->withoutDeprecatedOptions();

        foreach ($form as $option => $field) {
            if (!array_key_exists($option, $options->values)) {
                continue;
            }

            $value = $options->values[$option];
            $data[$option] = !$options->isRejected($option) || self::holds($field, $value) ? $value : null;
        }

        return $data;
    }

    /**
     * Whether the field shows and saves the value unchanged, so a rejected value can be shown as stored. Any other
     * value the field would show or save changed, a list as text or a string as a number, so it is shown empty.
     *
     * @param FormInterface<mixed> $field
     */
    private static function holds(FormInterface $field, mixed $value): bool
    {
        $type = $field->getConfig()->getType()->getInnerType();

        return match (true) {
            $type instanceof CheckboxType => is_bool($value),
            $type instanceof IntegerType => is_int($value),
            $type instanceof TextType => is_string($value),
            default => false,
        };
    }
}
