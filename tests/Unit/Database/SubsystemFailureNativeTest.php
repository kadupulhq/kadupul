<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

declare(strict_types=1);

function subsystem_failure_run(string $scenario): array {
	if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
		throw new PHPUnit\Framework\SkippedTestError('Disposable database required.');
	}
	$command = [PHP_BINARY];
	if (getenv('LTS_PCOV_EXTENSION')) {
		array_push($command, '-d', 'extension=' . getenv('LTS_PCOV_EXTENSION'), '-d', 'pcov.directory=' . dirname(__DIR__, 3));
	}
	array_push($command, dirname(__DIR__, 2) . '/fixtures/subsystem-failures.php', $scenario);
	$process = proc_open($command, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
	expect(is_resource($process))->toBeTrue();
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	PHPUnit\Framework\Assert::assertSame(0, proc_close($process), $stderr . $stdout);
	expect($stderr)->toBe('');
	return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

test('failed cache rebuild preserves every previous row', function (string $scenario): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeFalse()->and($r['retained'])->toBeTrue()->and($r['rows'])->toBe(2);
	if ($scenario === 'cache-borrowed') expect($r['borrowed'])->toBeTrue()->and($r['caller'])->toBe('caller pending');
})->with(['cache-update-fail','cache-insert-fail','cache-delete-fail','cache-source-fail','cache-engine','cache-shadow','cache-borrowed']);

test('successful cache replacement removes obsolete rows and admits empty results', function (string $scenario, int $rows): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeTrue()->and($r['rows'])->toBe($rows);
	if ($rows) expect($r['hostname'])->toBe('new');
})->with([['cache-success',1],['cache-empty',0],['cache-empty-host',0]]);

test('failed site deletion retains the site and its device association', function (string $scenario): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeFalse()->and($r['sites'])->toBe(1)->and($r['site_id'])->toBe(7);
	if ($scenario === 'site-borrowed') expect($r['borrowed'])->toBeTrue()->and($r['caller'])->toBe('caller');
})->with(['site-update-fail','site-delete-fail','site-engine','site-borrowed']);

test('site deletion commits both mutations and accepts empty selection', function (string $scenario, int $sites, int $site): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeTrue()->and($r['sites'])->toBe($sites)->and($r['site_id'])->toBe($site);
})->with([['site-success',0,0],['site-empty',1,7]]);

test('invalid duplicate names or missing sources write no copies', function (string $scenario): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeFalse()->and($r['names'])->toHaveCount(2);
})->with(['duplicate-long','duplicate-array','duplicate-blank','duplicate-invalid','duplicate-missing']);

test('site duplication accepts the character boundary and ordinary names', function (string $scenario): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeTrue()->and($r['names'])->toHaveCount(3)->and($r['names'][2])->toBe($r['names'][0] . ' (1)');
})->with(['duplicate-success','duplicate-unicode']);

test('rejected device saves do not disable the device or rebuild its cache', function (string $scenario): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeFalsy()->and($r['disabled'])->toBe('')->and($r['cache_retained'])->toBeTrue();
})->with(['device-invalid','device-late-invalid','device-save-fail']);

test('failed output handoff retains the local batch and rolls back both destinations', function (string $scenario): void {
	$r = subsystem_failure_run($scenario);
	expect($r['queued'])->toBeTrue()->and($r['ok'])->toBeFalse()->and($r['pending'])->toBe(1)->and($r['rows'])->toBe(0)->and($r['boost'])->toBe(0);
	if ($scenario === 'output-borrowed') expect($r['borrowed'])->toBeTrue()->and($r['caller'])->toBe('caller pending');
	if ($scenario === 'output-retry') expect($r['retry'])->toBeTrue()->and($r['retry_rows'])->toBe(2)->and($r['retry_pending'])->toBe(0)->and($r['times_match'])->toBe(2);
	if (str_starts_with($scenario, 'output-truncation')) expect($r['emulated'])->toBe($scenario === 'output-truncation');
})->with(['output-first-fail','output-second-fail','output-retry','output-truncation','output-truncation-native','output-borrowed']);

test('output handoff preserves timestamps, zero values and idempotency', function (string $scenario, int $boost): void {
	$r = subsystem_failure_run($scenario);
	expect($r['ok'])->toBeTrue()->and($r['pending'])->toBe(0)->and($r['rows'])->toBe(2)->and($r['boost'])->toBe($boost)->and($r['times_match'])->toBe($boost);
})->with([['output-delayed',2],['output-success',2],['output-plain',0],['output-idempotent',2]]);

test('script completion status controls whether the sample is accepted', function (string $scenario, string $value): void {
	expect(subsystem_failure_run($scenario)['value'])->toBe($value);
})->with([['script-success','0'],['script-fail','U'],['script-empty','U'],['script-large','42']]);

test('output handoff preserves exact values on the supplied destination connection', function (string $scenario): void {
	$r = subsystem_failure_run($scenario);
	$expected = [[7, "o'brien\\界", "label:'temp'\\café 界"], [8, 'zero', '0']];
	expect($r['queued'])->toBeTrue()->and($r['ok'])->toBeTrue()->and($r['pending'])->toBe(0);
	expect($r['emulated'])->toBe($scenario === 'output-destination-emulated');
	foreach (['poller_output', 'poller_output_boost'] as $table) {
		expect($r[$table])->toBe($expected)->and($r['default_' . $table])->toBe(0);
	}
})->with(['output-destination-native', 'output-destination-emulated']);

test('a valid device save still disables the device', function (): void {
	$r = subsystem_failure_run('device-success');
	expect((int) $r['ok'])->toBe(7)->and($r['disabled'])->toBe('on');
});
