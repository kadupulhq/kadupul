<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace PollerCacheChildLifecycleTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function db_fetch_assoc($sql) {
	return $GLOBALS['child_rows'];
}

function cacti_process_still_running($pid) {
	return $pid === 1234;
}

function unregister_process($taskType, $taskName, $taskId, $pid) {
	$GLOBALS['unregistered_children'][] = array($taskType, $taskName, $taskId, $pid);
}

function read_config_option($option) {
	return '/usr/bin/php';
}

function pushout_debug($message) {}

function cacti_log(...$arguments) {}

function exec_background_process($binary, $args) {
	$GLOBALS['launched_process'] = array($binary, $args);

	return $GLOBALS['launch_result'];
}

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/rebuild_poller_cache.php');
if (!is_string($source)) {
	throw new \RuntimeException('Cannot read cli/rebuild_poller_cache.php.');
}
if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 1);
}
eval('namespace PollerCacheChildLifecycleTest; ' . test_php_function_source($source, 'pushout_processes_running'));
eval('namespace PollerCacheChildLifecycleTest; ' . test_php_function_source($source, 'pushout_launch_child'));

test('child monitoring distinguishes database errors and reaps an exited child with no result marker', function () {
	$GLOBALS['child_rows'] = false;
	expect(pushout_processes_running())->toBeFalse();

	$GLOBALS['child_rows'] = array(array('taskid' => 1, 'pid' => 1234));
	expect(pushout_processes_running())->toBe(1);

	$GLOBALS['unregistered_children'] = array();
	$GLOBALS['child_rows'] = array(array('taskid' => 2, 'pid' => 4321));
	expect(pushout_processes_running())->toBe(0)
		->and($GLOBALS['pushout_child_exit_unreported'])->toBeTrue()
		->and($GLOBALS['unregistered_children'])->toBe(array(array('pushout', 'child', 2, 4321)));
});

test('child launch returns the process adapter result and preserves selected scope', function () {
	$GLOBALS['config'] = array('base_path' => '/srv/cacti');
	$GLOBALS['debug'] = true;
	$GLOBALS['host_template_id'] = 7;
	$GLOBALS['data_template_id'] = 9;
	$GLOBALS['launch_result'] = false;

	expect(pushout_launch_child(3, 5, 11))->toBeFalse()
		->and($GLOBALS['launched_process'])->toBe(array('/usr/bin/php', array(
			'/srv/cacti/cli/push_out_hosts.php',
			'--type=child',
			'--threads=5',
			'--child=3',
			'--debug',
			'--host-id=11',
			'--host-template-id=7',
			'--data-template-id=9',
		)));
});
