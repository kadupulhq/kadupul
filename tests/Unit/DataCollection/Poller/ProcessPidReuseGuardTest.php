<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * register_process_start(), timeout_kill_registered_processes(), and
 * dsstats_kill_running_processes() used to trust a registered pid with a
 * bare `posix_kill($pid, 0)`. If the registered process died without
 * unregistering and the OS recycled its pid for an unrelated program, the
 * bare check couldn't tell the difference: it would refuse to start a
 * legitimate new task, or SIGTERM a process that never had anything to do
 * with Cacti. The fix adds cacti_process_still_running(), which layers a
 * procfs command-line check on Linux, and routes all three call sites
 * through it instead of the bare posix_kill($pid, 0).
 */

require_once dirname(__DIR__, 4) . '/lib/poller.php';

/*
 * On hosts without /proc (macOS/BSD test runners), cacti_process_still_running()
 * hits its own @file_get_contents() fallback path, which is intentionally
 * suppressed in the fix itself. PHPUnit's error handler intercepts E_WARNING
 * regardless of the "@" operator, so calls that can reach that path are
 * wrapped here to keep the suppression the fix already declared.
 */
$stillRunning = function ($pid) {
	set_error_handler(function () {
		return true;
	});

	try {
		return cacti_process_still_running($pid);
	} finally {
		restore_error_handler();
	}
};

// proc_open can return while the child still has the parent's PHP identity.
// Wait for native exec independently of the identity guard being tested.
$withNativeSleep = function ($assertion) {
    $proc = proc_open(array('sleep', '30'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect($proc)->not->toBeFalse();
    $pid = proc_get_status($proc)['pid'];
    try {
        $deadline = microtime(true) + 5;
        do {
            $command = @file_get_contents('/proc/' . $pid . '/comm');
            if (trim((string)$command) === 'sleep') {
                $assertion($pid);
                return $pid;
            }
            usleep(1000);
        } while (microtime(true) < $deadline && proc_get_status($proc)['running']);
        throw new RuntimeException('Native child did not finish exec before the identity assertion');
    } finally {
        proc_terminate($proc, SIGKILL);
        foreach ($pipes as $pipe) { fclose($pipe); }
        proc_close($proc);
    }
};

test('rejects non-positive pids outright', function () use ($stillRunning) {
	expect($stillRunning(0))->toBeFalse();
	expect($stillRunning(-1))->toBeFalse();
});

test('returns false for a pid that is not running', function () use ($stillRunning) {
	// PID_MAX_LIMIT on Linux is 4194304; on macOS/BSD pids are 16-bit.
	// 999999999 is not an assignable pid on any of these, so posix_kill
	// with signal 0 must fail with ESRCH.
	expect($stillRunning(999999999))->toBeFalse();
});

test('returns true for the currently running process (self)', function () use ($stillRunning) {
	// Comparing /proc/self/cmdline to /proc/<pid>/cmdline (or falling back to
	// the bare existence check when /proc is unavailable) must agree
	// that our own pid is both alive and not a reused identity.
	expect($stillRunning(getmypid()))->toBeTrue();
});

test('rejects a recycled pid running a readable native command', function () use ($stillRunning, $withNativeSleep) {
    if (!is_dir('/proc/' . getmypid())) {
        test()->markTestSkipped('command identity is available only on procfs platforms');
    }
    $pid = $withNativeSleep(function ($pid) use ($stillRunning) {
        expect(cacti_process_identity_matches($pid))->toBeFalse()
            ->and($stillRunning($pid))->toBeFalse();
    });
    expect($stillRunning($pid))->toBeFalse();
});

test('cacti_process_kill_denied does not keep the row of a pid reused by an unrelated process', function () use ($withNativeSleep) {
    if (!is_dir('/proc/' . getmypid())) {
        test()->markTestSkipped('command identity is available only on procfs platforms');
    }
    $withNativeSleep(function ($pid) {
        expect(cacti_process_kill_denied($pid))->toBeFalse();
    });
});

test('falls back to the bare existence check when /proc is unavailable', function () use ($stillRunning) {
	if (is_dir('/proc')) {
		test()->markTestSkipped('This host has /proc; the Linux command-name comparison path is exercised instead.');
	}

	// On non-Linux hosts (e.g. macOS/BSD) file_get_contents() on
	// /proc/<pid>/comm always fails, so the function must fall back to
	// treating a live pid as "still running" rather than defaulting to
	// false and starving legitimate registrations.
	expect($stillRunning(getmypid()))->toBeTrue();
});

test('the liveness guard falls back when procfs identity is unreadable', function () {
	$src = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
	$start = strpos($src, 'function cacti_process_still_running(');
	$body = substr($src, $start, strpos($src, "\n}\n", $start) - $start);

	expect($body)->toContain('$identity_matches !== null')
		->and($body)->toContain('return cacti_process_signalable($pid);');
});

test('all common PHP executables require script identity', function () {
	foreach (array('php', 'php8.3', 'php-fpm', 'php-fpm8.3', 'php-cgi', 'php-cgi8.3', 'phpdbg', 'phpdbg8.3') as $binary) {
		expect(cacti_process_executable_is_php_interpreter('/usr/bin/' . $binary))->toBeTrue();
	}

	expect(cacti_process_executable_is_php_interpreter('/usr/bin/phpunit'))->toBeFalse()
		->and(cacti_process_executable_is_php_interpreter('/usr/bin/python'))->toBeFalse();
});

test('register_process_start() and timeout_kill_registered_processes() route through the guard, not a bare posix_kill(pid, 0)', function () {
	$src = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');

	expect($src)->toContain("'/cmdline'")
		->and($src)->toContain('hash_equals($mine_script, $theirs_script)')
		->and($src)->toContain('($mine_script === false) !== ($theirs_script === false)')
		->and($src)->toContain('cacti_process_executable_is_php_interpreter($hidden_exe)');

	/* Three liveness decisions live in these two functions: the timed-out pid
	   and the not-yet-timed-out row in register_process_start(), and the row in
	   timeout_kill_registered_processes(). They name the argument $timeout_pid,
	   $r['pid'] and $pid, so counting one spelling only ever found one of the
	   three. Assert the guarded call is present in each function instead. */
	foreach (array('register_process_start', 'timeout_kill_registered_processes') as $function) {
		$start = strpos($src, 'function ' . $function . '(');

		expect($start)->not->toBeFalse();
		expect(substr($src, $start, 2600))->toContain('cacti_process_still_running(');
	}

	/* Subtract the declaration, which the raw count includes, so this asserts
	   the three call sites it names rather than two of them plus the function. */
	$calls = substr_count($src, 'cacti_process_still_running($') - substr_count($src, 'function cacti_process_still_running($');

	expect($calls)->toBeGreaterThanOrEqual(3);
	expect($src)->not->toContain('$r[\'pid\'] > 0 && posix_kill($r[\'pid\'], 0)');
	expect($src)->not->toContain("\$timeout_pid = (int) \$r['pid']")
		->and($src)->not->toContain("\$pid = (int) \$r['pid']");
});

test('dsstats_kill_running_processes() guards its SIGTERM with the same check', function () {
	$src = file_get_contents(dirname(__DIR__, 4) . '/lib/dsstats.php');

	$pos = strpos($src, 'function dsstats_kill_running_processes()');
	expect($pos)->not->toBeFalse();

	$fragment = substr($src, $pos, 700);

	expect($fragment)->toContain('if (cacti_process_still_running($p[\'pid\'])) {');
});

/**
 * Starts a PHP process that runs $script from $cwd and waits for procfs to
 * show its command line.
 *
 * @param array<int, string> $command Command and arguments.
 * @param string             $cwd     Working directory for the child.
 *
 * @return array{0: resource, 1: int} The process handle and its pid.
 */
function pid_reuse_start_probe(array $command, string $cwd) : array {
	$pipes   = array();
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);

	expect($process)->not->toBeFalse();

	$pid      = proc_get_status($process)['pid'];
	$deadline = microtime(true) + 5;

	while (strpos((string) @file_get_contents('/proc/' . $pid . '/cmdline'), 'probe.php') === false && microtime(true) < $deadline) {
		usleep(20000);
	}

	return array($process, $pid);
}

/**
 * Runs the checker from $cwd and returns cacti_process_still_running($pid).
 *
 * @param array<int, string> $command Checker command and arguments.
 * @param string             $cwd     Working directory for the checker.
 *
 * @return string JSON true or false as printed by the probe.
 */
function pid_reuse_check(array $command, string $cwd) : string {
	$pipes   = array();
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);
	$output  = trim(stream_get_contents($pipes[1]));
	$error   = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	expect($output)->toBeIn(array('true', 'false'), $error);

	return $output;
}

test('a relative script launch matches the same script checked from another directory', function () {
	if (!is_dir('/proc/' . getmypid()) || !is_dir('/proc/self/cwd')) {
		test()->markTestSkipped('command identity is available only on procfs platforms');
	}

	$root  = sys_get_temp_dir() . '/cacti_pid_reuse_' . getmypid();
	$app   = $root . '/app';
	$other = $root . '/other';
	$twin  = $root . '/twin';

	foreach (array($app, $other, $twin) as $dir) {
		@mkdir($dir, 0700, true);
	}

	$probe = '<?php' . PHP_EOL
		. 'if ($argv[1] === "sleep") { sleep(20); exit; }' . PHP_EOL
		. 'require ' . var_export(dirname(__DIR__, 4) . '/lib/poller.php', true) . ';' . PHP_EOL
		. 'echo json_encode(cacti_process_still_running((int) $argv[2]));' . PHP_EOL;

	file_put_contents($app . '/probe.php', $probe);
	file_put_contents($twin . '/probe.php', $probe);

	[$relative, $relative_pid] = pid_reuse_start_probe(array(PHP_BINARY, 'probe.php', 'sleep'), $app);
	[$absolute, $absolute_pid] = pid_reuse_start_probe(array(PHP_BINARY, $app . '/probe.php', 'sleep'), $other);

	try {
		/* the two cases that returned false before relative paths were resolved
		   against the target's working directory */
		expect(pid_reuse_check(array(PHP_BINARY, $app . '/probe.php', 'check', (string) $relative_pid), $other))->toBe('true')
			->and(pid_reuse_check(array(PHP_BINARY, $app . '/probe.php', 'check', (string) $relative_pid), $twin))->toBe('true')
			->and(pid_reuse_check(array(PHP_BINARY, 'probe.php', 'check', (string) $relative_pid), $app))->toBe('true')
			->and(pid_reuse_check(array(PHP_BINARY, '../app/probe.php', 'check', (string) $absolute_pid), $other))->toBe('true')
			/* a same-named script in another directory is still a different command */
			->and(pid_reuse_check(array(PHP_BINARY, 'probe.php', 'check', (string) $relative_pid), $twin))->toBe('false')
			->and(pid_reuse_check(array(PHP_BINARY, $twin . '/probe.php', 'check', (string) $absolute_pid), $other))->toBe('false');
	} finally {
		foreach (array(array($relative, $relative_pid), array($absolute, $absolute_pid)) as $child) {
			posix_kill($child[1], 9);
			proc_close($child[0]);
		}

		foreach (array($app, $twin) as $dir) {
			@unlink($dir . '/probe.php');
		}

		foreach (array($app, $other, $twin, $root) as $dir) {
			@rmdir($dir);
		}
	}
});
