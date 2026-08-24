<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Payum\Extension;

use Payum\Core\Extension\Context;
use Payum\Core\Gateway;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Payum\Extension\NotifyIdempotencyExtension;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class NotifyIdempotencyExtensionTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_locks_the_payment_while_a_notify_is_processed_and_releases_it_afterwards(): void
    {
        $store = new InMemoryStore();
        $extension = new NotifyIdempotencyExtension(new LockFactory($store));

        $context = $this->createContext(new Notify(new \ArrayObject(['quickpayPaymentId' => 123])));

        $extension->onPreExecute($context);

        self::assertNull($context->getAction());
        self::assertFalse($this->tryAcquire($store, 123), 'the lock should be held while the notify is processed');

        $extension->onPostExecute($context);

        self::assertTrue($this->tryAcquire($store, 123), 'the lock should be released after the notify was processed');
    }

    /**
     * @test
     */
    public function it_resolves_an_in_flight_duplicate_to_a_noop_action(): void
    {
        $store = new InMemoryStore();

        // the in-flight callback, i.e. another process holding the lock
        $inFlight = (new LockFactory($store))->createLock('setono_sylius_quickpay_notify_123');
        self::assertTrue($inFlight->acquire());

        $extension = new NotifyIdempotencyExtension(new LockFactory($store));

        $request = new Notify(new \ArrayObject(['quickpayPaymentId' => 123]));
        $context = $this->createContext($request);

        $extension->onPreExecute($context);

        $action = $context->getAction();
        self::assertNotNull($action, 'the duplicate should be resolved to a noop action instead of being processed');
        self::assertTrue($action->supports($request));
        $action->execute($request);

        // the duplicate did not acquire anything, so nothing must be released
        $extension->onPostExecute($context);
        self::assertFalse($this->tryAcquire($store, 123), 'the in-flight callback should still hold the lock');
    }

    /**
     * @test
     *
     * @dataProvider unrelatedRequestProvider
     */
    public function it_ignores_requests_that_are_not_a_details_notify(mixed $request): void
    {
        $store = $this->prophesize(PersistingStoreInterface::class);
        $store->save(Argument::any())->shouldNotBeCalled();

        $extension = new NotifyIdempotencyExtension(new LockFactory($store->reveal()));

        $context = $this->createContext($request);

        $extension->onPreExecute($context);

        self::assertNull($context->getAction());

        $extension->onPostExecute($context);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unrelatedRequestProvider(): iterable
    {
        yield 'not a notify request' => [new \stdClass()];

        yield 'notify without a model' => [new Notify(null)];

        yield 'notify without a quickpay payment id' => [new Notify(new \ArrayObject(['order_id' => 'qp_123']))];

        yield 'notify with a non-numeric quickpay payment id' => [new Notify(new \ArrayObject(['quickpayPaymentId' => 'foo']))];
    }

    /**
     * @test
     */
    public function it_fails_open_when_the_lock_store_is_broken(): void
    {
        $store = $this->prophesize(PersistingStoreInterface::class);
        $store->save(Argument::type(Key::class))->willThrow(new \RuntimeException('the lock store is down'));

        $extension = new NotifyIdempotencyExtension(new LockFactory($store->reveal()));

        $context = $this->createContext(new Notify(new \ArrayObject(['quickpayPaymentId' => 123])));

        $extension->onPreExecute($context);

        self::assertNull($context->getAction(), 'a broken lock store must not block the callback from being processed');

        $extension->onPostExecute($context);
    }

    /**
     * @test
     */
    public function it_does_not_fail_the_notify_when_releasing_the_lock_fails(): void
    {
        $store = $this->prophesize(PersistingStoreInterface::class);
        $store->save(Argument::type(Key::class))->shouldBeCalled();
        $store->putOffExpiration(Argument::type(Key::class), Argument::any())->shouldBeCalled();
        $store->delete(Argument::type(Key::class))->willThrow(new \RuntimeException('the lock store is down'));
        $store->exists(Argument::type(Key::class))->willReturn(false);

        $extension = new NotifyIdempotencyExtension(new LockFactory($store->reveal()));

        $context = $this->createContext(new Notify(new \ArrayObject(['quickpayPaymentId' => 123])));

        $extension->onPreExecute($context);
        $extension->onPostExecute($context);

        $this->addToAssertionCount(1);
    }

    private function createContext(mixed $request): Context
    {
        return new Context(new Gateway(), $request, []);
    }

    private function tryAcquire(InMemoryStore $store, int $quickpayPaymentId): bool
    {
        $lock = (new LockFactory($store))->createLock('setono_sylius_quickpay_notify_' . $quickpayPaymentId);

        if (!$lock->acquire()) {
            return false;
        }

        $lock->release();

        return true;
    }
}
