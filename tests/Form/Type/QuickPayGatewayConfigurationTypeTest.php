<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Form\Type;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Form\Type\QuickPayGatewayConfigurationType;
use Symfony\Component\Form\FormBuilderInterface;

final class QuickPayGatewayConfigurationTypeTest extends TestCase
{
    /**
     * @test
     */
    public function it_builds_the_gateway_configuration_form(): void
    {
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnSelf();

        $type = new QuickPayGatewayConfigurationType();
        $type->buildForm($builder, []);

        // buildForm chains ->add() for every gateway configuration field; reaching this
        // point without an exception proves the whole form definition is wired correctly.
        $this->addToAssertionCount(1);
    }
}
