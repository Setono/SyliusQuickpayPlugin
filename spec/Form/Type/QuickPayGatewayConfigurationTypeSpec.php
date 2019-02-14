<?php

declare(strict_types=1);

namespace spec\Setono\SyliusQuickpayPlugin\Form\Type;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Setono\SyliusQuickpayPlugin\Form\Type\QuickPayGatewayConfigurationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class QuickPayGatewayConfigurationTypeSpec extends ObjectBehavior
{
    public function it_is_initializable(): void
    {
        $this->shouldHaveType(QuickPayGatewayConfigurationType::class);
    }

    public function it_is_an_abstract_type_form(): void
    {
        $this->shouldHaveType(AbstractType::class);
    }

    public function it_takes_arguments(FormBuilderInterface $builder): void
    {
        $builder->add(Argument::type('string'), Argument::type('string'), Argument::any())->willReturn($builder);
        $this->buildForm($builder, []);
    }
}
