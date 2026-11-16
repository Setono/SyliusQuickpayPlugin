<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * A reconcile run that writes was started while another one holds the run lock: it lists nothing and reconciles
 * nothing, since the run going on does the work
 */
final class AnotherRunIsReconciling extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another run is reconciling payments.');
    }
}
