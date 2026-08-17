<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Twig;

use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogoProvider;
use Setono\SyliusQuickpayPlugin\Twig\PaymentMethodLogoExtension;
use Setono\SyliusQuickpayPlugin\Twig\PaymentMethodLogoRuntime;
use Twig\Extension\ExtensionInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;
use Twig\Test\IntegrationTestCase;

/**
 * Renders the fixtures in Fixtures/PaymentMethodLogo through a real Twig environment with the
 * extension, its runtime and the real provider — see the .test files for the cases.
 */
final class PaymentMethodLogoExtensionTest extends IntegrationTestCase
{
    // Twig < 3.13 (the lowest supported version is 2.15) asks for the fixtures through this method
    protected function getFixturesDir(): string
    {
        return self::getFixturesDirectory();
    }

    protected static function getFixturesDirectory(): string
    {
        return __DIR__ . '/Fixtures/PaymentMethodLogo';
    }

    /**
     * @return list<ExtensionInterface>
     */
    protected function getExtensions(): array
    {
        return [new PaymentMethodLogoExtension()];
    }

    /**
     * @return list<RuntimeLoaderInterface>
     */
    protected function getRuntimeLoaders(): array
    {
        return [new FactoryRuntimeLoader([
            PaymentMethodLogoRuntime::class => static fn (): PaymentMethodLogoRuntime => new PaymentMethodLogoRuntime(
                new PaymentMethodLogoProvider(['resurs' => 'https://cdn.example/resurs.png'], ['dankort', 'visa', 'mastercard']),
            ),
        ])];
    }
}
