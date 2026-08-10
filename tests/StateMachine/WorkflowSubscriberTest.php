<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\StateMachine;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessorInterface;
use Setono\SyliusQuickpayPlugin\StateMachine\WorkflowSubscriber;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

final class WorkflowSubscriberTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_subscribes_to_the_forwarded_payment_transitions(): void
    {
        self::assertSame([
            'workflow.sylius_payment.transition.complete' => 'process',
            'workflow.sylius_payment.transition.refund' => 'process',
            'workflow.sylius_payment.transition.cancel' => 'process',
        ], WorkflowSubscriber::getSubscribedEvents());
    }

    /**
     * @test
     */
    public function it_forwards_the_transition_to_the_payment_processor(): void
    {
        $payment = $this->prophesize(PaymentInterface::class)->reveal();

        $paymentProcessor = $this->prophesize(PaymentProcessorInterface::class);
        $paymentProcessor->__invoke($payment, 'complete')->shouldBeCalledOnce();

        $event = new TransitionEvent($payment, new Marking(), new Transition('complete', 'authorized', 'completed'));

        (new WorkflowSubscriber($paymentProcessor->reveal()))->process($event);
    }

    /**
     * @test
     */
    public function it_ignores_subjects_that_are_not_payments(): void
    {
        $paymentProcessor = $this->prophesize(PaymentProcessorInterface::class);
        $paymentProcessor->__invoke(Argument::cetera())->shouldNotBeCalled();

        $event = new TransitionEvent(new \stdClass(), new Marking(), new Transition('complete', 'authorized', 'completed'));

        (new WorkflowSubscriber($paymentProcessor->reveal()))->process($event);
    }

    /**
     * @test
     */
    public function it_ignores_events_without_a_transition(): void
    {
        $payment = $this->prophesize(PaymentInterface::class)->reveal();

        $paymentProcessor = $this->prophesize(PaymentProcessorInterface::class);
        $paymentProcessor->__invoke(Argument::cetera())->shouldNotBeCalled();

        $event = new TransitionEvent($payment, new Marking());

        (new WorkflowSubscriber($paymentProcessor->reveal()))->process($event);
    }
}
