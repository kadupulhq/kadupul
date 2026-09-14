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

/*
 * cli/removespikes.php and cli/batchgapfix.php run lib/spikekill.php's
 * remove_spikes() as root. It copies the RRD being processed to a backup
 * name under cache/spikekill/ (or the configured spikekill_backupdir), a
 * directory the web user's poller can write to. The prior code picked the
 * name with file_exists() and then called copy(), which follows symlinks:
 * a symlink planted at that name, dangling or not, made copy() write
 * through it as root instead of to the intended backup file.
 *
 * copyFileSafely() replaces that check-then-act pair with an exclusive
 * create (refusing a name already taken, including by a symlink) and a
 * unique-name fallback, so root never follows or clobbers a pre-planted
 * link. Both call sites in remove_spikes() (the requested-backup copy and
 * backupRRDFile()) now go through it.
 *
 * These tests load the real class with the constructor bypassed (it reads
 * config options this test has no database for) and invoke the private
 * methods via reflection, so the exact shipped code runs against a real
 * temp directory rather than a text-extracted copy of it.
 */

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

function invoke_spikekill_private(string $method, array $args) {
	$reflection = new ReflectionClass('spikekill');
	$instance   = $reflection->newInstanceWithoutConstructor();
	$m          = $reflection->getMethod($method);
	$m->setAccessible(true);

	return $m->invokeArgs($instance, $args);
}

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/spikekill_test_' . uniqid();
	mkdir($this->dir, 0700, true);

	$this->rrdfile = $this->dir . '/source.rrd';
	file_put_contents($this->rrdfile, 'rrd-bytes');
});

afterEach(function () {
	foreach (glob($this->dir . '/*') as $item) {
		unlink($item);
	}

	rmdir($this->dir);
});

test('a normal backup is created with the expected content and name', function () {
	$desired = $this->dir . '/backup.rrd';

	$written = invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]);

	expect($written)->toBe($desired)
		->and(is_link($desired))->toBeFalse()
		->and(file_get_contents($desired))->toBe('rrd-bytes')
		->and(fileperms($desired) & 0777)->toBe(0600);
});

test('a planted symlink at the backup name is never written to', function () {
	$evil_target = $this->dir . '/evil-target';
	$desired     = $this->dir . '/backup.rrd';

	symlink($evil_target, $desired);

	$written = invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]);

	expect($written)->not->toBe($desired)
		->and(is_link($desired))->toBeTrue()
		->and(file_exists($evil_target))->toBeFalse()
		->and(file_get_contents($written))->toBe('rrd-bytes');

	unlink($written);
	unlink($desired);
});

test('a dangling symlink at the backup name is not followed', function () {
	$desired = $this->dir . '/backup.rrd';

	symlink($this->dir . '/does-not-exist', $desired);

	$written = invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]);

	expect($written)->not->toBe($desired)
		->and(is_link($desired))->toBeTrue()
		->and(file_exists($this->dir . '/does-not-exist'))->toBeFalse();

	unlink($written);
	unlink($desired);
});

test('an existing regular file at the backup name is not overwritten', function () {
	$desired = $this->dir . '/backup.rrd';
	file_put_contents($desired, 'do-not-touch');

	$written = invoke_spikekill_private('copyFileSafely', [$this->rrdfile, $desired]);

	expect($written)->not->toBe($desired)
		->and(file_get_contents($desired))->toBe('do-not-touch')
		->and(file_get_contents($written))->toBe('rrd-bytes');

	unlink($written);
	unlink($desired);
});

test('unlinkOwnedFile refuses to remove a name swapped for a symlink', function () {
	$path = $this->dir . '/owned.rrd';
	$fh   = fopen($path, 'xb');
	$fstat = fstat($fh);
	fclose($fh);

	unlink($path);
	symlink($this->dir . '/source.rrd', $path);

	invoke_spikekill_private('unlinkOwnedFile', [$path, $fstat]);

	expect(is_link($path))->toBeTrue()
		->and(file_exists($this->dir . '/source.rrd'))->toBeTrue();

	unlink($path);
});
