<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Exception\InternalServerErrorException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifier;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;

final class ApiKeyVerifierTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_verifies_a_key_with_the_ping_permission(): void
    {
        $verifier = $this->createVerifier(new Response(200, [], '{}'));

        self::assertTrue($verifier->verify('the-api-key'));
    }

    /**
     * Quickpay answers 401 on /ping both for an invalid key and for a valid key whose api user
     * lacks the /ping permission (verified live), so a rejected ping is decided by /payments
     *
     * @test
     */
    public function it_verifies_a_key_without_the_ping_permission_through_payments(): void
    {
        $verifier = $this->createVerifier(
            new Response(401, [], '{"message": "Invalid API key"}'),
            new Response(200, [], '[]'),
        );

        self::assertFalse($verifier->verify('the-api-key'));
    }

    /**
     * @test
     */
    public function it_throws_when_quickpay_rejects_the_key_on_both_endpoints(): void
    {
        $verifier = $this->createVerifier(
            new Response(401, [], '{"message": "Invalid API key"}'),
            new Response(401, [], '{"message": "Invalid API key"}'),
        );

        $this->expectException(UnauthorizedException::class);

        $verifier->verify('the-api-key');
    }

    /**
     * @test
     */
    public function it_propagates_an_unanswered_ping(): void
    {
        $verifier = $this->createVerifier(new Response(500, [], '{"message": "boom"}'));

        $this->expectException(InternalServerErrorException::class);

        $verifier->verify('the-api-key');
    }

    /**
     * @test
     */
    public function it_propagates_an_unanswered_payments_fallback(): void
    {
        $verifier = $this->createVerifier(
            new Response(401, [], '{"message": "Invalid API key"}'),
            new Response(500, [], '{"message": "boom"}'),
        );

        $this->expectException(InternalServerErrorException::class);

        $verifier->verify('the-api-key');
    }

    private function createVerifier(Response ...$responses): ApiKeyVerifier
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory
            ->create('the-api-key')
            ->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(...$responses)))
        ;

        return new ApiKeyVerifier($clientFactory->reveal());
    }
}
