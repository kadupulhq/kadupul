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

require_once dirname(__DIR__, 4) . '/lib/poller_output_spool.php';

function output_spool_fixture(callable $check): void {
	global $config;
	$previous = $config ?? null;
	$root = sys_get_temp_dir() . '/output-spool-test-' . bin2hex(random_bytes(8));
	mkdir($root, 0700);
	$config = ['base_path' => dirname(__DIR__, 4), 'poller_output_spool' => $root . '/queue'];
	try {
		$check($root);
	} finally {
		foreach (glob($root . '/queue/*') ?: [] as $file) unlink($file);
		if (is_link($root . '/queue')) unlink($root . '/queue');
		elseif (is_dir($root . '/queue')) rmdir($root . '/queue');
		rmdir($root);
		$config = $previous;
	}
}

test('the local queue survives reopening with exact bytes and destination', function (): void {
	output_spool_fixture(function (): void {
		$queue = poller_output_spool(1);
		expect(poller_output_enqueue($queue, [[7, 'value', "\0\xff0"]], true))->toBeTrue();
		$queue->close();
		$queue = poller_output_spool(1);
		$row = $queue->query('SELECT * FROM batches')->fetchArray(SQLITE3_ASSOC);
		expect($row['redirect'])->toBe(1)->and(base64_decode(json_decode($row['payload'], true)[0][2], true))->toBe("\0\xff0");
		$queue->close();
	});
});

test('invalid output batches never enter the local queue', function (array $rows): void {
	output_spool_fixture(function () use ($rows): void {
		$queue = poller_output_spool(1);
		expect(poller_output_enqueue($queue, $rows, false))->toBeFalse()
			->and($queue->querySingle('SELECT COUNT(*) FROM batches'))->toBe(0);
		$queue->close();
	});
})->with([
	'empty' => [[]], 'zero id' => [[[0, 'value', '42']]], 'string id' => [[['7', 'value', '42']]],
	'array name' => [[[7, [], '42']]], 'boolean output' => [[[7, 'value', false]]],
	'oversized count' => [array_fill(0, 2003, [7, 'value', '42'])],
	'oversized payload' => [[[7, 'value', str_repeat('x', 4 * 1024 * 1024)]]],
]);

test('empty recovery succeeds and an unavailable destination retains queued work', function (): void {
	output_spool_fixture(function (): void {
		$queue = poller_output_spool(1);
		expect(poller_output_replay($queue))->toBeTrue();
		expect(poller_output_enqueue($queue, [[7, 'value', '0']], false))->toBeTrue();
		expect(poller_output_replay($queue))->toBeFalse()
			->and($queue->querySingle('SELECT COUNT(*) FROM batches'))->toBe(1);
		$queue->close();
	});
});

test('an invalid collector or linked queue directory is refused', function (): void {
	output_spool_fixture(function (string $root): void {
		expect(poller_output_spool(0))->toBeFalse();
		symlink($root, $root . '/queue');
		expect(poller_output_spool(1))->toBeFalse();
		unlink($root . '/queue');
	});
});

test('shared POSIX queue permissions are refused', function (): void {
	if (PHP_OS_FAMILY === 'Windows') $this->markTestSkipped('POSIX permissions only.');
	output_spool_fixture(function (string $root): void {
		mkdir($root . '/queue', 0755);
		expect(poller_output_spool(1))->toBeFalse();
	});
});

test('a spool directory owned by another account names the owner and the fix', function (): void {
	$stat = array('mode' => 0040700, 'uid' => 0, 'nlink' => 2);
	$message = poller_output_spool_problem($stat, 'directory', '/var/lib/cacti/poller-output', 0040000, 501);

	expect($message)->toStartWith('ERROR: Poller output spool directory /var/lib/cacti/poller-output is owned by ')
		->and($message)->toContain('uid 0')
		->and($message)->toContain('This usually follows a poller run as root.')
		->and($message)->toContain('chown -R ')
		->and($message)->toContain(' /var/lib/cacti/poller-output"')
		->and($message)->toContain('Do not delete pending queue files.');
});

test('a spool file owned by another account points the fix at its directory', function (): void {
	$message = poller_output_spool_problem(array('mode' => 0100600, 'uid' => 0, 'nlink' => 1), 'file', '/q/poller-1.sqlite', 0100000, 501);

	expect($message)->toContain('Poller output spool file /q/poller-1.sqlite is owned by')
		->and($message)->toContain(' /q"');
});

test('shared spool permissions name the mode and the chmod to run', function (): void {
	$message = poller_output_spool_problem(array('mode' => 0040755, 'uid' => 501, 'nlink' => 2), 'directory', '/q', 0040000, 501);

	expect($message)->toContain('has mode 0755')->and($message)->toContain('"chmod 0700 /q"');
});

test('a private spool owned by the poller account has no problem, and Windows skips POSIX ownership', function (): void {
	expect(poller_output_spool_problem(array('mode' => 0040700, 'uid' => 501, 'nlink' => 2), 'directory', '/q', 0040000, 501))->toBeNull()
		->and(poller_output_spool_problem(array('mode' => 0040777, 'uid' => 0, 'nlink' => 2), 'directory', 'C:/q', 0040000, null))->toBeNull()
		->and(poller_output_spool_problem(false, 'directory', '/q', 0040000, 501))->toContain('cannot be read');
});
