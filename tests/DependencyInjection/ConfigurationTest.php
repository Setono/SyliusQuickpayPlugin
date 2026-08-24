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
            'fraud' => [
                'block_capture' => false,
            ],
            'checkout' => [
                'payment_method_logos' => [],
                'creditcard_brands' => ['visa', 'mastercard'],
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
            'fraud' => [
                'block_capture' => false,
            ],
            'checkout' => [
                'payment_method_logos' => [],
                'creditcard_brands' => ['visa', 'mastercard'],
            ],
        ]);
    }

    /**
     * @test
     */
    public function it_allows_enabling_the_capture_fraud_guard(): void
    {
        $this->assertProcessedConfigurationEquals([
            ['fraud' => ['block_capture' => true]],
        ], [
            'operations' => [
                'capture' => true,
                'refund' => true,
                'cancel' => true,
            ],
            'fraud' => [
                'block_capture' => true,
            ],
            'checkout' => [
                'payment_method_logos' => [],
                'creditcard_brands' => ['visa', 'mastercard'],
            ],
        ]);
    }

    /**
     * @test
     */
    public function it_keeps_payment_method_tokens_as_configured(): void
    {
        $this->assertProcessedConfigurationEquals([
            ['checkout' => ['payment_method_logos' => ['mobilepay' => 'build/images/mobilepay.svg', 'apple-pay' => null]]],
            ['checkout' => ['creditcard_brands' => ['dankort', 'visa']]],
        ], [
            'operations' => [
                'capture' => true,
                'refund' => true,
                'cancel' => true,
            ],
            'fraud' => [
                'block_capture' => false,
            ],
            'checkout' => [
                'payment_method_logos' => ['mobilepay' => 'build/images/mobilepay.svg', 'apple-pay' => null],
                'creditcard_brands' => ['dankort', 'visa'],
            ],
        ]);
    }
}
