<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
$root=dirname(__DIR__,2);require $root.'/include/vendor/autoload.php';
$input=json_decode((string)stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
if(!is_array($input)||!is_string($input['directory'] ?? null)||!is_dir($input['directory'])||!is_string($input['id'] ?? null)||preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D',$input['id'])!==1)throw new RuntimeException('Invalid native close probe.');
$coverage=getenv('KADUPUL_SESSION_CLOSE_COVERAGE_STACK');
if($coverage!==false) {
    define('RRD_TEST_PARENT_COVERAGE_STACK',json_decode($coverage,true,8,JSON_THROW_ON_ERROR));
    define('CONSOLE_HANDOFF_TEST_COVERAGE','src/Platform/Infrastructure/Legacy/BrowserWorkerSession.php');
    define('RRD_TEST_COVERAGE_DIRECTORY',$input['directory'].'/coverage');
    require $root.'/tests/Fixtures/rrd-process-coverage.php';
    require_once $root.'/tests/Helpers/NativeChildCoverageEvidence.php';
    $GLOBALS['nativeChildCoverageSnapshot']=NativeChildCoverageEvidence::snapshot($root,'tests/Fixtures/browser-session-close-unavailable.php','disabled-native-close',[
        'composer.lock','tests/composer.lock','tests/Symfony/NativeCoverageStack.php','tests/Fixtures/rrd-process-coverage.php','tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Symfony/BrowserWorkerSessionTest.php','src/Platform/Infrastructure/Legacy/BrowserWorkerSession.php',
    ],Kadupul\Tests\NativeCoverageStack::capture($root));
}
ini_set('session.use_cookies','0');session_save_path($input['directory']);session_id($input['id']);
if(!session_start())throw new RuntimeException('Native close probe session was not opened.');
$before=file_get_contents($input['directory'].'/sess_'.$input['id']);
$_SESSION['state']='PRIVATE-UNCONFIRMED-SESSION-CANARY';$failure=null;
try{Kadupul\Platform\Infrastructure\Legacy\BrowserWorkerSession::close();}catch(Throwable $error){$failure=get_class($error);}
$after=file_get_contents($input['directory'].'/sess_'.$input['id']);
$GLOBALS['nativeChildCoverageMarkers']=['native-close-refusal-and-persisted-state-observed'];
print json_encode(['available'=>function_exists('session_write_close'),'failure'=>$failure,'status'=>session_status(),'unchanged'=>$before===$after],JSON_THROW_ON_ERROR);
