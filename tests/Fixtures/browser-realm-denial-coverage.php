<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Tests\BrowserRealmDenialCoverageRegistration;

$browserDenialRoot=dirname(__DIR__,2);
require_once $browserDenialRoot.'/tests/Symfony/BrowserRealmDenialCoverageRegistration.php';
$browserDenialFixture=getenv('KADUPUL_BROWSER_DENIAL_FIXTURE');
if(!is_string($browserDenialFixture)||!is_dir($browserDenialFixture)) {throw new RuntimeException('Missing native realm fixture.');}
define('BROWSER_REALM_DENIAL_TEST_COVERAGE',true);
define('RRD_TEST_COVERAGE_DIRECTORY',$browserDenialFixture.'/coverage');
define('RRD_TEST_ADDITIONAL_COVERAGE_COPIES',[
    $browserDenialFixture.'/include/auth.php'=>$browserDenialRoot.'/include/auth.php',
    $browserDenialFixture.'/bin/legacy-console-handoff.php'=>$browserDenialRoot.'/bin/legacy-console-handoff.php',
]);
require $browserDenialRoot.'/tests/Fixtures/rrd-process-coverage.php';
require_once $browserDenialRoot.'/tests/Helpers/NativeChildCoverageEvidence.php';
$GLOBALS['nativeChildCoverageSnapshot']=NativeChildCoverageEvidence::snapshot($browserDenialRoot,BrowserRealmDenialCoverageRegistration::PRODUCER,
    (string)getenv('KADUPUL_BROWSER_DENIAL_SCENARIO'),BrowserRealmDenialCoverageRegistration::SOURCES);
register_shutdown_function(static function():void {$GLOBALS['nativeChildCoverageMarkers']=BrowserRealmDenialCoverageRegistration::MARKERS;});
unset($browserDenialRoot,$browserDenialFixture);
