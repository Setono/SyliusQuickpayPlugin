<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Guesser;

use Sylius\Component\Locale\Context\LocaleContextInterface;
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

    protected LocaleContextInterface $localeContext;

    public function __construct(LocaleContextInterface $localeContext)
    {
        $this->localeContext = $localeContext;
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
        $localeParts = explode('_', $locale);
        if (!isset($localeParts[0])) {
            return self::DEFAULT_LANGUAGE;
        }

        $language = $localeParts[0];

        return self::MAPPING[$language] ?? $language;
    }
}
