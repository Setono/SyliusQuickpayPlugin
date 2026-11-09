<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Payum\Core\Model\GatewayConfigInterface;
use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifierInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class UsableGatewayConfigurationValidator extends ConstraintValidator
{
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
            // Unreadable as a whole, as an encrypted configuration is
            if ([] === $e->getViolations()) {
                $this->context->buildViolation($constraint->unreadableMessage)
                    ->atPath('config')
                    ->addViolation()
                ;
            }

            // What an option means, and so what is wrong with it, is payum-quickpay's to say: its per-option messages
            // pass through as the library's English, untranslated
            foreach ($e->getViolations() as $option => $message) {
                $this->context->buildViolation($message)
                    ->disableTranslation()
                    ->atPath(sprintf('config[%s]', $option))
                    ->addViolation()
                ;
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
