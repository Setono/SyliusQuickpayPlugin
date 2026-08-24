<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Setono\SyliusQuickpayPlugin\DependencyInjection\SetonoSyliusQuickpayExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class SetonoSyliusQuickpayExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [
            new SetonoSyliusQuickpayExtension(),
        ];
    }

    /**
     * @test
     */
    public function it_sets_the_operation_toggle_parameters(): void
    {
        $this->load();

        $this->assertContainerBuilderHasParameter('setono_sylius_quickpay.operations.capture', true);
        $this->assertContainerBuilderHasParameter('setono_sylius_quickpay.operations.refund', true);
        $this->assertContainerBuilderHasParameter('setono_sylius_quickpay.operations.cancel', true);
        $this->assertContainerBuilderHasParameter('setono_sylius_quickpay.fraud.block_capture', false);
    }

    /**
     * @test
     */
    public function it_sets_the_checkout_presentation_parameters(): void
    {
        $container = new ContainerBuilder();
        (new SetonoSyliusQuickpayExtension())->load([[
            'checkout' => [
                'payment_method_logos' => ['mobilepay' => 'build/mobilepay.svg', 'resurs' => null],
                'creditcard_brands' => ['visa', 'mastercard', 'dankort'],
            ],
        ]], $container);

        self::assertSame(['mobilepay' => 'build/mobilepay.svg', 'resurs' => null], $container->getParameter('setono_sylius_quickpay.checkout.payment_method_logos'));
        self::assertSame(['visa', 'mastercard', 'dankort'], $container->getParameter('setono_sylius_quickpay.checkout.creditcard_brands'));
    }

    /**
     * @test
     */
    public function it_registers_the_payment_processor_service(): void
    {
        $this->load();

        $this->assertContainerBuilderHasService(
            \Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor::class,
        );
    }

    /**
     * @test
     */
    public function it_prepends_the_state_machine_callback(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class() extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'winzou_state_machine';
            }
        });

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        $config = $container->getExtensionConfig('winzou_state_machine');

        self::assertCount(1, $config);
        self::assertSame([
            'sylius_payment' => [
                'callbacks' => [
                    'before' => [
                        'setono_quickpay_resolve_state' => [
                            'on' => ['complete', 'refund', 'cancel'],
                            'do' => ['@Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor', '__invoke'],
                            'args' => ['object', 'event.getTransition()'],
                        ],
                    ],
                ],
            ],
        ], $config[0]);
    }

    /**
     * @test
     */
    public function it_does_not_prepend_when_the_winzou_extension_is_not_registered(): void
    {
        $container = new ContainerBuilder();

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('winzou_state_machine'));
    }

    /**
     * @test
     */
    public function it_prepends_a_named_lock_resource(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class() extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'framework';
            }
        });

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        self::assertSame([
            [
                'lock' => [
                    'resources' => [
                        'setono_sylius_quickpay' => ['flock'],
                    ],
                ],
            ],
        ], $container->getExtensionConfig('framework'));
    }

    /**
     * @test
     */
    public function it_prepends_the_payment_link_email(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class() extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'sylius_mailer';
            }
        });

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        self::assertSame([
            [
                'emails' => [
                    'setono_sylius_quickpay_payment_link' => [
                        'subject' => 'setono_sylius_quickpay.email.payment_link.subject',
                        'template' => '@SetonoSyliusQuickpayPlugin/email/payment_link.html.twig',
                    ],
                ],
            ],
        ], $container->getExtensionConfig('sylius_mailer'));
    }

    /**
     * @test
     */
    public function it_prepends_the_form_theme(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class() extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'twig';
            }
        });

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        self::assertSame([
            ['form_themes' => ['@SetonoSyliusQuickpayPlugin/form/theme.html.twig']],
        ], $container->getExtensionConfig('twig'));
    }

    /**
     * @test
     */
    public function it_does_not_prepend_the_form_theme_when_the_twig_extension_is_not_registered(): void
    {
        $container = new ContainerBuilder();

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('twig'));
    }

    /**
     * @test
     */
    public function it_prepends_the_admin_order_show_block(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class() extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'sylius_ui';
            }
        });

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        self::assertSame([
            [
                'events' => [
                    'sylius.admin.order.show.payment_content' => [
                        'blocks' => [
                            'setono_sylius_quickpay_operations' => [
                                'template' => '@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_quickpay.html.twig',
                                'priority' => -10,
                            ],
                        ],
                    ],
                    'sylius.shop.checkout.select_payment.choice_item_content' => [
                        'blocks' => [
                            'setono_sylius_quickpay_payment_method_logos' => [
                                'template' => '@SetonoSyliusQuickpayPlugin/shop/checkout/select_payment/_payment_method_logos.html.twig',
                                'priority' => -10,
                            ],
                        ],
                    ],
                ],
            ],
        ], $container->getExtensionConfig('sylius_ui'));
    }

    /**
     * @test
     */
    public function it_does_not_prepend_the_admin_order_show_block_when_the_sylius_ui_extension_is_not_registered(): void
    {
        $container = new ContainerBuilder();

        (new SetonoSyliusQuickpayExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('sylius_ui'));
    }
}
