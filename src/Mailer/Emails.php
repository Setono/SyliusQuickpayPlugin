<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Mailer;

/**
 * The email codes the plugin registers with the Sylius mailer, mirroring
 * {@see \Sylius\Bundle\CoreBundle\Mailer\Emails}.
 */
interface Emails
{
    public const PAYMENT_LINK = 'setono_sylius_quickpay_payment_link';
}
