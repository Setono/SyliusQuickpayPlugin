<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Checkout;

use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * Derives the checkout presentation from what the payment window will actually offer: the gateway
 * configuration's `payment_methods` option (a comma-separated list of Quickpay payment method
 * tokens, see https://learn.quickpay.net/tech-talk/appendixes/payment-methods/). Nothing is
 * stored and no API is called — the option already says which methods the customer will see.
 *
 * Token handling follows Quickpay's own grammar: exclusions (`!diners`) are skipped, the `3d-`
 * prefix (forced 3-D Secure) is ignored, and regional/debit variants (`visa-dk`,
 * `mastercard-debet-dk`, `mobilepay-subscriptions`) fall back to their base brand. `creditcard`,
 * which means "every card enabled on the agreement", expands to a configurable list of brands.
 */
final class PaymentMethodLogoProvider implements PaymentMethodLogoProviderInterface
{
    private const IMAGE_PATH = 'bundles/setonosyliusquickpayplugin/images/payment-methods/%s.svg';

    /**
     * Quickpay token => [label, bundled image stem or null]
     */
    private const BUILT_IN = [
        'visa' => ['Visa', 'visa'],
        'visa-electron' => ['Visa Electron', 'visa-electron'],
        'mastercard' => ['Mastercard', 'mastercard'],
        'maestro' => ['Maestro', 'maestro'],
        'american-express' => ['American Express', 'american-express'],
        'diners' => ['Diners Club', 'diners-club'],
        'discover' => ['Discover', 'discover'],
        'jcb' => ['JCB', 'jcb'],
        'unionpay' => ['UnionPay', 'unionpay'],
        'dankort' => ['Dankort', 'dankort'],
        'fbg1886' => ['Forbrugsforeningen', 'forbrugsforeningen'],
        'mobilepay' => ['MobilePay', 'mobilepay'],
        'apple-pay' => ['Apple Pay', 'apple-pay'],
        'google-pay' => ['Google Pay', 'google-pay'],
        'klarna-payments' => ['Klarna', 'klarna'],
        'klarna' => ['Klarna', 'klarna'],
        'anyday' => ['Anyday', 'anyday'],
        'vipps' => ['Vipps', 'vipps'],
        'swish' => ['Swish', 'swish'],
        'paypal' => ['PayPal', 'paypal'],
        'viabill' => ['ViaBill', 'viabill'],
        'trustly' => ['Trustly', 'trustly'],
        'ideal' => ['iDEAL', 'ideal'],
        'sofort' => ['Sofort', 'sofort'],
        'paysafecard' => ['paysafecard', 'paysafecard'],
        'resurs' => ['Resurs Bank', null],
    ];

    /**
     * @param array<string, string|null> $images token => image path (`asset()`-compatible) to add or
     *                                            override a logo, or null to hide the token entirely
     * @param list<string> $creditcardBrands the brands `creditcard` stands for
     */
    public function __construct(
        private readonly array $images = [],
        private readonly array $creditcardBrands = ['visa', 'mastercard'],
    ) {
    }

    public function provide(PaymentMethodInterface $paymentMethod): array
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface || QuickpayGatewayFactory::NAME !== $gatewayConfig->getFactoryName()) {
            return [];
        }

        $paymentMethods = $gatewayConfig->getConfig()['payment_methods'] ?? null;
        if (!is_string($paymentMethods)) {
            return [];
        }

        $logos = [];
        foreach ($this->tokens($paymentMethods) as $token) {
            $logo = $this->logo($token);
            if (null !== $logo && !isset($logos[$logo->token])) {
                $logos[$logo->token] = $logo;
            }
        }

        return array_values($logos);
    }

    /**
     * @return list<string> the base tokens the option resolves to, in order
     */
    private function tokens(string $paymentMethods): array
    {
        $tokens = [];
        foreach (explode(',', strtolower($paymentMethods)) as $raw) {
            $token = trim($raw);
            if ('' === $token || str_starts_with($token, '!')) {
                continue;
            }

            if (str_starts_with($token, '3d-')) {
                $token = substr($token, 3);
            }

            if ('creditcard' === $token) {
                array_push($tokens, ...$this->creditcardBrands);

                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    private function logo(string $token): ?PaymentMethodLogo
    {
        $base = $this->base($token);

        // An explicit configuration wins: an image adds/overrides, null hides
        if (array_key_exists($base, $this->images)) {
            $image = $this->images[$base];
            if (null === $image) {
                return null;
            }

            return new PaymentMethodLogo($base, self::BUILT_IN[$base][0] ?? self::humanize($base), $image);
        }

        if (isset(self::BUILT_IN[$base])) {
            [$label, $stem] = self::BUILT_IN[$base];

            return new PaymentMethodLogo($base, $label, null === $stem ? null : sprintf(self::IMAGE_PATH, $stem));
        }

        return new PaymentMethodLogo($base, self::humanize($base), null);
    }

    /**
     * Reduces a token to the brand it stands for: `visa-dk` and `mastercard-debet-dk` are still
     * Visa and Mastercard, `mobilepay-subscriptions` is still MobilePay. Suffixes are only stripped
     * while the token is unknown, so `apple-pay` and `visa-electron` keep their own identity.
     */
    private function base(string $token): string
    {
        $candidate = $token;
        while (true) {
            if (isset(self::BUILT_IN[$candidate]) || array_key_exists($candidate, $this->images)) {
                return $candidate;
            }

            $pos = strrpos($candidate, '-');
            if (false === $pos) {
                // Nothing known at any length: keep the token as Quickpay spells it
                return $token;
            }

            $candidate = substr($candidate, 0, $pos);
        }
    }

    private static function humanize(string $token): string
    {
        return ucwords(str_replace('-', ' ', $token));
    }
}
