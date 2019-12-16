<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Guesser;

interface QuickpayLanguageGuesserInterface
{
    public function guess(): string;
}
