<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Diagnosis;

/**
 * What a finding says (see GLOSSARY.md, Finding). Only a failed finding means the gateway configuration doesn't work.
 */
enum FindingOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';

    /**
     * A check that was skipped or whose answer proved nothing, or a setting that works but surprises
     */
    case Warning = 'warning';
}
