<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * Why a reconcile run lists a Quickpay account's changes since when it does ({@see AccountWindow}): since the
 * account's watermark, or, for any of the other reasons, since the lookback
 */
enum WindowReason
{
    /** The previous run that wrote listed the account: since its watermark */
    case SinceLastRun;

    /** No run listed the account before (the first run, a cleared cache, a new api key): since the lookback */
    case NoEarlierRun;

    /** The account's watermark is older than the lookback, so it is clamped to it */
    case LastRunTooLongAgo;

    /** The run is a full one, which ignores the watermarks: since the lookback */
    case Full;
}
