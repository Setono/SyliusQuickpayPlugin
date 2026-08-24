<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Validator\Constraints;

use Nyholm\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Quickpay\Exception\InternalServerErrorException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifierInterface;
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

    /** @var ObjectProphecy<ApiKeyVerifierInterface> */
    private ObjectProphecy $apiKeyVerifier;

    protected function createValidator(): QuickpayCredentialsValidator
    {
        $this->apiKeyVerifier = $this->prophesize(ApiKeyVerifierInterface::class);

        return new QuickpayCredentialsValidator($this->apiKeyVerifier->reveal());
    }

    /**
     * @test
     */
    public function it_accepts_a_key_quickpay_accepts(): void
    {
        $this->apiKeyVerifier->verify('the-api-key')->willReturn(true);

        $this->validator->validate('the-api-key', new QuickpayCredentials());

        $this->assertNoViolation();
    }

    /**
     * A valid key whose api user lacks the /ping permission is verified through /payments and must
     * not raise a violation (see the verifier and issue #141)
     *
     * @test
     */
    public function it_accepts_a_key_verified_through_the_payments_fallback(): void
    {
        $this->apiKeyVerifier->verify('the-api-key')->willReturn(false);

        $this->validator->validate('the-api-key', new QuickpayCredentials());

        $this->assertNoViolation();
    }

    /**
     * @test
     */
    public function it_raises_a_violation_when_quickpay_rejects_the_key(): void
    {
        $this->apiKeyVerifier->verify('the-api-key')->willThrow(new UnauthorizedException(new Response(401)));

        $constraint = new QuickpayCredentials();
        $this->validator->validate('the-api-key', $constraint);

        $this->buildViolation($constraint->message)->assertRaised();
    }

    /**
     * @test
     */
    public function it_fails_open_when_quickpay_cannot_be_reached(): void
    {
        $this->apiKeyVerifier->verify('the-api-key')->willThrow(new InternalServerErrorException(new Response(500)));

        $this->validator->validate('the-api-key', new QuickpayCredentials());

        $this->assertNoViolation();
    }

    /**
     * @test
     */
    public function it_ignores_empty_values(): void
    {
        $this->apiKeyVerifier->verify(Argument::any())->shouldNotBeCalled();

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
