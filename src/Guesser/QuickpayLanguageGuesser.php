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
        try {
            $locale = $this->localeContext->getLocaleCode();

            return substr($locale, 0, 2);
        } catch (LocaleNotFoundException $e) {
        }

        return 'en';
    }
}
