<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator\Constraints;

use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifierInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class QuickpayCredentialsValidator extends ConstraintValidator
{
    public function __construct(private readonly ApiKeyVerifierInterface $apiKeyVerifier)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof QuickpayCredentials) {
            throw new UnexpectedTypeException($constraint, QuickpayCredentials::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        try {
            $this->apiKeyVerifier->verify($value);
        } catch (UnauthorizedException|ForbiddenException) {
            $this->context->buildViolation($constraint->message)->addViolation();
        } catch (\Throwable) {
            // Fail open: an unreachable Quickpay must not make the payment method unsaveable —
            // only an explicit rejection of the key counts as invalid credentials
        }
    }
}
