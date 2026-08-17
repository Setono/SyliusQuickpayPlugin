<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Form\Type;

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
            // Gateway configurations stored before the options were renamed carry the old keys;
            // migrate them so the form shows the stored values and saves the new keys. The agreement
            // id may be stored as a string (or '' from an unset fixture env var), which the integer
            // field cannot display, so it is normalized while migrating
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $data = $event->getData();
                if (!is_array($data)) {
                    return;
                }

                $data['api_key'] ??= $data['apikey'] ?? null;
                $data['private_key'] ??= $data['privatekey'] ?? null;

                $agreementId = $data['agreement_id'] ?? $data['agreement'] ?? null;
                $data['agreement_id'] = is_numeric($agreementId) ? (int) $agreementId : null;

                // Configurations saved before 2.0 carry the deprecated `auto_capture` option next to a
                // hidden `use_authorize: true`. An enabled auto capture is what "capture immediately"
                // now means, so it becomes `use_authorize: false`; a fresh configuration defaults to
                // authorize — hold the money, capture on completion
                if (array_key_exists('auto_capture', $data)) {
                    $data['use_authorize'] = !self::toBool($data['auto_capture']);
                }
                $data['use_authorize'] = self::toBool($data['use_authorize'] ?? true);

                unset($data['apikey'], $data['privatekey'], $data['agreement'], $data['auto_capture']);

                $event->setData($data);
            })
        ;
    }

    /**
     * Stored booleans come in every shape Sylius has persisted over the years: bool, 0/1, '0'/'1'
     */
    private static function toBool(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
    }
}
