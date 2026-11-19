<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Form;

use Nyholm\Psr7\Response;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Bundle\PaymentBundle\Form\Type\PaymentMethodType;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Twig\Environment;

/**
 * The admin's payment method form shows the Quickpay gateway configuration's fields in the admin's language, and
 * refuses a Quickpay gateway configuration the gateway would reject, with each error on the field to fix
 */
final class PaymentMethodFormTest extends FunctionalTestCase
{
    /**
     * Every field has a label and a help text of its own, translated into each locale the plugin ships
     *
     * @test
     *
     * @dataProvider locales
     */
    public function it_labels_and_explains_every_gateway_configuration_field_in_every_locale(string $locale): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue($locale);

        $fields = $this->gatewayConfigurationFields();
        self::assertSame(
            ['api_key', 'private_key', 'agreement_id', 'payment_methods', 'use_authorize', 'synchronized', 'order_prefix', 'branding_id'],
            array_keys($fields),
        );

        foreach ($fields as $field => $vars) {
            // Twig translates the label and help text in the messages domain when the form names none
            self::assertNull($vars['translation_domain']);

            foreach (['label', 'help'] as $text) {
                self::assertIsString($vars[$text], sprintf('The "%s" field has a %s', $field, $text));
                self::assertTrue(
                    $catalogue->defines($vars[$text], 'messages'),
                    sprintf('The %s of the "%s" field, "%s", is translated into "%s"', $text, $field, $vars[$text], $locale),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (['cs', 'da', 'de', 'en', 'es', 'fi', 'fr', 'hu', 'it', 'nl', 'no', 'pl', 'pt', 'ro', 'sv', 'uk'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * The admin's browser demands only what the gateway can't do without: the credentials, and the capture mode, which
     * always has one chosen. A required checkbox couldn't be saved unchecked.
     *
     * @test
     */
    public function it_requires_only_the_credentials_and_the_capture_mode(): void
    {
        self::bootKernel();

        self::assertSame([
            'api_key' => true,
            'private_key' => true,
            'agreement_id' => false,
            'payment_methods' => false,
            'use_authorize' => true,
            'synchronized' => false,
            'order_prefix' => false,
            'branding_id' => false,
        ], array_map(static fn (array $vars): mixed => $vars['required'], $this->gatewayConfigurationFields()));
    }

    /**
     * @test
     */
    public function it_shows_an_example_in_the_empty_payment_methods_and_order_prefix_fields(): void
    {
        self::bootKernel();

        $fields = $this->gatewayConfigurationFields();

        self::assertSame(['placeholder' => 'creditcard, mobilepay'], $fields['payment_methods']['attr']);
        self::assertSame(['placeholder' => 'qp_'], $fields['order_prefix']['attr']);
    }

    /**
     * Sylius' admin form theme escapes help texts, so the plugin's own form theme renders the payment methods help: its
     * link to Quickpay's documentation is one the admin can follow
     *
     * @test
     */
    public function it_renders_the_link_in_the_payment_methods_help(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $help = $twig->createTemplate('{{ form_help(form.gatewayConfig.config.payment_methods) }}')->render([
            'form' => $this->paymentMethodForm()->createView(),
        ]);

        self::assertStringContainsString(
            '<a href="https://learn.quickpay.net/tech-talk/appendixes/payment-methods/#payment-methods" target="_blank" rel="noopener">',
            $help,
        );
    }

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
     * The order prefix field carries no length of its own: an order prefix too long for the store's latest order is one
     * error on the field, as payum-quickpay's rule finds it, and a long one that fits the store's own numbers saves
     *
     * @test
     *
     * @dataProvider orderPrefixes
     *
     * @param array<string, int> $expected
     */
    public function it_puts_an_order_id_quickpay_refuses_on_the_order_prefix_field(string $latestOrderNumber, string $orderPrefix, array $expected): void
    {
        self::bootKernel();
        $this->createOrder($this->channel(), 'awaiting_payment', $latestOrderNumber);
        $this->entityManager()->flush();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', []), [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
            'order_prefix' => $orderPrefix,
        ]);

        self::assertSame($expected, $errors);
    }

    /**
     * @return iterable<string, array{string, string, array<string, int>}>
     */
    public static function orderPrefixes(): iterable
    {
        yield 'too long for the latest order' => ['000000123', 'qp_shop_2026_', ['gatewayConfig.config.order_prefix' => 1]];
        yield 'longer than 11, fitting the store\'s own numbers' => ['1234', 'qp_shop_2026_', []];
        yield 'a character Quickpay refuses' => ['000000123', 'qp#', ['gatewayConfig.config.order_prefix' => 1]];
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
     * Quickpay then uses the account's default agreement, which needs no check
     *
     * @test
     */
    public function it_asks_quickpay_about_no_agreement_without_an_agreement_id(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', []), [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
            'agreement_id' => '',
        ]);

        self::assertSame([], $errors);
        self::assertSame(['GET /ping'], $this->quickpay()->sentRequests());
    }

    /**
     * Quickpay's answer about the agreement proves nothing with an api key it rejects, and the rejected key is the
     * one error to fix
     *
     * @test
     */
    public function it_asks_quickpay_about_no_agreement_when_it_rejects_the_api_key(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));
        $this->quickpay()->respond('GET', '/payments', new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid API key"}'));

        $errors = $this->submit($this->createPaymentMethod('quickpay_dk', []), [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
            'agreement_id' => '12345',
        ]);

        self::assertSame(['gatewayConfig.config.api_key' => 1], $errors);
        self::assertSame(['GET /ping', 'GET /payments'], $this->quickpay()->sentRequests());
    }

    /**
     * Sylius names a new payment method's gateway after its code, so one submitted without a code has no gateway name
     * yet: its gateway configuration is still checked, and the errors are Sylius' own, the missing code and the gateway
     * name it would give
     *
     * @test
     */
    public function it_checks_the_gateway_configuration_of_a_new_payment_method_submitted_without_a_code(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $paymentMethodFactory = self::getContainer()->get('sylius.factory.payment_method');
        self::assertInstanceOf(PaymentMethodFactoryInterface::class, $paymentMethodFactory);
        $paymentMethod = $paymentMethodFactory->createWithGateway('quickpay');

        $errors = $this->submit($paymentMethod, [
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
        ]);

        self::assertSame(['code' => 1, 'gatewayConfig' => 1], $errors);
        self::assertSame(['GET /ping'], $this->quickpay()->sentRequests());
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
     * The admin's payment method form of a new Quickpay payment method
     *
     * @return FormInterface<mixed>
     */
    private function paymentMethodForm(): FormInterface
    {
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create(PaymentMethodType::class, $this->createPaymentMethod('quickpay_dk', []), ['csrf_protection' => false]);
    }

    /**
     * What the admin's payment method form of a new Quickpay payment method shows of each gateway configuration field:
     * the variables of its view, by the field's name, in the form's order
     *
     * @return array<string, array<mixed>>
     */
    private function gatewayConfigurationFields(): array
    {
        $fields = [];
        foreach ($this->paymentMethodForm()->createView()['gatewayConfig']['config'] as $field => $view) {
            self::assertIsArray($view->vars);
            $fields[(string) $field] = $view->vars;
        }

        return $fields;
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
