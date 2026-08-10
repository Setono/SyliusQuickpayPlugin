<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Feeds the payment processor when the sylius_payment graph runs on the symfony_workflow
 * adapter — the counterpart of the winzou before-callback prepended by the bundle extension.
 * A transition is applied through exactly one adapter and only that adapter dispatches its
 * events, so registering both hooks never processes a payment twice.
 */
final class WorkflowSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PaymentProcessorInterface $paymentProcessor)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            sprintf('workflow.%s.transition.%s', PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE) => 'process',
            sprintf('workflow.%s.transition.%s', PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND) => 'process',
            sprintf('workflow.%s.transition.%s', PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL) => 'process',
        ];
    }

    public function process(TransitionEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $transition = $event->getTransition()?->getName();
        if (null === $transition) {
            return;
        }

        ($this->paymentProcessor)($payment, $transition);
    }
}
