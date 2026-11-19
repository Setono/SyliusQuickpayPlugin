<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Validator\Constraints;

use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusQuickpayPlugin\OrderId\LatestOrderNumberProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\Verdict;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\UsableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\UsableGatewayConfigurationValidator;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Save validation is tested against the container in tests/Functional/Validator and tests/Functional/Form. This covers
 * what the container doesn't show: the validator's contract with Symfony's validator, and that its logger is optional
 * (the service is wired without one when the app has none)
 *
 * @extends ConstraintValidatorTestCase<UsableGatewayConfigurationValidator>
 */
final class UsableGatewayConfigurationValidatorTest extends ConstraintValidatorTestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<GatewayChecksInterface> */
    private ObjectProphecy $gatewayChecks;

    protected function setUp(): void
    {
        $this->gatewayChecks = $this->prophesize(GatewayChecksInterface::class);

        parent::setUp();
    }

    protected function createValidator(): UsableGatewayConfigurationValidator
    {
        $latestOrderNumberProvider = $this->prophesize(LatestOrderNumberProviderInterface::class);
        $latestOrderNumberProvider->getLatestOrderNumber()->willReturn('000000123');

        return new UsableGatewayConfigurationValidator($this->gatewayChecks->reveal(), $latestOrderNumberProvider->reveal());
    }

    /**
     * @test
     */
    public function it_refuses_a_constraint_it_does_not_validate(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(GatewayConfigurations::entity(), new NotNull());
    }

    /**
     * Symfony's validator turns this into a violation saying which type was expected
     *
     * @test
     */
    public function it_refuses_a_value_that_is_not_a_gateway_config(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(GatewayConfigurations::USABLE, new UsableGatewayConfiguration());
    }

    /**
     * @test
     */
    public function it_refuses_an_option_the_gateway_rejects_without_a_logger(): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->shouldNotBeCalled();

        $this->validator->validate(
            GatewayConfigurations::entity([...GatewayConfigurations::USABLE, 'branding_id' => 'Our brand']),
            new UsableGatewayConfiguration(),
        );

        $this->buildViolation('setono_sylius_quickpay.gateway_configuration.gateway_option.branding_id')
            ->setParameter('{{ option }}', 'branding_id')
            ->atPath('property.path.config[branding_id]')
            ->assertRaised()
        ;
    }

    /**
     * An undetermined api key check fails open
     *
     * @test
     */
    public function it_accepts_an_api_key_whose_check_is_undetermined_without_a_logger(): void
    {
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::undetermined('The status code was: 500.'));

        $this->validator->validate(GatewayConfigurations::entity(), new UsableGatewayConfiguration());

        $this->assertNoViolation();
    }
}
