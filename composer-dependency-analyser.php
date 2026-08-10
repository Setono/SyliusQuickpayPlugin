<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToExclude(__DIR__ . '/tests')
    // The state machine callback that forwards payment transitions to Quickpay is registered as
    // prepended winzou_state_machine config, so the dependency is real but never referenced by code
    ->ignoreErrorsOnPackage('winzou/state-machine', [ErrorType::UNUSED_DEPENDENCY])
    // The credentials-validating client applies a short timeout when the Symfony HTTP client is
    // available; the reference is guarded by class_exists, so the package stays optional. Which
    // error type fires depends on how the environment installed the package (dev dependency
    // locally, transitive package in the require-dev-less CI job) — both are ignored, and since
    // the one that does not apply would be reported as an unmatched ignore, that report is off
    ->ignoreErrorsOnPackage('symfony/http-client', [ErrorType::DEV_DEPENDENCY_IN_PROD, ErrorType::SHADOW_DEPENDENCY])
    ->disableReportingUnmatchedIgnores()
;
