<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\UnauthorizedException;

interface ApiKeyVerifierInterface
{
    /**
     * Verifies the api key against Quickpay and tells how it was verified. Quickpay answers 401 on
     * /ping both for an invalid key and for a valid key whose api user merely lacks the /ping
     * permission (verified live), so a rejected ping falls back to a one-item /payments read — a
     * permission every integration needs.
     *
     * @throws UnauthorizedException|ForbiddenException when Quickpay rejects the key
     * @throws \Throwable when Quickpay did not answer, so nothing is proven either way
     */
    public function verify(string $apiKey): ApiKeyVerification;
}
