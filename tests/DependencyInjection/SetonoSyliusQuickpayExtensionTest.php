<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Setono\SyliusQuickpayPlugin\DependencyInjection\SetonoSyliusQuickpayExtension;

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

        $this->assertContainerBuilderHasParameter('setono_sylius_quickpay.disable_capture', false);
        $this->assertContainerBuilderHasParameter('setono_sylius_quickpay.disable_refund', false);
        $this->assertContainerBuilderHasParameter('setono_sylius_quickpay.disable_cancel', false);
    }

    /**
     * @test
     */
    public function it_registers_the_payment_processor_service(): void
    {
        $this->load();

        $this->assertContainerBuilderHasService(
            'setono_sylius_quickpay.state_machine.payment_processor',
        );
    }
}
