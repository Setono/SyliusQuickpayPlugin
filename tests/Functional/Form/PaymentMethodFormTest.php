<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Form;

use Nyholm\Psr7\Response;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Bundle\PaymentBundle\Form\Type\PaymentMethodType;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * The admin's payment method form refuses a Quickpay gateway configuration the gateway would reject, with each error
 * on the field to fix
 */
final class PaymentMethodFormTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_puts_each_error_on_its_field(): void
    {
        self::bootKernel();

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', []), [
            'api_key' => 'the-api-key',
            'private_key' => '',
            'agreement_id' => '0',
            'branding_id' => 'Our brand',
        ]);

        self::assertSame([
            'gatewayConfig.config.agreement_id' => 1,
            'gatewayConfig.config.branding_id' => 1,
            'gatewayConfig.config.private_key' => 1,
        ], $errors);
    }

    /**
     * @test
     */
    public function it_puts_the_rejection_of_the_api_key_on_the_api_key_field(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));
        $this->quickpay()->respond('GET', '/payments', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', []), [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
        ]);

        self::assertSame(['gatewayConfig.config.api_key' => 1], $errors);
    }

    /**
     * @test
     */
    public function it_puts_the_refusal_of_an_agreement_quickpay_does_not_find_on_the_agreement_field(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));
        $this->quickpay()->respond('GET', '/agreements/12345', new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not found"}'));

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', []), [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
            'agreement_id' => '12345',
        ]);

        self::assertSame(['gatewayConfig.config.agreement_id' => 1], $errors);
    }

    /**
     * The api user may lack the /agreements permission, which must not block saving
     *
     * @test
     *
     * @dataProvider refusals
     */
    public function it_saves_an_agreement_quickpay_refuses_to_read(int $status): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));
        $this->quickpay()->respond('GET', '/agreements/12345', new Response($status, ['Content-Type' => 'application/json'], '{"message":"Not authorized"}'));

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', []), [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
            'agreement_id' => '12345',
        ]);

        self::assertSame([], $errors);
        self::assertSame(['GET /ping', 'GET /agreements/12345'], $this->quickpay()->sentRequests());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusals(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    /**
     * A stored gateway option the form has no field for, like the language, is kept when the form is saved
     *
     * @test
     */
    public function it_puts_an_error_on_an_option_without_a_field_on_the_gateway_configuration(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', ['language' => ['da']]), [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
        ]);

        self::assertSame(['gatewayConfig.config' => 1], $errors);
    }

    /**
     * Submits the admin's payment method form with the gateway configuration fields given, the payment method's other
     * fields as they are
     *
     * @param array<string, string> $gatewayConfiguration
     *
     * @return array<string, int> how many errors each field of the form has, by the field's path in the form
     */
    private function submit(PaymentMethodInterface $paymentMethod, array $gatewayConfiguration): array
    {
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $form = $formFactory->create(PaymentMethodType::class, $paymentMethod, ['csrf_protection' => false]);
        $form->submit(['gatewayConfig' => ['config' => $gatewayConfiguration + ['use_authorize' => '1']]], false);

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            self::assertInstanceOf(FormError::class, $error);

            $path = [];
            for ($field = $error->getOrigin(); null !== $field?->getParent(); $field = $field->getParent()) {
                array_unshift($path, $field->getName());
            }

            $path = implode('.', $path);
            $errors[$path] = ($errors[$path] ?? 0) + 1;
        }
        ksort($errors);

        return $errors;
    }
}
