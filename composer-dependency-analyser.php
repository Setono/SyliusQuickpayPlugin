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
    // available; the reference is guarded by class_exists, so the package stays optional
    ->ignoreErrorsOnPackage('symfony/http-client', [ErrorType::DEV_DEPENDENCY_IN_PROD])
;
