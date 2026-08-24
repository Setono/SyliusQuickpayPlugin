<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Payum\Extension;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Extension\Context;
use Payum\Core\Extension\ExtensionInterface;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Serializes concurrent notify handling per Quickpay payment. Quickpay retries callbacks, and a retry
 * can race the original delivery (or the customer's browser return): whichever callback arrives while
 * another is being processed for the same payment is acknowledged without reprocessing, by resolving
 * the request to a no-op action, so the notify endpoints still answer 2xx and Quickpay stops retrying.
 *
 * Both notify entry points funnel through this extension: it matches the Notify request whose model is
 * the payment details (the shape the gateway's NotifyAction handles), which Sylius'
 * ExecuteSameRequestWithPaymentDetailsAction produces exactly once per dispatch — for the per-payment
 * token endpoint and the plugin's shared endpoint alike.
 *
 * The lock is deliberately not paired with a persisted processing flag in the payment details: the
 * lock's TTL self-heals after a crashed worker, while a persisted flag would wedge the payment. A
 * broken lock store fails open — the callback is processed unguarded, as before this extension.
 */
final class NotifyIdempotencyExtension implements ExtensionInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const LOCK_KEY_PREFIX = 'setono_sylius_quickpay_notify_';

    /**
     * Locks held while a notify dispatch is being processed, keyed by the context that acquired them
     * so onPostExecute releases the right one on every exit path
     *
     * @var \SplObjectStorage<Context, LockInterface>
     */
    private \SplObjectStorage $locks;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly float $ttl = 60.0,
    ) {
        $this->locks = new \SplObjectStorage();
    }

    public function onPreExecute(Context $context): void
    {
        $quickpayPaymentId = self::resolveQuickpayPaymentId($context->getRequest());
        if (null === $quickpayPaymentId) {
            return;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX . $quickpayPaymentId, $this->ttl);

        try {
            $acquired = $lock->acquire();
        } catch (LockExceptionInterface $e) {
            $this->logger?->warning(sprintf('Could not use the lock store for Quickpay callbacks, handling the callback unguarded: %s', $e->getMessage()), [
                'quickpayPaymentId' => $quickpayPaymentId,
            ]);

            return;
        }

        if (!$acquired) {
            $this->logger?->info('A callback for this Quickpay payment is already being processed, acknowledging this one without processing it', [
                'quickpayPaymentId' => $quickpayPaymentId,
            ]);

            $context->setAction(new class() implements ActionInterface {
                /**
                 * @param mixed $request
                 */
                public function execute($request): void
                {
                }

                /**
                 * @param mixed $request
                 */
                public function supports($request): bool
                {
                    return $request instanceof Notify;
                }
            });

            return;
        }

        $this->locks[$context] = $lock;
    }

    public function onExecute(Context $context): void
    {
    }

    public function onPostExecute(Context $context): void
    {
        if (!isset($this->locks[$context])) {
            return;
        }

        $lock = $this->locks[$context];
        unset($this->locks[$context]);

        try {
            $lock->release();
        } catch (LockExceptionInterface $e) {
            $this->logger?->warning(sprintf('Could not release the Quickpay callback lock: %s', $e->getMessage()));
        }
    }

    private static function resolveQuickpayPaymentId(mixed $request): ?int
    {
        if (!$request instanceof Notify) {
            return null;
        }

        $model = $request->getModel();
        if (!$model instanceof \ArrayAccess) {
            return null;
        }

        $quickpayPaymentId = $model['quickpayPaymentId'] ?? null;

        return is_numeric($quickpayPaymentId) ? (int) $quickpayPaymentId : null;
    }
}
