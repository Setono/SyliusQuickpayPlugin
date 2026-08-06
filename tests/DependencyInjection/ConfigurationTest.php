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
            'disable_capture' => false,
            'disable_refund' => false,
            'disable_cancel' => false,
        ]);
    }

    /**
     * @test
     */
    public function it_allows_disabling_individual_operations(): void
    {
        $this->assertProcessedConfigurationEquals([
            ['disable_capture' => true],
            ['disable_cancel' => true],
        ], [
            'disable_capture' => true,
            'disable_refund' => false,
            'disable_cancel' => true,
        ]);
    }
}
