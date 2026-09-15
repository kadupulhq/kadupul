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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * backupRRDFile() in cli/splice_rrd.php created the backup target before it
 * had a source to copy, so a source that could not be opened, or a copy that
 * stream_copy_to_stream() could not finish, left an empty or partial file
 * behind under its 1.2.31 name. A later run then found that name already
 * taken and treated it as an existing backup. The function is extracted and
 * run against a real temporary directory and the real cacti_cli_create_file()
 * / cacti_cli_remove_file() helpers, so the fix is exercised as it runs in
 * the script.
 */

namespace SpliceRrdBackupCleanupTest;

require_once __DIR__ . '/../../../../lib/maintenance_cli.php';

if (!function_exists(__NAMESPACE__ . '\backupRRDFile')) {
	$root = dirname(__DIR__, 4);

	preg_match('/^function backupRRDFile\(.*?^}\n/ms', file_get_contents($root . '/cli/splice_rrd.php'), $match);

	expect($match)->not->toBeEmpty();

	/* test-only eval of source read from this repository, not external input;
	 * cacti_cli_create_file()/cacti_cli_remove_file() are unqualified calls,
	 * so PHP resolves them to the real global functions required above */
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
}

beforeEach(function () {
	$base = sys_get_temp_dir() . '/splice_rrd_backup_' . getmypid() . '_' . mt_rand();

	$this->dir    = $base . '/backup';
	$this->rradir = $base . '/rra';

	mkdir($this->dir, 0700, true);
	mkdir($this->rradir, 0700, true);

	$GLOBALS['tempdir'] = $this->dir;
	$GLOBALS['seed']    = 'seed123';
	$GLOBALS['html']    = false;
});

afterEach(function () {
	foreach (array_merge(glob($this->dir . '/*') ?: array(), glob($this->rradir . '/*') ?: array()) as $file) {
		unlink($file);
	}

	rmdir($this->dir);
	rmdir($this->rradir);
	unset($GLOBALS['tempdir'], $GLOBALS['seed'], $GLOBALS['html']);
});

test('a missing source leaves no backup file behind', function () {
	$rrdfile = $this->rradir . '/does-not-exist.rrd';

	expect(backupRRDFile($rrdfile))->toBeFalse();
	expect(glob($this->dir . '/*'))->toBe(array());
});

test('a successful copy keeps the backup under its 1.2.31 name', function () {
	$rrdfile = $this->rradir . '/live.rrd';

	file_put_contents($rrdfile, 'rrd-bytes');

	expect(backupRRDFile($rrdfile))->toBeTrue();
	expect(file_get_contents($this->dir . '/live.rrd'))->toBe('rrd-bytes');
});

/*
 * stream_copy_to_stream() returns the byte count it moved even when the
 * source ends early, so an interrupted copy has to be simulated with a
 * source that stops short of its real size. This wrapper reads the real
 * source file but claims EOF after a fixed number of bytes, the same shape
 * as a read that was cut off partway through.
 */
class ShortCopyStreamWrapper {
	public $context;

	private $handle;
	private $limit;
	private $read = 0;

	public function stream_open($path, $mode, $options, &$opened_path) {
		list($limit, $real) = explode('|', substr($path, strlen('shortcopy://')), 2);

		$this->limit  = (int) $limit;
		$this->handle = @fopen($real, $mode);

		return $this->handle !== false;
	}

	public function stream_read($count) {
		$remaining = $this->limit - $this->read;

		if ($remaining <= 0) {
			return '';
		}

		$data = fread($this->handle, min($count, $remaining));
		$this->read += strlen($data);

		return $data;
	}

	public function stream_eof() {
		return $this->read >= $this->limit;
	}

	public function stream_stat() {
		return fstat($this->handle);
	}

	public function stream_close() {
		fclose($this->handle);
	}
}

test('a copy that ends before the source does is not reported as a success', function () {
	$rrdfile = $this->rradir . '/live.rrd';

	file_put_contents($rrdfile, 'rrd-bytes-longer-than-the-short-copy-limit');

	stream_wrapper_register('shortcopy', __NAMESPACE__ . '\ShortCopyStreamWrapper');

	try {
		expect(backupRRDFile('shortcopy://5|' . $rrdfile))->toBeFalse();
		expect(glob($this->dir . '/*'))->toBe(array());
	} finally {
		stream_wrapper_unregister('shortcopy');
	}
});
