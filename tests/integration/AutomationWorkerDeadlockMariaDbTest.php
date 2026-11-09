<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('discovery worker writes retry a deadlock in fail-fast mode on the native database', function () {
	$process=proc_open(array(PHP_BINARY,dirname(__DIR__).'/fixtures/automation-worker-deadlock-native.php'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
	expect(is_resource($process))->toBeTrue();
	fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
	$error=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
	\PHPUnit\Framework\Assert::assertSame(0,$exit,$out.$error);
	$result=json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);
	expect($result['checks'])->toBe(10);
});
