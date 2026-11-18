<?php
declare(strict_types=1);

/**
 * IntraVox Test Bootstrap
 *
 * This bootstrap file sets up the testing environment for IntraVox.
 * For unit tests, we use stubs instead of the full Nextcloud framework.
 *
 * IMPORTANT: OCP stubs must be loaded BEFORE composer autoloader to ensure
 * IntraVox classes find the stub implementations when they're loaded.
 */

// Load OCP stubs FIRST - before any autoloading happens
require_once __DIR__ . '/Stubs/OCP.php';

// Load the real standalone contracts absent from the minimal legacy stubs.
// These interfaces have no Nextcloud server/bootstrap side effects.
foreach ([
    'Files/SimpleFS/ISimpleFile.php',
    'Files/SimpleFS/ISimpleFolder.php',
    'Files/SimpleFS/ISimpleRoot.php',
    'Files/IAppData.php',
    'Lock/ILockingProvider.php',
] as $contract) {
    require_once __DIR__ . '/../vendor/nextcloud/ocp/OCP/' . $contract;
}

// Load composer autoloader (this will autoload IntraVox classes on demand)
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Load test mocks
require_once __DIR__ . '/Mocks/MockUserSession.php';
require_once __DIR__ . '/Mocks/MockGroupManager.php';
