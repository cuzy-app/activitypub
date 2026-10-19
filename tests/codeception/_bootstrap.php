<?php

/*
 * Module test bootstrap: locate the HumHub core and hand over to its test bootstrap.
 *
 * The core is taken from the HUMHUB_PATH environment variable, else from
 * `humhub_root` in tests/config/test.php, else the module is assumed to live in
 * <humhub>/protected/modules/activitypub.
 */

$testRoot = dirname(__DIR__);

\Codeception\Configuration::append(['test_root' => $testRoot]);
codecept_debug('Module root: ' . $testRoot);

$humhubPath = getenv('HUMHUB_PATH');
if ($humhubPath === false) {
    $moduleConfig = require $testRoot . '/config/test.php';
    $humhubPath = $moduleConfig['humhub_root'] ?? dirname(__DIR__, 5);
}

\Codeception\Configuration::append(['humhub_root' => $humhubPath]);
codecept_debug('HumHub Root: ' . $humhubPath);

// Load test configuration (/config/test.php or /config/env/<environment>/test.php)
$globalConfig = require $humhubPath . '/protected/humhub/tests/codeception/_loadConfig.php';

// Load default test bootstrap (initialize Yii...)
require $globalConfig['humhub_root'] . '/protected/humhub/tests/codeception/_bootstrap.php';
