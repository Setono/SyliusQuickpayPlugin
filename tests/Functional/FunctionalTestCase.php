<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Response;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Psr\Http\Message\ResponseInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\ScriptedHttpClient;
use SM\Event\SMEvents;
use SM\Event\TransitionEvent;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

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

    /**
     * Quickpay payments whose status makes Sylius transition the Sylius payment, whether a callback or the
     * reconcile command reconciles it: Sylius' payment-state mapping and its order payment state rules. Each
     * case is the payment's and its order's states, the Quickpay payment's state, approved operations and
     * balance, then the payment's and the order's expected states.
     *
     * @return iterable<string, array{string, string, string, list<string>, int|null, string, string}>
     */
    public static function transitioningQuickpayStatuses(): iterable
    {
        $awaitingPayment = OrderPaymentStates::STATE_AWAITING_PAYMENT;

        yield 'pending moves a new payment to processing' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'pending', [], null, PaymentInterface::STATE_PROCESSING, $awaitingPayment];
        yield 'authorized' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'new', ['authorize'], 0, PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED];
        yield 'captured' => [PaymentInterface::STATE_PROCESSING, $awaitingPayment, 'processed', ['authorize', 'capture'], 10_000, PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID];
        yield 'refunded' => [PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, 'processed', ['authorize', 'capture', 'refund'], 0, PaymentInterface::STATE_REFUNDED, OrderPaymentStates::STATE_REFUNDED];
        // Sylius resolves no order payment state on cancel or fail
        yield 'cancelled' => [PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, 'processed', ['authorize', 'cancel'], 0, PaymentInterface::STATE_CANCELLED, OrderPaymentStates::STATE_AUTHORIZED];
        yield 'rejected' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'rejected', [], null, PaymentInterface::STATE_FAILED, $awaitingPayment];
        yield 'authorization never approved' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'new', [], null, PaymentInterface::STATE_FAILED, $awaitingPayment];
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

    /**
     * Takes the lock reconciling a Sylius payment takes, as a concurrent callback or reconcile run holding
     * it would. It stays held until released, even when the returned lock isn't kept. The test env's lock
     * store is in-memory, so the lock is shared with the booted container only.
     */
    protected function holdLockOf(int $quickpayPaymentId): LockInterface
    {
        $lockFactory = self::getContainer()->get('lock.setono_sylius_quickpay.factory');
        self::assertInstanceOf(LockFactory::class, $lockFactory);

        $lock = $lockFactory->createLock('setono_sylius_quickpay_payment_' . $quickpayPaymentId, autoRelease: false);
        self::assertTrue($lock->acquire(), 'The payment\'s lock is already held');

        return $lock;
    }

    /**
     * Records each payment transition Sylius applies from now on, by name, under either state machine
     * adapter. A transition is recorded once it has run with all its callbacks, so a transition applied
     * twice, e.g. from inside itself, shows up twice
     *
     * @return \ArrayObject<int, string>
     */
    protected function recordPaymentTransitions(): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $transitions */
        $transitions = new \ArrayObject();

        $eventDispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);

        $eventDispatcher->addListener(SMEvents::POST_TRANSITION, static function (TransitionEvent $event) use ($transitions): void {
            if (PaymentTransitions::GRAPH === $event->getStateMachine()->getGraph()) {
                $transitions[] = $event->getTransition();
            }
        });
        $eventDispatcher->addListener(sprintf('workflow.%s.completed', PaymentTransitions::GRAPH), static function (CompletedEvent $event) use ($transitions): void {
            $transitions[] = (string) $event->getTransition()?->getName();
        });

        return $transitions;
    }

    /**
     * The reconciliation module, as the booted container wires it
     */
    protected function reconciler(): PaymentReconcilerInterface
    {
        $reconciler = self::getContainer()->get(PaymentReconcilerInterface::class);
        self::assertInstanceOf(PaymentReconcilerInterface::class, $reconciler);

        return $reconciler;
    }

    protected function payum(): Payum
    {
        $payum = self::getContainer()->get('payum');
        self::assertInstanceOf(Payum::class, $payum);

        return $payum;
    }

    protected function gatewayOf(PaymentInterface $payment): GatewayInterface
    {
        return $this->payum()->getGateway(self::gatewayNameOf($payment));
    }

    /**
     * The name of the Payum gateway the payment is paid through, its payment method's gateway config's
     */
    protected static function gatewayNameOf(PaymentInterface $payment): string
    {
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $gatewayName = $method->getGatewayConfig()?->getGatewayName();
        self::assertIsString($gatewayName);

        return $gatewayName;
    }

    /**
     * A Sylius payment of an order, paid with a Quickpay payment method and linked to a Quickpay
     * payment, all persisted. For several payments in one test, give each its own order number.
     *
     * Without ordered variants the order totals 0, so any payment pays it in full. With them, the order
     * holds one unit of each, priced so the order totals the payment's amount, and paying the order sells
     * those units.
     *
     * @param list<ProductVariantInterface> $orderedVariants
     */
    protected function createSyliusPayment(
        string $state,
        string $orderPaymentState,
        int $quickpayPaymentId,
        int $amount = 10_000,
        string $orderNumber = '000000001',
        array $orderedVariants = [],
    ): PaymentInterface {
        $order = $this->createOrder($this->channel(), $orderPaymentState, $orderNumber);
        foreach ($orderedVariants as $variant) {
            $order->addItem($this->createOrderItem($variant, intdiv($amount, \count($orderedVariants))));
        }
        self::assertSame([] === $orderedVariants ? 0 : $amount, $order->getTotal(), 'The payment must pay the order in full');

        $paymentFactory = self::getContainer()->get('sylius.factory.payment');
        self::assertInstanceOf(PaymentFactoryInterface::class, $paymentFactory);

        $payment = $paymentFactory->createWithAmountAndCurrencyCode($amount, self::CURRENCY);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod($this->quickpayPaymentMethod());
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
     * A product variant whose inventory Sylius tracks, persisted. Paying an order sells the units it holds,
     * taking them off both counts; Sylius fails the order's `pay` when the variant has fewer units on hold
     * or on hand than the order sells.
     */
    protected function createTrackedVariant(string $code, int $onHand, int $onHold): ProductVariantInterface
    {
        $product = $this->createResource('sylius.factory.product');
        self::assertInstanceOf(ProductInterface::class, $product);
        $product->setCode($code);
        $product->setName($code);
        $product->setSlug($code);

        $variant = $this->createResource('sylius.factory.product_variant');
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $variant->setCode($code);
        $variant->setName($code);
        $variant->setTracked(true);
        $variant->setOnHand($onHand);
        $variant->setOnHold($onHold);
        $product->addVariant($variant);

        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $variant;
    }

    /**
     * The variant's inventory as committed to the database, read after clearing the entity manager
     *
     * @return array{onHand: int|null, onHold: int|null}
     */
    protected function inventoryOf(ProductVariantInterface $variant): array
    {
        $id = $variant->getId();
        $this->entityManager()->clear();

        $reloaded = $this->findOneBy('sylius.repository.product_variant', ['id' => $id]);
        self::assertInstanceOf(ProductVariantInterface::class, $reloaded);

        return ['onHand' => $reloaded->getOnHand(), 'onHold' => $reloaded->getOnHold()];
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
     * @param bool $fraudSuspected whether Quickpay's fraud filter flagged the payment
     */
    protected static function quickpayPayment(int $id, string $state, array $approvedOperations, ?int $balance = null, bool $fraudSuspected = false): ResponseInterface
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
            'metadata' => ['fraud_suspected' => $fraudSuspected],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * Quickpay's callback about a Quickpay payment, as POSTed to the plugin's shared callback endpoint:
     * the payment as JSON, signed with the gateway's private key unless another key is given
     */
    protected static function quickpayCallback(int $quickpayPaymentId, string $orderNumber = '000000001', string $privateKey = self::PRIVATE_KEY): Request
    {
        $body = json_encode([
            'id' => $quickpayPaymentId,
            'order_id' => self::ORDER_PREFIX . $orderNumber,
            'accepted' => true,
            'currency' => self::CURRENCY,
        ], \JSON_THROW_ON_ERROR);

        return Request::create('/payment/quickpay/notify', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_QUICKPAY_RESOURCE_TYPE' => 'Payment',
            'HTTP_QUICKPAY_CHECKSUM_SHA256' => hash_hmac('sha256', $body, $privateKey),
        ], content: $body);
    }

    /**
     * Makes the callback the request being handled, in place of any received before, as Payum reads the
     * main request
     */
    protected function receiveCallback(Request $callback): void
    {
        $requestStack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);

        while (null !== $requestStack->pop()) {
        }
        $requestStack->push($callback);
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->findOneBy('sylius.repository.channel', ['code' => 'web']);
        if (null !== $channel) {
            self::assertInstanceOf(ChannelInterface::class, $channel);

            return $channel;
        }

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

    private function createOrder(ChannelInterface $channel, string $paymentState, string $number): OrderInterface
    {
        $order = $this->createResource('sylius.factory.order');
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setChannel($channel);
        $order->setNumber($number);
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

    /**
     * One unit of the variant at the given price
     */
    private function createOrderItem(ProductVariantInterface $variant, int $unitPrice): OrderItemInterface
    {
        $item = $this->createResource('sylius.factory.order_item');
        self::assertInstanceOf(OrderItemInterface::class, $item);
        $item->setVariant($variant);
        $item->setUnitPrice($unitPrice);

        $quantityModifier = self::getContainer()->get('sylius.order_item_quantity_modifier');
        self::assertInstanceOf(OrderItemQuantityModifierInterface::class, $quantityModifier);
        $quantityModifier->modify($item, 1);

        return $item;
    }

    private function quickpayPaymentMethod(): PaymentMethodInterface
    {
        $paymentMethod = $this->findOneBy('sylius.repository.payment_method', ['code' => 'quickpay']);
        if (null !== $paymentMethod) {
            self::assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);

            return $paymentMethod;
        }

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
