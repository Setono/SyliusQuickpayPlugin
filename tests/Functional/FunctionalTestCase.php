<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Response;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Psr\Http\Message\ResponseInterface;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\ScriptedHttpClient;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base for kernel-booting tests against the test application's database. dama/doctrine-test-bundle
 * wraps each test in a transaction it rolls back, so every test builds its own entities from Sylius'
 * factories. Quickpay's HTTP is the one thing substituted: script it with {@see self::quickpay()}.
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    protected const CURRENCY = 'DKK';

    protected const LOCALE = 'en_US';

    protected const ORDER_PREFIX = 'qp_';

    protected const PRIVATE_KEY = 'the-private-key';

    /**
     * The test application's environment running each of Sylius' state machine adapters
     */
    private const ENVIRONMENTS = [
        'winzou_state_machine' => 'test',
        'symfony_workflow' => 'test_workflow',
    ];

    /**
     * For tests that must hold under both of Sylius' state machine adapters: boot the kernel with
     * {@see self::bootKernelWithStateMachineAdapter()}
     *
     * @return iterable<string, array{string}>
     */
    public static function stateMachineAdapters(): iterable
    {
        foreach (array_keys(self::ENVIRONMENTS) as $adapter) {
            yield $adapter => [$adapter];
        }
    }

    protected static function bootKernelWithStateMachineAdapter(string $adapter): void
    {
        self::bootKernel(['environment' => self::ENVIRONMENTS[$adapter]]);

        // Guards the harness: a kernel silently running the other adapter would make a test meant
        // for both run the same one twice
        self::assertSame($adapter, self::getContainer()->getParameter('sylius_abstraction.state_machine.default_adapter'));
    }

    /**
     * The scripted client every Quickpay gateway in the booted kernel sends its requests to
     */
    protected function quickpay(): ScriptedHttpClient
    {
        $client = self::getContainer()->get(ScriptedHttpClient::class);
        self::assertInstanceOf(ScriptedHttpClient::class, $client);

        return $client;
    }

    protected function gatewayOf(PaymentInterface $payment): GatewayInterface
    {
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $gatewayName = $method->getGatewayConfig()?->getGatewayName();
        self::assertIsString($gatewayName);

        $payum = self::getContainer()->get('payum');
        self::assertInstanceOf(Payum::class, $payum);

        return $payum->getGateway($gatewayName);
    }

    /**
     * A Sylius payment of an order, paid with a Quickpay payment method and linked to a Quickpay
     * payment, all persisted
     */
    protected function createSyliusPayment(string $state, string $orderPaymentState, int $quickpayPaymentId, int $amount = 10_000): PaymentInterface
    {
        $order = $this->createOrder($this->createChannel(), $orderPaymentState);

        $paymentFactory = self::getContainer()->get('sylius.factory.payment');
        self::assertInstanceOf(PaymentFactoryInterface::class, $paymentFactory);

        $payment = $paymentFactory->createWithAmountAndCurrencyCode($amount, self::CURRENCY);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod($this->createQuickpayPaymentMethod());
        $payment->setState($state);
        $payment->setDetails([
            'quickpayPaymentId' => $quickpayPaymentId,
            'order_id' => self::ORDER_PREFIX . (string) $order->getNumber(),
            'amount' => $amount,
            'currency' => self::CURRENCY,
        ]);
        $order->addPayment($payment);

        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();

        return $payment;
    }

    /**
     * The payment as committed to the database, read after clearing the entity manager
     */
    protected function reload(PaymentInterface $payment): PaymentInterface
    {
        $id = $payment->getId();
        $this->entityManager()->clear();

        $reloaded = $this->findOneBy('sylius.repository.payment', ['id' => $id]);
        self::assertInstanceOf(PaymentInterface::class, $reloaded);

        return $reloaded;
    }

    /**
     * A Quickpay payment as `GET /payments/{id}` returns it
     *
     * @param list<string> $approvedOperations operation types, in the order they happened
     */
    protected static function quickpayPayment(int $id, string $state, array $approvedOperations, ?int $balance = null): ResponseInterface
    {
        $operations = [];
        foreach ($approvedOperations as $i => $type) {
            $operations[] = [
                'id' => $i + 1,
                'type' => $type,
                'amount' => 10_000,
                'pending' => false,
                'qp_status_code' => '20000',
                'qp_status_msg' => 'Approved',
            ];
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id,
            'order_id' => self::ORDER_PREFIX . '000000001',
            'currency' => self::CURRENCY,
            'state' => $state,
            'merchant_id' => 1,
            'accepted' => true,
            'test_mode' => true,
            'balance' => $balance,
            'operations' => $operations,
        ], \JSON_THROW_ON_ERROR));
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function createChannel(): ChannelInterface
    {
        $currency = $this->findOneBy('sylius.repository.currency', ['code' => self::CURRENCY]);
        if (null === $currency) {
            $currency = $this->createResource('sylius.factory.currency');
            self::assertInstanceOf(CurrencyInterface::class, $currency);
            $currency->setCode(self::CURRENCY);
            $this->entityManager()->persist($currency);
        }
        self::assertInstanceOf(CurrencyInterface::class, $currency);

        $locale = $this->findOneBy('sylius.repository.locale', ['code' => self::LOCALE]);
        if (null === $locale) {
            $locale = $this->createResource('sylius.factory.locale');
            self::assertInstanceOf(LocaleInterface::class, $locale);
            $locale->setCode(self::LOCALE);
            $this->entityManager()->persist($locale);
        }
        self::assertInstanceOf(LocaleInterface::class, $locale);

        $channel = $this->createResource('sylius.factory.channel');
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $channel->setCode('web');
        $channel->setName('Web');
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $this->entityManager()->persist($channel);

        return $channel;
    }

    private function createOrder(ChannelInterface $channel, string $paymentState): OrderInterface
    {
        $order = $this->createResource('sylius.factory.order');
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setChannel($channel);
        $order->setNumber('000000001');
        $order->setCurrencyCode(self::CURRENCY);
        $order->setLocaleCode(self::LOCALE);
        $order->setState(BaseOrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setCheckoutCompletedAt(new \DateTime());
        $order->setPaymentState($paymentState);
        $order->setShippingState(OrderShippingStates::STATE_READY);
        $this->entityManager()->persist($order);

        return $order;
    }

    private function createQuickpayPaymentMethod(): PaymentMethodInterface
    {
        $paymentMethodFactory = self::getContainer()->get('sylius.factory.payment_method');
        self::assertInstanceOf(PaymentMethodFactoryInterface::class, $paymentMethodFactory);

        $paymentMethod = $paymentMethodFactory->createWithGateway('quickpay');
        $paymentMethod->setCode('quickpay');

        $gatewayConfig = $paymentMethod->getGatewayConfig();
        self::assertNotNull($gatewayConfig);
        $gatewayConfig->setGatewayName('quickpay');
        $gatewayConfig->setConfig([
            'api_key' => 'the-api-key',
            'private_key' => self::PRIVATE_KEY,
            'order_prefix' => self::ORDER_PREFIX,
        ]);

        $this->entityManager()->persist($paymentMethod);

        return $paymentMethod;
    }

    private function createResource(string $factoryId): object
    {
        $factory = self::getContainer()->get($factoryId);
        self::assertInstanceOf(FactoryInterface::class, $factory);

        return $factory->createNew();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function findOneBy(string $repositoryId, array $criteria): ?object
    {
        $repository = self::getContainer()->get($repositoryId);
        self::assertInstanceOf(RepositoryInterface::class, $repository);

        return $repository->findOneBy($criteria);
    }
}
