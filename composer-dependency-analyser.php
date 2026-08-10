<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToExclude(__DIR__ . '/tests')
    // The state machine callback that forwards payment transitions to Quickpay is registered as
    // prepended winzou_state_machine config, so the dependency is real but never referenced by code
    ->ignoreErrorsOnPackage('winzou/state-machine', [ErrorType::UNUSED_DEPENDENCY])
;
