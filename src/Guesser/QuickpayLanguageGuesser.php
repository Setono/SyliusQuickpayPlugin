<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Guesser;

use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\Intl\Languages;
use Throwable;

class QuickpayLanguageGuesser implements QuickpayLanguageGuesserInterface
{
    private const DEFAULT_LANGUAGE = 'en';

    /**
     * Map both norwegian locales to no
     *
     * @see https://github.com/QuickPay/standard-branding/tree/master/locales
     */
    private const MAPPING = [
        'nb' => 'no',
        'nn' => 'no',
    ];

    public function __construct(protected LocaleContextInterface $localeContext)
    {
    }

    public function guess(): string
    {
        try {
            $locale = $this->localeContext->getLocaleCode();

            return self::resolveLanguage($locale);
        } catch (Throwable) {
            return self::DEFAULT_LANGUAGE;
        }
    }

    private static function resolveLanguage(string $locale): string
    {
        $language = explode('_', $locale)[0];
        $language = self::MAPPING[$language] ?? $language;

        return Languages::exists($language) ? $language : self::DEFAULT_LANGUAGE;
    }
}
