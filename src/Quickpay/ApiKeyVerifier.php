<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\Quickpay\Request\Payment\PaymentsQuery;

final class ApiKeyVerifier implements ApiKeyVerifierInterface
{
    public function __construct(private readonly ClientFactoryInterface $clientFactory)
    {
    }

    public function verify(string $apiKey): bool
    {
        $client = $this->clientFactory->create($apiKey);

        try {
            $client->ping();

            return true;
        } catch (UnauthorizedException|ForbiddenException) {
            // Either an invalid key or a valid key without the /ping permission — /payments decides
        }

        $client->payments()->getPage(new PaymentsQuery(pageSize: 1));

        return false;
    }
}
