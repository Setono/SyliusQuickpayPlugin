<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Payum\Core\Model\GatewayConfigInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\SyliusQuickpayPlugin\Diagnosis\DiagnoserInterface;
use Setono\SyliusQuickpayPlugin\Diagnosis\Finding;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingKind;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingOutcome;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Refuses a gateway configuration whose diagnosis has a failed finding, with a violation in the admin's language on the
 * field the finding is about. The diagnosis' English texts go to the log, every finding that didn't pass at info
 * level, so what the admin saw, and what was only a warning, can be read there.
 */
final class UsableGatewayConfigurationValidator extends ConstraintValidator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(private readonly DiagnoserInterface $diagnoser)
    {
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

        // Never live: saving must not leave a test payment on the Quickpay account
        foreach ($this->diagnoser->diagnose($gatewayConfiguration, live: false) as $finding) {
            if (FindingOutcome::Passed === $finding->outcome) {
                continue;
            }

            $this->log($gatewayConfiguration->gatewayName, $finding);

            if (FindingOutcome::Failed === $finding->outcome) {
                $this->refuse($constraint, $finding);
            }
        }
    }

    /**
     * The violation of a failed finding: the plugin's own message, in the admin's language, on the field it is about
     */
    private function refuse(UsableGatewayConfiguration $constraint, Finding $finding): void
    {
        $option = (string) $finding->option;

        [$message, $path, $parameters] = match ($finding->kind) {
            FindingKind::Unreadable => [$constraint->unreadableMessage, 'config', []],
            // The admin reads what the option accepts; payum-quickpay's reason, which says what was wrong with the
            // value, is English, and logged
            FindingKind::RejectedOption => [
                $constraint->optionMessages[$option] ?? $constraint->invalidOptionMessage,
                sprintf('config[%s]', $option),
                ['{{ option }}' => $option],
            ],
            FindingKind::OrderId => [
                $constraint->orderIdMessage,
                'config[order_prefix]',
                ['{{ order_id }}' => (string) $finding->orderId, '{{ number }}' => (string) $finding->orderNumber],
            ],
            FindingKind::ApiKey => [$constraint->apiKeyRejectedMessage, 'config[api_key]', []],
            FindingKind::Agreement => [$constraint->agreementNotFoundMessage, 'config[agreement_id]', []],
            default => throw new \LogicException(sprintf('Save validation has no message for a failed finding of the kind "%s"', $finding->kind->value)),
        };

        $violation = $this->context->buildViolation($message)->atPath($path);
        foreach ($parameters as $parameter => $parameterValue) {
            $violation->setParameter($parameter, $parameterValue);
        }

        $violation->addViolation();
    }

    private function log(string $gatewayName, Finding $finding): void
    {
        $context = ['gatewayName' => $gatewayName, 'kind' => $finding->kind->value];
        if (null !== $finding->option) {
            $context['option'] = $finding->option;
        }

        $this->logger?->info(sprintf(
            FindingOutcome::Failed === $finding->outcome
                ? 'Refused the gateway configuration of the Quickpay gateway "%s": %s'
                : 'A warning about the gateway configuration of the Quickpay gateway "%s": %s',
            $gatewayName,
            $finding->text,
        ), $context);
    }
}
