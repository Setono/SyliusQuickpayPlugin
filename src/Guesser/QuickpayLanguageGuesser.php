<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Guesser;

use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Locale\Context\LocaleNotFoundException;

class QuickpayLanguageGuesser implements QuickpayLanguageGuesserInterface
{
    /** @var LocaleContextInterface */
    protected $localeContext;

    public function __construct(LocaleContextInterface $localeContext)
    {
        $this->localeContext = $localeContext;
    }

    public function guess(): string
    {
        // Map both norwegian locales to no
        // @see https://github.com/QuickPay/standard-branding/tree/master/locales
        static $map = [
            'nb' => 'no',
            'nn' => 'no',
        ];

        try {
            $locale = $this->localeContext->getLocaleCode();

            $language = explode('_', $locale)[0];
            if (isset($map[$language])) {
                return $map[$language];
            }

            return $language;
        } catch (LocaleNotFoundException $e) {
        }

        return 'en';
    }
}
