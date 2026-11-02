<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Doctrine\Persistence\ManagerRegistry;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\InvalidArgumentException;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
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
        $status = new GetStatus(new ArrayObject($payment->getDetails()));
        $this->gatewayOf($payment)->execute($status);

        // Sylius marks every status as a payment state string
        $quickpayStatus = $status->getValue();
        Assert::string($quickpayStatus);

        // The extension's own condition for applying a transition
        if ($quickpayStatus === $payment->getState() || PaymentInterface::STATE_UNKNOWN === $quickpayStatus) {
            return new StatusCheck($quickpayStatus, null);
        }

        return new StatusCheck(
            $quickpayStatus,
            $this->stateMachine->getTransitionToState($payment, PaymentTransitions::GRAPH, $quickpayStatus),
        );
    }

    public function reconcile(PaymentInterface $payment): ReconcileResult
    {
        return $this->reconcileWith($payment, static function (GatewayInterface $gateway, PaymentInterface $payment): string {
            // Sylius' own status request, so the status reads exactly as the extension maps it
            $status = new GetStatus($payment);
            $gateway->execute($status);

            $quickpayStatus = $status->getValue();
            Assert::string($quickpayStatus);

            return $quickpayStatus;
        });
    }

    /**
     * The reconcile sequence, whatever request triggers Sylius' payment-state extension: take the payment's
     * lock, reload the payment, run the request, release the lock, compare the states before and after.
     *
     * @param \Closure(GatewayInterface, PaymentInterface): string $runRequest runs the request through the
     *        payment's gateway as a top-level request on the Sylius payment itself and returns the Quickpay
     *        status. After a top-level status request or Notify on a Sylius payment, the extension applies
     *        the transition and Payum's storage flushes the entity manager, both while the lock is held.
     */
    private function reconcileWith(PaymentInterface $payment, \Closure $runRequest): ReconcileResult
    {
        $quickpayPaymentId = Details::paymentId(new ArrayObject($payment->getDetails()));

        // Not auto-released: the lock must span the transition and the flush and be released right after,
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
            // Whatever was committed before the lock was taken (say, by a callback that held it a moment
            // ago) must be seen, or its transition would be applied a second time. The order goes too: the
            // transition's callbacks read and change it.
            $manager = $this->getManager($payment);
            $manager->refresh($payment);
            $order = $payment->getOrder();
            if (null !== $order) {
                $manager->refresh($order);
            }

            $stateBefore = self::stateOf($payment);
            $quickpayStatus = $runRequest($this->gatewayOf($payment), $payment);

            return ReconcileResult::reconciled($stateBefore, self::stateOf($payment), $quickpayStatus);
        } finally {
            if (null !== $lock) {
                self::release($lock, $quickpayPaymentId, $this->logger);
            }
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
