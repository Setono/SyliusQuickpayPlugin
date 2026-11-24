<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Diagnosis;

use Setono\Payum\Quickpay\Exception\InvalidOrderId;
use Setono\Payum\Quickpay\OrderId;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\OrderId\LatestOrderNumberProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\Verdict;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\VerdictOutcome;

final class Diagnoser implements DiagnoserInterface
{
    public function __construct(
        private readonly GatewayChecksInterface $gatewayChecks,
        private readonly LatestOrderNumberProviderInterface $latestOrderNumberProvider,
    ) {
    }

    public function diagnose(GatewayConfiguration $gatewayConfiguration, bool $live = false): array
    {
        try {
            $options = $gatewayConfiguration->options();
        } catch (UnusableGatewayConfiguration $e) {
            return $this->diagnoseUnusable($gatewayConfiguration, $e);
        }

        $findings = [
            ...self::storedOptionWarnings($gatewayConfiguration),
            ...$this->orderIdFindings($gatewayConfiguration->orderPrefix()),
        ];

        // The checks below that ask Quickpay need an api key it accepts, or their answers would prove nothing
        $apiKeyVerdict = $this->gatewayChecks->apiKey($gatewayConfiguration);
        $findings[] = self::fromVerdict(
            FindingKind::ApiKey,
            $apiKeyVerdict,
            'The api key is accepted by Quickpay',
            'Could not check the api key',
        );
        $apiKeyAccepted = VerdictOutcome::Passed === $apiKeyVerdict->outcome;

        $findings[] = $this->agreementFinding($gatewayConfiguration, $options->agreementId, $apiKeyAccepted);

        if ($live) {
            $findings[] = $this->paymentLinkPermissionFinding($gatewayConfiguration, $apiKeyAccepted);
        }

        return $findings;
    }

    /**
     * One failed finding per gateway option the gateway rejects, and the order id, which needs no gateway. The checks
     * against Quickpay are skipped: the gateway can't be built, and their errors would only follow from the
     * configuration's.
     *
     * @return list<Finding>
     */
    private function diagnoseUnusable(GatewayConfiguration $gatewayConfiguration, UnusableGatewayConfiguration $unusable): array
    {
        $skipped = new Finding(
            FindingKind::QuickpayChecksSkipped,
            FindingOutcome::Warning,
            'Skipped the checks against Quickpay of this gateway — they need a gateway configuration the gateway can be built from',
        );

        // Nothing can be said about the stored options of an unreadable configuration
        if ($unusable->isUnreadable()) {
            return [
                new Finding(FindingKind::Unreadable, FindingOutcome::Failed, sprintf('%s See the README\'s Configuration section.', $unusable->getMessage())),
                $skipped,
            ];
        }

        $findings = [];
        foreach ($unusable->getViolations() as $option => $reason) {
            $findings[] = new Finding(FindingKind::RejectedOption, FindingOutcome::Failed, $reason, option: $option);
        }

        return [
            ...$findings,
            ...self::storedOptionWarnings($gatewayConfiguration),
            ...$this->orderIdFindings($gatewayConfiguration->orderPrefix()),
            $skipped,
        ];
    }

    /**
     * What the stored configuration says that works but surprises people, whether or not the gateway can be built
     * from it
     *
     * @return list<Finding>
     */
    private static function storedOptionWarnings(GatewayConfiguration $gatewayConfiguration): array
    {
        $findings = [];

        if ($gatewayConfiguration->usesDeprecatedOptions()) {
            $findings[] = new Finding(
                FindingKind::DeprecatedOptions,
                FindingOutcome::Warning,
                'The gateway configuration uses a deprecated gateway option (a 1.x option name, or auto_capture) — re-save the payment method in the admin to replace it before 3.0 stops reading it',
            );
        }

        if (!$gatewayConfiguration->isCaptureModeSet()) {
            $findings[] = new Finding(
                FindingKind::CaptureModeNotSet,
                FindingOutcome::Warning,
                'The capture mode is not set, so checkout captures immediately — choose a capture mode on the payment method in the admin',
            );
        }

        return $findings;
    }

    /**
     * The Quickpay order id the order prefix gives the store's latest order, by payum-quickpay's rule
     * (`OrderId::of()`), so an order number format of the store's own counts too. Without an order yet, Sylius'
     * default first order number stands in. None for an order prefix payum-quickpay rejects, which its violation
     * already reports.
     *
     * @return list<Finding>
     */
    private function orderIdFindings(?string $orderPrefix): array
    {
        if (null === $orderPrefix) {
            return [];
        }

        $latestOrderNumber = $this->latestOrderNumberProvider->getLatestOrderNumber();
        $orderNumber = $latestOrderNumber ?? LatestOrderNumberProviderInterface::STAND_IN_ORDER_NUMBER;

        try {
            $orderId = OrderId::of($orderPrefix, $orderNumber);
        } catch (InvalidOrderId $e) {
            return [new Finding(
                FindingKind::OrderId,
                FindingOutcome::Failed,
                sprintf(
                    '%s %s, and checkout fails until the two fit.',
                    $e->getMessage(),
                    null === $latestOrderNumber
                        ? 'There are no orders yet, so the number is Sylius\' default first order number'
                        : 'The number is the latest order\'s',
                ),
                orderId: $e->orderId,
                orderNumber: $e->number,
            )];
        }

        $findings = [new Finding(
            FindingKind::OrderId,
            FindingOutcome::Passed,
            null === $latestOrderNumber
                ? sprintf('No orders yet: a Sylius order number like "%s" gives the order id "%s", which Quickpay accepts', $orderNumber, $orderId)
                : sprintf('The order id of the latest order, "%s", is one Quickpay accepts', $orderId),
            orderId: $orderId,
            orderNumber: $orderNumber,
        )];

        if ('' === $orderPrefix) {
            $findings[] = new Finding(
                FindingKind::NoOrderPrefix,
                FindingOutcome::Passed,
                'No order prefix configured — make sure order numbers are unique on the Quickpay account',
            );
        }

        return $findings;
    }

    /**
     * The agreement the gateway sends. Without one there is nothing to ask Quickpay, which uses the account's default
     * agreement, so that passes whatever the api key's verdict.
     */
    private function agreementFinding(GatewayConfiguration $gatewayConfiguration, ?int $agreementId, bool $apiKeyAccepted): Finding
    {
        if (null === $agreementId) {
            return new Finding(
                FindingKind::Agreement,
                FindingOutcome::Passed,
                'No agreement id configured — Quickpay uses the account\'s default Payment Window agreement',
            );
        }

        if (!$apiKeyAccepted) {
            return new Finding(
                FindingKind::Agreement,
                FindingOutcome::Warning,
                sprintf('Skipped checking agreement %d — no usable api key', $agreementId),
                agreementId: $agreementId,
            );
        }

        return self::fromVerdict(
            FindingKind::Agreement,
            $this->gatewayChecks->agreement($gatewayConfiguration),
            sprintf('Agreement %d exists on the account', $agreementId),
            sprintf('Could not check agreement %d', $agreementId),
            $agreementId,
        );
    }

    /**
     * The one check that writes to the account (a money-less test payment, which the verdict's note names), surfacing
     * the "Not authorized to PUT /payments/:id/link" case before a customer does
     */
    private function paymentLinkPermissionFinding(GatewayConfiguration $gatewayConfiguration, bool $apiKeyAccepted): Finding
    {
        if (!$apiKeyAccepted) {
            return new Finding(
                FindingKind::PaymentLinkPermission,
                FindingOutcome::Warning,
                'Skipped checking the payment link permission — no usable api key',
            );
        }

        return self::fromVerdict(
            FindingKind::PaymentLinkPermission,
            $this->gatewayChecks->paymentLinkPermission($gatewayConfiguration),
            'The api user may create payment links',
            'Could not check the payment link permission',
        );
    }

    /**
     * A gateway check's verdict as a finding: passed with what a pass means, failed with the verdict's reason, and an
     * undetermined verdict as a warning saying what couldn't be found out and why, each with the verdict's note
     *
     * @param string $passed what a pass means
     * @param string $undetermined what couldn't be found out, followed by the reason Quickpay's answer proves nothing
     */
    private static function fromVerdict(
        FindingKind $kind,
        Verdict $verdict,
        string $passed,
        string $undetermined,
        ?int $agreementId = null,
    ): Finding {
        $note = null === $verdict->note ? '' : sprintf(' (%s)', $verdict->note);

        [$outcome, $text] = match ($verdict->outcome) {
            VerdictOutcome::Passed => [FindingOutcome::Passed, $passed . $note],
            VerdictOutcome::Failed => [FindingOutcome::Failed, $verdict->reason . $note],
            VerdictOutcome::Undetermined => [FindingOutcome::Warning, sprintf('%s: %s%s', $undetermined, $verdict->reason, $note)],
        };

        return new Finding($kind, $outcome, $text, agreementId: $agreementId);
    }
}
