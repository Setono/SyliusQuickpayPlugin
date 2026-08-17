<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Twig;

use Setono\SyliusQuickpayPlugin\PaymentLink\PaymentLinkProvider;
use Setono\SyliusQuickpayPlugin\Twig\PaymentLinkExtension;
use Setono\SyliusQuickpayPlugin\Twig\PaymentLinkRuntime;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Extension\ExtensionInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;
use Twig\Test\IntegrationTestCase;

/**
 * Renders the fixtures in Fixtures/PaymentLink through a real Twig environment with the extension,
 * its runtime and the real provider generating real urls — see the .test files for the cases.
 */
final class PaymentLinkExtensionTest extends IntegrationTestCase
{
    // Twig < 3.13 (the lowest supported version is 2.15) asks for the fixtures through this method
    protected function getFixturesDir(): string
    {
        return self::getFixturesDirectory();
    }

    protected static function getFixturesDirectory(): string
    {
        return __DIR__ . '/Fixtures/PaymentLink';
    }

    /**
     * @return list<ExtensionInterface>
     */
    protected function getExtensions(): array
    {
        return [new PaymentLinkExtension()];
    }

    /**
     * @return list<RuntimeLoaderInterface>
     */
    protected function getRuntimeLoaders(): array
    {
        $routes = new RouteCollection();
        $routes->add('sylius_shop_order_pay', new Route('/{_locale}/order/{tokenValue}/pay'));
        $context = new RequestContext('', 'GET', 'localhost', 'http');

        return [new FactoryRuntimeLoader([
            PaymentLinkRuntime::class => static fn (): PaymentLinkRuntime => new PaymentLinkRuntime(new PaymentLinkProvider(
                new UrlGenerator($routes, $context),
                new UrlHelper(new RequestStack(), $context),
            )),
        ])];
    }
}
