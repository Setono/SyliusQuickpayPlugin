<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Validator;

use Nyholm\Psr7\Response;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Bundle\PayumBundle\Validator\GroupsGenerator\GatewayConfigGroupsGenerator;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Saving a payment method validates it with the groups Sylius picks for its gateway, in the admin form and the Admin
 * API alike: a Quickpay gateway configuration the gateway would reject is refused
 */
final class GatewayConfigurationValidationTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_refuses_a_quickpay_gateway_configuration_the_gateway_would_reject_with_a_violation_per_option(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', [
            'api_key' => 'the-api-key',
            'agreement_id' => 0,
            'branding_id' => 'Our brand',
        ]);

        self::assertEqualsCanonicalizing([
            'gatewayConfig.config[private_key]',
            'gatewayConfig.config[agreement_id]',
            'gatewayConfig.config[branding_id]',
        ], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame([], $this->quickpay()->sentRequests(), 'The api key of a configuration that is refused anyway is not verified');
    }

    /**
     * @test
     */
    public function it_reports_a_missing_api_key_once_without_asking_quickpay(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', ['api_key' => '', 'private_key' => 'the-private-key']);

        self::assertSame(['gatewayConfig.config[api_key]'], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function it_leaves_the_gateway_configurations_of_other_gateways_alone(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('bank_transfer', ['branding_id' => 'Our brand'], factoryName: 'offline');

        self::assertSame([], $this->violationsOf($paymentMethod));
    }

    /**
     * @test
     */
    public function it_accepts_a_usable_quickpay_gateway_configuration_whose_api_key_quickpay_accepts(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);

        self::assertSame([], $this->violationsOf($paymentMethod));
        self::assertSame(['GET /ping'], $this->quickpay()->sentRequests(), 'The api key is verified with Quickpay');
    }

    /**
     * Quickpay answers 401 on /ping for a valid key whose api user lacks the /ping permission too, so only a
     * rejected /payments read as well rejects the key
     *
     * @test
     */
    public function it_refuses_an_api_key_quickpay_rejects(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));
        $this->quickpay()->respond('GET', '/payments', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);

        self::assertSame(['gatewayConfig.config[api_key]'], array_keys($this->violationsOf($paymentMethod)));
    }

    /**
     * As one that PayumBundle encrypted is until something decrypts it: encrypting gateway configurations is not
     * supported
     *
     * @test
     */
    public function it_refuses_a_quickpay_gateway_configuration_that_reads_as_empty(): void
    {
        self::bootKernel();

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);
        $paymentMethod->setGatewayConfig(GatewayConfigurations::encryptedEntity('quickpay_dk'));

        self::assertSame(['gatewayConfig.config'], array_keys($this->violationsOf($paymentMethod)));
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * Only an explicit rejection of the key counts: an unreachable Quickpay never makes a payment method unsaveable
     *
     * @test
     */
    public function it_accepts_an_api_key_quickpay_could_not_verify(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(500, ['Content-Type' => 'application/json'], '{"message":"Internal server error"}'));

        $paymentMethod = $this->createPaymentMethod('quickpay_dk', GatewayConfigurations::USABLE);

        self::assertSame([], $this->violationsOf($paymentMethod));
    }

    /**
     * The violations saving the payment method meets, each message keyed by its path
     *
     * @return array<string, string>
     */
    private function violationsOf(PaymentMethodInterface $paymentMethod): array
    {
        $groupsGenerator = self::getContainer()->get(GatewayConfigGroupsGenerator::class);
        self::assertInstanceOf(GatewayConfigGroupsGenerator::class, $groupsGenerator);

        $validator = self::getContainer()->get('validator');
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $violations = [];
        foreach ($validator->validate($paymentMethod, groups: $groupsGenerator($paymentMethod)) as $violation) {
            $violations[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $violations;
    }
}
