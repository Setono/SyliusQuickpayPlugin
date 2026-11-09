<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck;

use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\NotFoundException;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\Quickpay\Request\Payment\CreateLinkRequest;
use Setono\Quickpay\Request\Payment\CreatePaymentRequest;
use Setono\Quickpay\Request\Payment\PaymentsQuery;
use Setono\Quickpay\Response\Payment\Payment;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;

/**
 * The gateway checks, on the clients the client provider builds, so they follow the plugin's one HTTP policy. Every
 * request a check sends is classified once, by {@see self::ask()}: a refusal (401/403) is evidence, any other error
 * proves nothing and makes the verdict undetermined, with the error's message as the reason.
 */
final class GatewayChecks implements GatewayChecksInterface
{
    public function __construct(private readonly QuickpayClientProviderInterface $clientProvider)
    {
    }

    public function apiKey(GatewayConfiguration $gatewayConfiguration): Verdict
    {
        $client = $this->clientProvider->forGatewayConfiguration($gatewayConfiguration);

        return self::ask(
            static fn (): bool => $client->ping(),
            accepted: static fn (): Verdict => Verdict::passed(),
            // Quickpay refuses /ping both for an invalid key and for a valid key whose api user lacks the /ping
            // permission (verified live), so a one-item /payments read, a permission every integration needs, decides
            refused: static fn (): Verdict => self::ask(
                static fn () => $client->payments()->getPage(new PaymentsQuery(pageSize: 1)),
                accepted: static fn (): Verdict => Verdict::passed('verified through /payments: the api user lacks the /ping permission, which is harmless'),
                refused: static fn (): Verdict => Verdict::failed('Quickpay rejects the api key — use the API user\'s key (Settings → Users in the Quickpay manager), not a Payment Window agreement\'s'),
            ),
        );
    }

    public function agreement(GatewayConfiguration $gatewayConfiguration): Verdict
    {
        $agreementId = $gatewayConfiguration->options()->agreementId;
        if (null === $agreementId) {
            return Verdict::passed('Quickpay uses the account\'s default Payment Window agreement');
        }

        $client = $this->clientProvider->forGatewayConfiguration($gatewayConfiguration);

        return self::ask(
            static fn (): array => $client->get(sprintf('agreements/%d', $agreementId)),
            accepted: static fn (): Verdict => Verdict::passed(),
            refused: static fn (): Verdict => Verdict::undetermined('the api user may lack the /agreements permission'),
            notFound: static fn (): Verdict => Verdict::failed(sprintf('Agreement %d does not exist on this Quickpay account', $agreementId)),
        );
    }

    public function paymentLinkPermission(GatewayConfiguration $gatewayConfiguration): Verdict
    {
        $client = $this->clientProvider->forGatewayConfiguration($gatewayConfiguration);

        // The gateway's order prefix, like the store's orders on the account, and a random suffix unique per run
        $orderId = $gatewayConfiguration->options()->orderPrefix . 'dr' . bin2hex(random_bytes(3));

        return self::ask(
            static fn (): Payment => $client->payments()->create(new CreatePaymentRequest(orderId: $orderId, currency: 'DKK')),
            accepted: static fn (Payment $testPayment): Verdict => self::putLinkOn($client, $testPayment, $orderId),
            refused: static fn (): Verdict => Verdict::failed('The api user may not create payments — check the POST permission for /payments (Settings → Users → User permissions)'),
        );
    }

    /**
     * The payment link permission check's second step: puts a link on the test payment, as checkout does, then deletes
     * it again. Every verdict from here on names the test payment, which stays on the account.
     */
    private static function putLinkOn(ClientInterface $client, Payment $testPayment, string $orderId): Verdict
    {
        $note = sprintf('test payment %d with order id "%s" remains on the account — it never carries money', $testPayment->id, $orderId);

        $verdict = self::ask(
            // The link PUT checkout sends; a link needs an amount, and nobody ever pays through this one
            static fn () => $client->payments()->createLink($testPayment->id, new CreateLinkRequest(amount: 100)),
            accepted: static fn (): Verdict => Verdict::passed($note),
            refused: static fn (): Verdict => Verdict::failed('The api user may not create payment links — check the PUT checkbox for "Create or update payment link" (Settings → Users → User permissions)', $note),
            note: $note,
        );

        try {
            $client->payments()->deleteLink($testPayment->id);
        } catch (QuickpayException|\RuntimeException) {
            // Deleting the link only tidies up: the test payment stays on the account either way, so whatever
            // Quickpay answers, the verdict stands
        }

        return $verdict;
    }

    /**
     * Sends one request of a check and classifies Quickpay's answer, so a check states only what its answers mean:
     * the verdict for an accepted request, for a refusal (401/403) and, where the check knows one, for a 404. Any other
     * error proves nothing: the verdict is undetermined, with the error's message as the reason and the given note.
     * Such an error is one of the SDK's, or the PSR-7 body's \RuntimeException when an answer breaks off after its
     * headers arrived: the SDK reads the body outside its transport error handling, so that one isn't wrapped.
     *
     * The verdict closures aren't guarded by this classification: a request one of them sends goes through its own.
     *
     * @template T
     *
     * @param \Closure(): T $request
     * @param \Closure(T): Verdict $accepted
     * @param \Closure(): Verdict $refused
     * @param (\Closure(): Verdict)|null $notFound null: a 404 is any other error
     */
    private static function ask(
        \Closure $request,
        \Closure $accepted,
        \Closure $refused,
        ?\Closure $notFound = null,
        ?string $note = null,
    ): Verdict {
        try {
            $answer = $request();
        } catch (UnauthorizedException|ForbiddenException) {
            return $refused();
        } catch (NotFoundException $e) {
            if (null === $notFound) {
                return Verdict::undetermined($e->getMessage(), $note);
            }

            return $notFound();
        } catch (QuickpayException|\RuntimeException $e) {
            return Verdict::undetermined($e->getMessage(), $note);
        }

        return $accepted($answer);
    }
}
