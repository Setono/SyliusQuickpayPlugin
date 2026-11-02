<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\InvalidArgumentException;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Setono\Doctrine\ORMTrait;
use Setono\Payum\Quickpay\Details;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Webmozart\Assert\Assert;

final class PaymentReconciler implements PaymentReconcilerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ORMTrait;

    private const LOCK_KEY_PREFIX = 'setono_sylius_quickpay_payment_';

    /**
     * Comfortably longer than a reconcile takes (a couple of Quickpay reads, a transition, a flush), so the
     * lock only ever expires on a worker that died holding it
     */
    private const LOCK_TTL = 60.0;

    public function __construct(
        private readonly Payum $payum,
        private readonly StateMachineInterface $stateMachine,
        private readonly LockFactory $lockFactory,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function checkStatus(PaymentInterface $payment): StatusCheck
    {
        // Sylius' own status request, so the status maps to a payment state exactly as Sylius' payment-state
        // extension maps it. It runs on a copy of the stored details instead of the Sylius payment: the
        // extension only applies a transition after a request on a Sylius payment, and Payum's storage only
        // persists one, so neither a transition nor the details the status read refreshes reach the payment
        $targetState = self::readTargetState($this->gatewayOf($payment), new ArrayObject($payment->getDetails()));

        // The extension's own condition for applying a transition
        if ($targetState === $payment->getState() || PaymentInterface::STATE_UNKNOWN === $targetState) {
            return new StatusCheck($targetState, null);
        }

        return new StatusCheck(
            $targetState,
            $this->stateMachine->getTransitionToState($payment, PaymentTransitions::GRAPH, $targetState),
        );
    }

    public function reconcile(PaymentInterface $payment): ReconcileResult
    {
        // A status request on the Sylius payment itself, so the target state it reads is the one the extension
        // transitions the payment to
        return $this->reconcileWith($payment, static fn (GatewayInterface $gateway, PaymentInterface $payment): string => self::readTargetState($gateway, $payment));
    }

    public function reconcileFromCallback(PaymentInterface $payment): ReconcileResult
    {
        return $this->reconcileWith($payment, static function (GatewayInterface $gateway, PaymentInterface $payment): string {
            // The library validates the callback's HMAC and confirms the details from Quickpay. It runs on a
            // copy of the details, not the Sylius payment: the reply rejecting a forged callback ends the
            // request without an exception, so on the Sylius payment the extension would still apply the
            // transition and Payum's storage would persist it
            $details = new ArrayObject($payment->getDetails());
            $gateway->execute(new Notify($details));
            $payment->setDetails($details->getArrayCopy());

            // The callback is genuine: reconcile as on demand, persisting the confirmed details with the state
            return self::readTargetState($gateway, $payment);
        });
    }

    /**
     * Runs Sylius' own status request, so the Quickpay payment's status maps to the Sylius payment state
     * exactly as Sylius' payment-state extension maps it
     */
    private static function readTargetState(GatewayInterface $gateway, PaymentInterface|ArrayObject $model): string
    {
        $status = new GetStatus($model);
        $gateway->execute($status);

        // Sylius marks every status as a payment state string
        $targetState = $status->getValue();
        Assert::string($targetState);

        return $targetState;
    }

    /**
     * The reconcile sequence, whatever request triggers Sylius' payment-state extension: take the payment's
     * lock, reload the payment, run the request in a database transaction, release the lock, compare the
     * states before and after.
     *
     * @param \Closure(GatewayInterface, PaymentInterface): string $runRequest runs the request through the
     *        payment's gateway as a top-level request on the Sylius payment itself and returns the target
     *        state. After a top-level status request or Notify on a Sylius payment, the extension applies
     *        the transition and Payum's storage flushes the entity manager, both inside the transaction and
     *        while the lock is held.
     */
    private function reconcileWith(PaymentInterface $payment, \Closure $runRequest): ReconcileResult
    {
        $quickpayPaymentId = Details::paymentId(new ArrayObject($payment->getDetails()));

        // Not auto-released: the lock must span the transition and the commit and be released right after,
        // in the finally below, never left to whenever the lock object happens to be collected
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX . $quickpayPaymentId, self::LOCK_TTL, false);

        try {
            // Non-blocking: a payment someone else is reconciling (e.g. a callback being handled) is skipped
            // untouched, not reconciled a second time once they're done
            if (!$lock->acquire()) {
                return ReconcileResult::skipped(self::stateOf($payment));
            }
        } catch (LockExceptionInterface $e) {
            // Fails open: a lock outage must not stop payments being reconciled
            $this->logger?->warning(sprintf('Could not use the lock store, reconciling the payment unguarded: %s', $e->getMessage()), [
                'quickpayPaymentId' => $quickpayPaymentId,
            ]);

            $lock = null;
        }

        try {
            $manager = $this->getManager($payment);
            $payment = self::reload($manager, $payment);
            $stateBefore = self::stateOf($payment);

            // Payum's storage flushes after the request even when the request fails (a state-machine
            // callback throwing halfway through the transition, say), so the flush runs in a transaction
            // that commits only when the request succeeded. A failed reconcile saves nothing: no half-applied
            // transition that a retry would take for a reconciled payment.
            $manager->getConnection()->beginTransaction();

            try {
                $targetState = $runRequest($this->gatewayOf($payment), $payment);
                $manager->getConnection()->commit();
            } catch (\Throwable $e) {
                $this->rollBack($manager, $payment, $quickpayPaymentId);

                throw $e;
            }

            return ReconcileResult::reconciled($stateBefore, self::stateOf($payment), $targetState);
        } finally {
            if (null !== $lock) {
                self::release($lock, $quickpayPaymentId, $this->logger);
            }
        }
    }

    /**
     * Whatever was committed before the lock was taken (say, by a callback that held it a moment ago) must be
     * seen, or its transition would be applied a second time. The order goes too: the transition's callbacks
     * read and change it. A payment the entity manager no longer manages, e.g. the reconcile command's next
     * payment after a failed reconcile cleared it, is loaded afresh.
     */
    private static function reload(EntityManagerInterface $manager, PaymentInterface $payment): PaymentInterface
    {
        if ($manager->contains($payment)) {
            $manager->refresh($payment);
        } else {
            $reloaded = $manager->find($payment::class, $payment->getId());
            Assert::isInstanceOf($reloaded, PaymentInterface::class, 'The payment to reconcile no longer exists');
            $payment = $reloaded;
        }

        $order = $payment->getOrder();
        if (null !== $order) {
            $manager->refresh($order);
        }

        return $payment;
    }

    /**
     * Rolls back what the failed request flushed and leaves the entity manager usable for what comes next
     * (the reconcile command goes on with its next payment), holding the payment as committed.
     */
    private function rollBack(EntityManagerInterface $manager, PaymentInterface $payment, int $quickpayPaymentId): void
    {
        try {
            $manager->getConnection()->rollBack();
        } catch (DBALException $e) {
            // The request's own error is the one to report
            $this->logger?->error(sprintf('Could not roll back the failed reconcile: %s', $e->getMessage()), [
                'quickpayPaymentId' => $quickpayPaymentId,
            ]);
        }

        // The entity manager still holds the half-applied changes in memory. A flush that failed closed it.
        if ($manager->isOpen()) {
            $manager->clear();
        } else {
            $name = array_search($manager, $this->managerRegistry->getManagers(), true);
            $this->managerRegistry->resetManager(is_string($name) ? $name : null);

            $this->managers = [];
            $manager = $this->getManager($payment);
        }

        // When saving the payment failed, Payum's storage extension keeps it and saves it again after the next
        // Payum request in this process, in whatever entity manager is current. Detached, it would be taken
        // for a new payment and fail that request too, so it is managed again, as committed: saving it again
        // then changes nothing. This is the payment instance Payum holds, so it can only be attached, not loaded.
        try {
            $manager->getUnitOfWork()->registerManaged($payment, $manager->getClassMetadata($payment::class)->getIdentifierValues($payment), []);
            $manager->refresh($payment);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Could not reload the payment after the failed reconcile: %s', $e->getMessage()), [
                'quickpayPaymentId' => $quickpayPaymentId,
            ]);
        }
    }

    /**
     * A lock that cannot be released expires with its TTL. Throwing here would turn a reconciled payment
     * into an error, or replace the request's own error.
     */
    private static function release(LockInterface $lock, int $quickpayPaymentId, ?LoggerInterface $logger): void
    {
        try {
            $lock->release();
        } catch (LockExceptionInterface $e) {
            $logger?->warning(sprintf('Could not release the payment\'s lock: %s', $e->getMessage()), [
                'quickpayPaymentId' => $quickpayPaymentId,
            ]);
        }
    }

    private static function stateOf(PaymentInterface $payment): string
    {
        $state = $payment->getState();
        Assert::string($state);

        return $state;
    }

    private function gatewayOf(PaymentInterface $payment): GatewayInterface
    {
        $method = $payment->getMethod();
        $gatewayName = $method instanceof PaymentMethodInterface ? $method->getGatewayConfig()?->getGatewayName() : null;
        if (null === $gatewayName) {
            $id = $payment->getId();

            throw new InvalidArgumentException(sprintf(
                'Payment %s has no payment method with a gateway',
                is_scalar($id) ? (string) $id : '?',
            ));
        }

        return $this->payum->getGateway($gatewayName);
    }
}
