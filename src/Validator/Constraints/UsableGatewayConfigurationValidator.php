<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Payum\Core\Model\GatewayConfigInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifierInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class UsableGatewayConfigurationValidator extends ConstraintValidator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(private readonly ApiKeyVerifierInterface $apiKeyVerifier)
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

        try {
            $options = GatewayConfiguration::fromGatewayConfig($value)->options();
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

            // The api key is verified once the gateway would be built: a key the library rejects already has its
            // violation, and a configuration refused anyway isn't worth a round trip to Quickpay
            return;
        }

        try {
            $this->apiKeyVerifier->verify($options->apiKey);
        } catch (UnauthorizedException|ForbiddenException) {
            $this->context->buildViolation($constraint->apiKeyRejectedMessage)
                ->atPath('config[api_key]')
                ->addViolation()
            ;
        } catch (\Throwable) {
            // Fail open: an unreachable Quickpay must not make the payment method unsaveable, only an explicit
            // rejection of the key counts as a rejected api key
        }
    }
}
