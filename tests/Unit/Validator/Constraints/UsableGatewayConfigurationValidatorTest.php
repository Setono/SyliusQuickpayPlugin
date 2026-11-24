<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Validator\Constraints;

use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusQuickpayPlugin\Diagnosis\DiagnoserInterface;
use Setono\SyliusQuickpayPlugin\Diagnosis\Finding;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingKind;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingOutcome;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\UsableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\UsableGatewayConfigurationValidator;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Save validation is tested against the container in tests/Functional/Validator and tests/Functional/Form. This covers
 * what the container doesn't show: the validator's contract with Symfony's validator, a failed finding it has no
 * message for, and that its logger is optional (the service is wired without one when the app has none)
 *
 * @extends ConstraintValidatorTestCase<UsableGatewayConfigurationValidator>
 */
final class UsableGatewayConfigurationValidatorTest extends ConstraintValidatorTestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DiagnoserInterface> */
    private ObjectProphecy $diagnoser;

    protected function setUp(): void
    {
        $this->diagnoser = $this->prophesize(DiagnoserInterface::class);

        parent::setUp();
    }

    protected function createValidator(): UsableGatewayConfigurationValidator
    {
        return new UsableGatewayConfigurationValidator($this->diagnoser->reveal());
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
     * Saving never leaves a test payment on the Quickpay account
     *
     * @test
     */
    public function it_diagnoses_the_gateway_configuration_without_the_live_check(): void
    {
        $this->diagnoser->diagnose(Argument::that(
            static fn (GatewayConfiguration $configuration): bool => 'quickpay_dk' === $configuration->gatewayName,
        ), false)->shouldBeCalledOnce()->willReturn([
            new Finding(FindingKind::ApiKey, FindingOutcome::Passed, 'The api key is accepted by Quickpay'),
        ]);

        $this->validator->validate(GatewayConfigurations::entity(gatewayName: 'quickpay_dk'), new UsableGatewayConfiguration());

        $this->assertNoViolation();
    }

    /**
     * @test
     */
    public function it_refuses_a_failed_finding_without_a_logger(): void
    {
        $this->diagnoser->diagnose(Argument::cetera())->willReturn([
            new Finding(FindingKind::RejectedOption, FindingOutcome::Failed, 'The "branding_id" option must be a positive integer', option: 'branding_id'),
        ]);

        $this->validator->validate(GatewayConfigurations::entity(), new UsableGatewayConfiguration());

        $this->buildViolation('setono_sylius_quickpay.gateway_configuration.gateway_option.branding_id')
            ->setParameter('{{ option }}', 'branding_id')
            ->atPath('property.path.config[branding_id]')
            ->assertRaised()
        ;
    }

    /**
     * A warning, an undetermined check among them, fails open
     *
     * @test
     */
    public function it_accepts_a_warning_without_a_logger(): void
    {
        $this->diagnoser->diagnose(Argument::cetera())->willReturn([
            new Finding(FindingKind::ApiKey, FindingOutcome::Warning, 'Could not check the api key: The status code was: 500.'),
        ]);

        $this->validator->validate(GatewayConfigurations::entity(), new UsableGatewayConfiguration());

        $this->assertNoViolation();
    }

    /**
     * Only the payment link permission can fail without a message, and saving never asks for it
     *
     * @test
     */
    public function it_refuses_to_guess_a_message_for_a_failed_finding_it_has_none_for(): void
    {
        $this->diagnoser->diagnose(Argument::cetera())->willReturn([
            new Finding(FindingKind::PaymentLinkPermission, FindingOutcome::Failed, 'The api user may not create payment links'),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Save validation has no message for a failed finding of the kind "payment_link_permission"');

        $this->validator->validate(GatewayConfigurations::entity(), new UsableGatewayConfiguration());
    }
}
