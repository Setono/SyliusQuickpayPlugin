<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Diagnosis;

use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;

/**
 * Diagnoses a gateway configuration (see GLOSSARY.md, Diagnosis): everything the plugin can find out about whether it
 * works, as findings. The doctor prints them, and save validation refuses a configuration with a failed one.
 */
interface DiagnoserInterface
{
    /**
     * The findings in the order the checks depend on each other: what payum-quickpay rejects, the stored options that
     * surprise, the order id, then the checks against Quickpay, which run only on a configuration the gateway can be
     * built from. The agreement and the payment link permission are checked only once Quickpay accepts the api key.
     * An unusable configuration is diagnosed, not thrown.
     *
     * @param bool $live whether to check the payment link permission too, which leaves a money-less test payment on
     *                   the Quickpay account
     *
     * @return list<Finding>
     */
    public function diagnose(GatewayConfiguration $gatewayConfiguration, bool $live = false): array;
}
