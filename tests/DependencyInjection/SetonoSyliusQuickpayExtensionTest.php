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
            ['form_themes' => ['@SetonoSyliusQuickpayPlugin/Form/theme.html.twig']],
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
                                'template' => '@SetonoSyliusQuickpayPlugin/Admin/Order/Show/Payment/_quickpay.html.twig',
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
