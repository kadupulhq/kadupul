<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace InertOutputRefreshSettingTest;

$root = dirname(__DIR__, 4);

/**
 * Every tracked PHP file outside the test tree, so a claim about what the
 * application reads is made against the application rather than a guess.
 *
 * @return array<int, string> Absolute paths.
 */
function application_php_files() {
	$root  = dirname(__DIR__, 4);
	$files = array();

	foreach (explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files "*.php"')) as $line) {
		$line = trim($line);

		if ($line === '' || strpos($line, 'tests/') === 0 || strpos($line, 'include/vendor/') === 0) {
			continue;
		}

		$files[] = $root . '/' . $line;
	}

	return $files;
}

/*
 * The checkbox promised to rebuild poller_output as a MEMORY table each cycle.
 * upgrade_to_1_2_32() converts that table to InnoDB precisely so retryable
 * samples survive a restart, so the setting cannot do what it advertised.
 */
test('the inert refresh setting is gone from the settings form', function () use ($root) {
	$settings = file_get_contents($root . '/include/global_settings.php');

	expect($settings)->not->toBeFalse();
	expect(strpos($settings, 'poller_refresh_output_table'))->toBeFalse();
});

test('no application code reads the setting', function () {
	$readers = array();

	foreach (application_php_files() as $path) {
		$source = file_get_contents($path);

		if ($source !== false && strpos($source, 'poller_refresh_output_table') !== false) {
			$readers[] = $path;
		}
	}

	// The upgrade that removes the stored row is the one allowed mention.
	$readers = array_values(array_filter($readers, function ($path) {
		return substr($path, -strlen('install/upgrades/1_2_32.php')) !== 'install/upgrades/1_2_32.php';
	}));

	expect($readers)->toBe(array());
});

test('the upgrade removes the stored row', function () use ($root) {
	$upgrade = file_get_contents($root . '/install/upgrades/1_2_32.php');

	expect($upgrade)->not->toBeFalse()
		->and($upgrade)->toContain("DELETE FROM settings WHERE name = 'poller_refresh_output_table'");
});

/*
 * The removal only makes sense while the queue is required to be InnoDB. If
 * that ever changes, the setting deserves reconsidering rather than silence.
 */
test('the poller output queue is still converted away from MEMORY', function () use ($root) {
	$upgrade = file_get_contents($root . '/install/upgrades/1_2_32.php');

	expect($upgrade)->toContain('ALTER TABLE poller_output ENGINE=InnoDB');

	$maintenance = file_get_contents($root . '/lib/rrd_maintenance.php');

	expect($maintenance)->not->toBeFalse()
		->and($maintenance)->toContain('poller_output queue must use InnoDB');
});
