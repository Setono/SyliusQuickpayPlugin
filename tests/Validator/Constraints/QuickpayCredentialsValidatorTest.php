<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Validator\Constraints;

use Nyholm\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Exception\InternalServerErrorException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\QuickpayCredentials;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\QuickpayCredentialsValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<QuickpayCredentialsValidator>
 */
final class QuickpayCredentialsValidatorTest extends ConstraintValidatorTestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ClientFactoryInterface> */
    private ObjectProphecy $clientFactory;

    /** @var ObjectProphecy<ClientInterface> */
    private ObjectProphecy $client;

    protected function createValidator(): QuickpayCredentialsValidator
    {
        $this->client = $this->prophesize(ClientInterface::class);

        $this->clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $this->clientFactory->create('the-api-key')->willReturn($this->client);

        return new QuickpayCredentialsValidator($this->clientFactory->reveal());
    }

    /**
     * @test
     */
    public function it_accepts_a_key_quickpay_accepts(): void
    {
        $this->client->ping()->willReturn(true);

        $this->validator->validate('the-api-key', new QuickpayCredentials());

        $this->assertNoViolation();
    }

    /**
     * @test
     */
    public function it_raises_a_violation_when_quickpay_rejects_the_key(): void
    {
        $this->client->ping()->willThrow(new UnauthorizedException(new Response(401)));

        $constraint = new QuickpayCredentials();
        $this->validator->validate('the-api-key', $constraint);

        $this->buildViolation($constraint->message)->assertRaised();
    }

    /**
     * @test
     */
    public function it_fails_open_when_quickpay_cannot_be_reached(): void
    {
        $this->client->ping()->willThrow(new InternalServerErrorException(new Response(500)));

        $this->validator->validate('the-api-key', new QuickpayCredentials());

        $this->assertNoViolation();
    }

    /**
     * @test
     */
    public function it_ignores_empty_values(): void
    {
        $this->clientFactory->create(Argument::any())->shouldNotBeCalled();

        $this->validator->validate(null, new QuickpayCredentials());
        $this->validator->validate('', new QuickpayCredentials());

        $this->assertNoViolation();
    }

    /**
     * @test
     */
    public function it_rejects_other_constraints(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('the-api-key', new NotBlank());
    }
}
