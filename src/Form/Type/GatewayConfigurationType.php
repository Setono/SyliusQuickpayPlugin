<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Form\Type;

use Setono\SyliusQuickpayPlugin\Validator\Constraints\QuickpayCredentials;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
            ->add('auto_capture', CheckboxType::class, [
                'label' => self::TRANSLATION_PREFIX . 'auto_capture',
                'help' => self::TRANSLATION_PREFIX . 'auto_capture_help',
                'required' => false,
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
            ->add('use_authorize', HiddenType::class, [
                'data' => true,
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

                unset($data['apikey'], $data['privatekey'], $data['agreement']);

                $event->setData($data);
            })
        ;

        // Stored configurations carry auto_capture as 0/1; the checkbox needs a bool and the
        // stored shape stays an int either way
        $builder->get('auto_capture')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $value): bool => (bool) $value,
            static fn (?bool $value): int => true === $value ? 1 : 0,
        ));
    }
}
