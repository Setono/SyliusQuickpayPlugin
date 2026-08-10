<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Guesser;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Guesser\QuickpayLanguageGuesser;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Locale\Context\LocaleNotFoundException;

final class QuickpayLanguageGuesserTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider localeProvider
     */
    public function it_guesses_the_language_from_the_locale(string $locale, string $expectedLanguage): void
    {
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn($locale);

        self::assertSame($expectedLanguage, (new QuickpayLanguageGuesser($localeContext))->guess());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'plain locale' => ['da_DK', 'da'];

        yield 'locale without territory' => ['sv', 'sv'];

        yield 'norwegian bokmal maps to no' => ['nb_NO', 'no'];

        yield 'norwegian nynorsk maps to no' => ['nn_NO', 'no'];

        yield 'bogus language falls back to the default' => ['xx_YY', 'en'];

        yield 'empty locale falls back to the default' => ['', 'en'];
    }

    /**
     * @test
     */
    public function it_falls_back_to_the_default_language_when_the_locale_cannot_be_resolved(): void
    {
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willThrowException(new LocaleNotFoundException());

        self::assertSame('en', (new QuickpayLanguageGuesser($localeContext))->guess());
    }
}
