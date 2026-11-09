<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('automation preserves run ownership and retry outcomes on the native database', function ($scenario) {
	$command=array(PHP_BINARY,dirname(__DIR__).'/fixtures/automation-recovery-native.php',$scenario);
	$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
	expect(is_resource($process))->toBeTrue();
	fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
	$error=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
	\PHPUnit\Framework\Assert::assertSame(0,$exit,$out.$error);
	$result=json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);
	expect($result['checks'])->toBeGreaterThan(0);
})->with(array('ownership','queue','schema','success','child-failure','deadline','completion-write','cancel','read-failure','launch-failure','schedule-write','priming-write','borrowed-transaction','manual-success','schedule-edit','disabled-force','deadlock-retry'));


test('the version-gated Installer upgrades automation and retries without advancing failed versions', function ($mode, $failure) {
	$process=proc_open(array(PHP_BINARY,dirname(__DIR__).'/fixtures/automation-upgrade-native.php',$mode,$failure),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
	expect(is_resource($process))->toBeTrue(); fclose($pipes[0]);
	$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
	\PHPUnit\Framework\Assert::assertSame(0,proc_close($process),$out.$error);
	$result=json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);
	expect($result['checks'])->toBeGreaterThanOrEqual(5);
})->with(array('cli','web'))->with(array('success','failure'));
