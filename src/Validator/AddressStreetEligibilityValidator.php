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
    /** @var StreetEligibilityCheckerInterface */
    private $streetEligibilityChecker;

    public function __construct(StreetEligibilityCheckerInterface $streetEligibilityChecker)
    {
        $this->streetEligibilityChecker = $streetEligibilityChecker;
    }

    public function validate($address, Constraint $constraint): void
    {
        Assert::isInstanceOf($address, AddressInterface::class);
        Assert::isInstanceOf($constraint, AddressStreetEligibility::class);

        if (!$this->streetEligibilityChecker->isEligible($address)) {
            $this->context
                ->buildViolation($constraint->message)
                ->atPath('street')
                ->addViolation()
            ;
        }
    }
}
