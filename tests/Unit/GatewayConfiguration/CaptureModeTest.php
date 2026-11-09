<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\GatewayConfiguration;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\CaptureMode;

final class CaptureModeTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider storedUseAuthorizeValues
     */
    public function it_is_read_from_sylius_use_authorize_gateway_option_as_checkout_reads_it(mixed $useAuthorize, CaptureMode $expected): void
    {
        self::assertSame($expected, CaptureMode::fromUseAuthorize($useAuthorize));
    }

    /**
     * Sylius' checkout authorizes when `use_authorize` is set and true under PHP's bool cast, and executes
     * `Capture` otherwise
     *
     * @return iterable<string, array{mixed, CaptureMode}>
     */
    public static function storedUseAuthorizeValues(): iterable
    {
        yield 'true' => [true, CaptureMode::OnCompletion];
        yield 'false' => [false, CaptureMode::Immediately];
        yield 'not set' => [null, CaptureMode::Immediately];
        yield "'1'" => ['1', CaptureMode::OnCompletion];
        yield "'0'" => ['0', CaptureMode::Immediately];
        yield "'false', which PHP casts to true" => ['false', CaptureMode::OnCompletion];
    }

    /**
     * @test
     */
    public function it_is_saved_as_sylius_use_authorize_gateway_option(): void
    {
        self::assertTrue(CaptureMode::OnCompletion->useAuthorize());
        self::assertFalse(CaptureMode::Immediately->useAuthorize());
    }
}
