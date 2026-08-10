<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Guesser;

interface LanguageGuesserInterface
{
    public function guess(): string;
}
