<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

/**
 * How an api key was verified. Both cases mean the key is valid — ViaPayments additionally tells
 * that the api user lacks the /ping permission, which is harmless but worth surfacing in
 * diagnostics. A key that could not be verified never yields a verification: the verifier throws.
 */
enum ApiKeyVerification
{
    case ViaPing;
    case ViaPayments;
}
