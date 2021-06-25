<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Validator;

use Setono\SyliusQuickpayPlugin\Checker\StreetEligibilityCheckerInterface;
use Setono\SyliusQuickpayPlugin\Validator\Constraints\AddressStreetEligibility;
use Sylius\Component\Addressing\Model\AddressInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class AddressStreetEligibilityValidator extends ConstraintValidator
{
    private StreetEligibilityCheckerInterface $streetEligibilityChecker;

    public function __construct(StreetEligibilityCheckerInterface $streetEligibilityChecker)
    {
        $this->streetEligibilityChecker = $streetEligibilityChecker;
    }

    public function validate($value, Constraint $constraint): void
    {
        Assert::isInstanceOf($value, AddressInterface::class);
        Assert::isInstanceOf($constraint, AddressStreetEligibility::class);

        if (!$this->streetEligibilityChecker->isEligible($value)) {
            $this->context
                ->buildViolation($constraint->message)
                ->atPath('street')
                ->addViolation()
            ;
        }
    }
}
