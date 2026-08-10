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
}
