<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck;

use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\Quickpay\Request\Payment\PaymentsQuery;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;

/**
 * The gateway checks, on the clients the client provider builds, so they follow the plugin's one HTTP policy. Each
 * classifies Quickpay's answers once: a refusal (401/403) is evidence, any other error of the SDK proves nothing and
 * makes the verdict undetermined, with the error's message as the reason.
 */
final class GatewayChecks implements GatewayChecksInterface
{
    public function __construct(private readonly QuickpayClientProviderInterface $clientProvider)
    {
    }

    public function apiKey(GatewayConfiguration $gatewayConfiguration): Verdict
    {
        $client = $this->clientProvider->forGatewayConfiguration($gatewayConfiguration);

        try {
            $client->ping();

            return Verdict::passed();
        } catch (UnauthorizedException|ForbiddenException) {
            // Quickpay refuses /ping both for an invalid key and for a valid key whose api user lacks the /ping
            // permission (verified live), so a one-item /payments read, a permission every integration needs, decides
        } catch (QuickpayException $e) {
            return Verdict::undetermined($e->getMessage());
        }

        try {
            $client->payments()->getPage(new PaymentsQuery(pageSize: 1));
        } catch (UnauthorizedException|ForbiddenException) {
            return Verdict::failed('Quickpay rejects the api key — use the API user\'s key (Settings → Users in the Quickpay manager), not a Payment Window agreement\'s');
        } catch (QuickpayException $e) {
            return Verdict::undetermined($e->getMessage());
        }

        return Verdict::passed('verified through /payments: the api user lacks the /ping permission, which is harmless');
    }
}
