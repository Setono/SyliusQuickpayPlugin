<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\DependencyInjection\SetonoSyliusQuickpayExtension;
use Setono\SyliusQuickpayPlugin\SetonoSyliusQuickpayPlugin;

final class SetonoSyliusQuickpayPluginTest extends TestCase
{
    /**
     * @test
     */
    public function it_resolves_its_container_extension(): void
    {
        self::assertInstanceOf(
            SetonoSyliusQuickpayExtension::class,
            (new SetonoSyliusQuickpayPlugin())->getContainerExtension(),
        );
    }
}
