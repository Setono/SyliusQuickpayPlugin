<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\DependencyInjection;

use Matthias\SymfonyConfigTest\PhpUnit\ConfigurationTestCaseTrait;
use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    use ConfigurationTestCaseTrait;

    protected function getConfiguration(): Configuration
    {
        return new Configuration();
    }

    /**
     * @test
     */
    public function it_has_sensible_defaults(): void
    {
        $this->assertProcessedConfigurationEquals([], [
            'operations' => [
                'capture' => true,
                'refund' => true,
                'cancel' => true,
            ],
        ]);
    }

    /**
     * @test
     */
    public function it_allows_disabling_individual_operations(): void
    {
        $this->assertProcessedConfigurationEquals([
            ['operations' => ['capture' => false]],
            ['operations' => ['cancel' => false]],
        ], [
            'operations' => [
                'capture' => false,
                'refund' => true,
                'cancel' => false,
            ],
        ]);
    }
}
