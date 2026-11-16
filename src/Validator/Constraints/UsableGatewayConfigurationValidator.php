<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Payum\Core\Model\GatewayConfigInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Payum\Quickpay\Exception\InvalidOrderId;
use Setono\Payum\Quickpay\OrderId;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\OrderId\LatestOrderNumberProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\Verdict;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\VerdictOutcome;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class UsableGatewayConfigurationValidator extends ConstraintValidator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly GatewayChecksInterface $gatewayChecks,
        private readonly LatestOrderNumberProviderInterface $latestOrderNumberProvider,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UsableGatewayConfiguration) {
            throw new UnexpectedTypeException($constraint, UsableGatewayConfiguration::class);
        }

        if (!$value instanceof GatewayConfigInterface) {
            throw new UnexpectedValueException($value, GatewayConfigInterface::class);
        }

        $gatewayConfiguration = GatewayConfiguration::fromGatewayConfig($value);

        try {
            $options = $gatewayConfiguration->options();
        } catch (UnusableGatewayConfiguration $e) {
            if ($e->isUnreadable()) {
                $this->context->buildViolation($constraint->unreadableMessage)
                    ->atPath('config')
                    ->addViolation()
                ;
            }

            // The admin reads, in their language, what the option accepts. payum-quickpay's reason, which says what
            // was wrong with the value, is English, so it goes to the log.
            foreach ($e->getViolations() as $option => $reason) {
                $this->context->buildViolation($constraint->optionMessages[$option] ?? $constraint->invalidOptionMessage)
                    ->setParameter('{{ option }}', $option)
                    ->atPath(sprintf('config[%s]', $option))
                    ->addViolation()
                ;

                $this->logger?->info(sprintf('Refused the gateway configuration of the Quickpay gateway "%s": %s', $e->gatewayName, $reason), [
                    'option' => $option,
                ]);
            }

            $this->validateOrderId($constraint, $gatewayConfiguration);

            // The api key is checked once the gateway would be built: a key the library rejects already has its
            // violation, and a configuration refused anyway isn't worth a round trip to Quickpay
            return;
        }

        $this->validateOrderId($constraint, $gatewayConfiguration);

        $apiKeyVerdict = $this->gatewayChecks->apiKey($gatewayConfiguration);
        $this->applyVerdict(
            $apiKeyVerdict,
            $constraint->apiKeyRejectedMessage,
            'config[api_key]',
            sprintf('Accepted the api key of the Quickpay gateway "%s", whose check is undetermined', $gatewayConfiguration->gatewayName),
            $gatewayConfiguration->gatewayName,
        );

        // Quickpay's answer about the agreement proves something only with an api key it accepts, and a rejected key
        // is one error, not two. Without an agreement id the account's default agreement is used, which needs no check.
        if (VerdictOutcome::Passed !== $apiKeyVerdict->outcome || null === $options->agreementId) {
            return;
        }

        $this->applyVerdict(
            $this->gatewayChecks->agreement($gatewayConfiguration),
            $constraint->agreementNotFoundMessage,
            'config[agreement_id]',
            sprintf('Accepted agreement %d of the Quickpay gateway "%s", whose check is undetermined', $options->agreementId, $gatewayConfiguration->gatewayName),
            $gatewayConfiguration->gatewayName,
        );
    }

    /**
     * The Quickpay order id the order prefix gives the store's latest order, by payum-quickpay's rule
     * (`OrderId::of()`), so a prefix too long for the store's order numbers, or an order number format Quickpay
     * refuses, is caught here rather than by every checkout. Without an order yet, Sylius' default first order number
     * stands in. A prefix payum-quickpay rejects already has its violation.
     */
    private function validateOrderId(UsableGatewayConfiguration $constraint, GatewayConfiguration $gatewayConfiguration): void
    {
        $orderPrefix = $gatewayConfiguration->orderPrefix();
        if (null === $orderPrefix) {
            return;
        }

        try {
            OrderId::of(
                $orderPrefix,
                $this->latestOrderNumberProvider->getLatestOrderNumber() ?? LatestOrderNumberProviderInterface::STAND_IN_ORDER_NUMBER,
            );
        } catch (InvalidOrderId $e) {
            $this->context->buildViolation($constraint->orderIdMessage)
                ->setParameter('{{ order_id }}', $e->orderId)
                ->setParameter('{{ number }}', $e->number)
                ->atPath('config[order_prefix]')
                ->addViolation()
            ;
        }
    }

    /**
     * A failed gateway check refuses the gateway configuration with the violation at the path. An undetermined one
     * fails open: Quickpay's answer proves nothing, and an unreachable Quickpay or an api user that may not ask must
     * not make the payment method unsaveable, so the reason is logged at info level instead, after the log text.
     */
    private function applyVerdict(Verdict $verdict, string $violationMessage, string $violationPath, string $logText, string $gatewayName): void
    {
        if (VerdictOutcome::Failed === $verdict->outcome) {
            $this->context->buildViolation($violationMessage)
                ->atPath($violationPath)
                ->addViolation()
            ;

            return;
        }

        if (VerdictOutcome::Undetermined === $verdict->outcome) {
            $this->logger?->info(sprintf('%s: %s', $logText, (string) $verdict->reason), ['gatewayName' => $gatewayName]);
        }
    }
}
