<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Started by LegacyPollerCacheRebuilder for kadupul:poller-cache:rebuild. The
// body and functions below are moved from cli/rebuild_poller_cache.php, from
// the line after its argument loop to its end, so the process registration,
// the child launches through cli/push_out_hosts.php, push_out_host() and the
// signal handler behave as they did. The script's globals are kept as globals
// of this file, because exec_background() reads $debug through one.
//
// Reads the parsed arguments as JSON on stdin. Prints its process id on one
// line, then exactly what the script printed, then one result line.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const KADUPUL_POLLER_CACHE_PID = 'KADUPUL_POLLER_CACHE_WORKER=';
const KADUPUL_POLLER_CACHE_RESULT = 'KADUPUL_POLLER_CACHE_RESULT=';

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

// Isolate procedural globals, plugin hooks and session messages from Symfony.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
// The libraries cli/rebuild_poller_cache.php loaded.
foreach (['utility', 'api_data_source', 'poller'] as $library) {
    require_once $config['base_path'] . '/lib/' . $library . '.php';
}
ob_end_clean();

// The last byte the script printed, so the result line can start on a line of its own.
$kadupul_last = null;

/**
 * Ends the run with the result line. A run whose output did not end with a
 * newline (a child prints one dot per device) gets one before the result line,
 * and the result says so, so the adapter can take it off again.
 *
 * @param array{status: string, outcome?: string, hosts?: int} $result
 */
function kadupul_poller_cache_finish(array $result, int $exit): never
{
    global $kadupul_last;

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    $newline = $kadupul_last === null || $kadupul_last === "\n";
    if (!$newline) {
        echo PHP_EOL;
    }
    echo KADUPUL_POLLER_CACHE_RESULT . json_encode($result + ['newline' => $newline], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($exit);
}

try {
    $input = stream_get_contents(STDIN, 20001);
    if (!is_string($input) || strlen($input) > 20000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    $keys = ['database', 'host_id', 'host_template_id', 'data_template_id', 'threads', 'type', 'child', 'force', 'debug'];
    // The ids reach the child command line that exec_background() builds, so
    // they are checked again here: digits, or a number with no spaces.
    $numeric = static fn(mixed $value): bool => $value === null || (is_string($value) && is_numeric($value) && trim($value) === $value);
    if (!is_array($command) || array_diff(array_keys($command), $keys) !== [] || count($command) !== count($keys)
        || !in_array($command['database'], ['local', 'main'], true)
        || !($command['host_id'] === null || (is_string($command['host_id']) && ctype_digit($command['host_id']) && (int) $command['host_id'] >= 1))
        || !$numeric($command['host_template_id']) || !$numeric($command['data_template_id'])
        || !($command['threads'] === null || (is_int($command['threads']) && $command['threads'] >= 1))
        || !is_string($command['type']) || !($command['child'] === null || is_string($command['child']))
        || !is_bool($command['force']) || !is_bool($command['debug'])) {
        throw new RuntimeException('Invalid command');
    }
} catch (Throwable $error) {
    cacti_log('ERROR: Poller cache rebuild worker refused its command: ' . get_class($error), false, 'WEBLOG');
    kadupul_poller_cache_finish(['status' => 'failed'], 1);
}

/* switch to main database for cli's; --local keeps the local one */
if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
    db_switch_remote_to_main();
}

/* system controlled parameters */
$type      = $command['type'];
$thread_id = $command['child'] ?? 0;

/* optional parameters for host selection */
$debug            = $command['debug'];
$host_id          = $command['host_id'] ?? false;
$host_template_id = $command['host_template_id'] ?? false;
$data_template_id = $command['data_template_id'] ?? false;

/* optional for threading and verbose display */
$threads = $command['threads'] ?? (read_config_option('commands_processes') ?? 5);

/* optional for force handing and resume */
$forcerun = $command['force'];

/* install signal handlers for UNIX only */
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'sig_handler');
    pcntl_signal(SIGINT, 'sig_handler');
}

// After the handlers, so a signal the command passes on is always handled.
echo KADUPUL_POLLER_CACHE_PID . getmypid() . PHP_EOL;

// From here on, everything printed is the script's output.
ob_start(static function (string $buffer): string {
    global $kadupul_last;

    if ($buffer !== '') {
        $kadupul_last = $buffer[-1];
    }

    return $buffer;
}, 1);

$outcome = 'skipped';
$hosts   = 0;

try {
    /* issue warnings and start message if applicable */
    print 'WARNING: Do not interrupt this script.  Rebuilding Poller Cache can take quite some time' . PHP_EOL;

    /* send a gentle message to the log and stdout */
    pushout_debug('Rebuild poller cache starting');

    /* silently end if the registered process is still running  */
    if (!$forcerun) {
        if (!register_process_start('pushout', $type, $thread_id, 86400)) {
            kadupul_poller_cache_finish(['status' => 'ok', 'outcome' => 'already_running', 'hosts' => 0], 0);
        }
    }

    /* Collect data as determined by the type */
    switch ($type) {
        case 'rmaster':
            $hosts   = pushout_master_handler($forcerun, $host_id, $host_template_id, $data_template_id, $threads);
            $outcome = $hosts > 0 ? 'rebuilt' : 'no_hosts';

            unregister_process('pushout', 'rmaster', 0);

            break;
        case 'child':  /* Launched by the rmaster process */
            $sql_where  = '';
            $sql_params = [];

            if ($host_id !== false) {
                $sql_where .= 'AND h.id = ?';
                $sql_params[] = $host_id;
            }

            if ($host_template_id !== false) {
                $sql_where .= 'AND h.host_template_id = ?';
                $sql_params[] = $host_template_id;
            }

            $rows = db_fetch_cell_prepared("SELECT count(h.id) FROM host AS h WHERE h.disabled='' " . $sql_where, $sql_params);

            $hosts_per_process = ceil($rows / $threads);

            $sql_where .= ' GROUP BY h.id ORDER BY h.id LIMIT ' . (($thread_id - 1) * $hosts_per_process) . ',' . $hosts_per_process;

            $rows = db_fetch_assoc_prepared(
                "SELECT h.id AS id, COUNT(dl.id) AS dl_count
				FROM host AS h
				LEFT JOIN data_local AS dl
				ON h.id=dl.host_id
				WHERE h.disabled='' " . $sql_where,
                $sql_params
            );

            cacti_log(sprintf('Child Started Process %s with %d hosts, from: %d', $thread_id, $hosts_per_process, ($thread_id - 1) * $hosts_per_process), true, 'PUSHOUT');

            foreach ($rows as $row) {
                if (!$debug) {
                    print '.';
                }

                if ($row['dl_count'] > 0) {
                    push_out_host($row['id'], 0, $data_template_id);
                } else {
                    db_execute_prepared('DELETE FROM poller_item WHERE host_id = ?', [$row['id']]);
                }
            }

            $hosts   = cacti_sizeof($rows);
            $outcome = 'rebuilt';

            unregister_process('pushout', 'child', $thread_id);

            break;
    }

    pushout_debug('Polling Ending');
} catch (Throwable $error) {
    cacti_log('ERROR: Poller cache rebuild worker failed: ' . get_class($error), false, 'WEBLOG');
    kadupul_poller_cache_finish(['status' => 'failed'], 1);
}

kadupul_poller_cache_finish(['status' => 'ok', 'outcome' => $outcome, 'hosts' => (int) $hosts], 0);

/**
 * Counts the enabled devices, starts one child per slice through
 * cli/push_out_hosts.php and waits until no child is registered.
 *
 * @param string|false $host_id
 * @param string|false $host_template_id
 * @param string|false $data_template_id
 * @param int|string $threads
 * @return int the devices counted; 0 after the "no hosts" warning
 */
function pushout_master_handler($forcerun, $host_id, $host_template_id, $data_template_id, $threads): int
{
    $sql_where  = '';
    $sql_params = [];

    if ($host_id !== false) {
        $sql_where .= 'AND id = ?';
        $sql_params[] = $host_id;
    }

    if ($host_template_id !== false) {
        $sql_where .= 'AND host_template_id = ?';
        $sql_params[] = $host_template_id;
    }

    $rows = db_fetch_cell_prepared("SELECT COUNT(id)
		FROM host
		WHERE disabled = '' " . $sql_where, $sql_params);

    if ($rows == 0) {
        print 'WARNING: There are no hosts to process' . PHP_EOL;

        return 0;
    }

    $hosts_per_process = ceil($rows / $threads);

    print "There are $rows hosts, $threads threads and $hosts_per_process hosts to process per thread" . PHP_EOL;

    $h_done = 0;

    for ($thread_id = 1; $h_done < $rows; $thread_id++) {
        pushout_debug("Launching Process ID $thread_id");

        pushout_launch_child($thread_id, $threads, $host_id);

        $h_done += $hosts_per_process;
    }

    $starting = true;

    while (true) {
        if ($starting) {
            sleep(5);
            $starting = false;
        }

        $running = pushout_processes_running();

        if ($running > 0) {
            pushout_debug(sprintf('%s Processes Running, keeping for 2 seconds.', $running));
            sleep(2);
        } else {
            break;
        }
    }

    return (int) $rows;
}

/**
 * pushout_launch_child - this function will launch collector children based upon
 *   the maximum number of threads and the process type
 *
 * @param int $thread_id the thread id to launch
 * @param int|string $threads
 * @param string|false $host_id
 */
function pushout_launch_child($thread_id, $threads, $host_id = false): void
{
    global $config, $debug, $host_template_id, $data_template_id;

    $php_binary = read_config_option('path_php_binary');

    pushout_debug(sprintf('Launching Rebuild poller cache Process Number %s for Type %s', $thread_id, 'child'));

    cacti_log(sprintf('NOTE: Launching Push out hosts Number %s for Type %s', $thread_id, 'child'), true, 'PUSHOUT', POLLER_VERBOSITY_MEDIUM);

    exec_background($php_binary, $config['base_path'] . "/cli/push_out_hosts.php --type=child --threads=$threads --child=$thread_id " . ($debug ? " --debug" : "") . ($host_id !== false ? " --host-id=$host_id" : "") . ($host_template_id ? " --host-template-id=$host_template_id" : "") . ($data_template_id ? " --data-template-id=$data_template_id" : ""));
}

/**
 * pushout_processes_running - determine the number of children that are
 *   currently registered
 *
 * @return mixed the number of running processes
 */
function pushout_processes_running()
{
    $running = db_fetch_cell('SELECT COUNT(*)
		FROM processes
		WHERE tasktype = "pushout"
		AND taskname = "child"');

    if ($running == 0) {
        return 0;
    }

    return $running;
}

/**
 * pushout_debug - this simple routine prints a standard message to the console
 *   when running in debug mode.
 */
function pushout_debug(string $message): void
{
    global $debug;

    if ($debug) {
        print 'PUSHOUT: ' . $message . PHP_EOL;
    }
}

/**
 * sig_handler - provides a generic means to catch exceptions to the Kadupul log.
 *   A second signal while the first is handled is ignored, so a SIGINT that
 *   reaches both the command and this worker is handled once.
 */
function sig_handler(int $signo): void
{
    global $type, $thread_id;
    static $handling = false;

    if ($handling) {
        return;
    }
    $handling = true;

    switch ($signo) {
        case SIGTERM:
        case SIGINT:
            cacti_log('WARNING: Rebuild poller cache terminated by user', false, 'PUSHOUT');

            if (strpos($type, 'rmaster') !== false) {
                pushout_kill_running_processes();
            }

            unregister_process('pushout', 'rmaster', $thread_id, getmypid());

            kadupul_poller_cache_finish(['status' => 'interrupted'], 1);
    }
}

/**
 * pushout_kill_running_processes - this function is part of an interrupt
 *   handler to kill children processes when the parent is killed
 */
function pushout_kill_running_processes(): void
{
    $processes = db_fetch_assoc_prepared(
        'SELECT *
		FROM processes
		WHERE tasktype = "pushout"
		AND taskname IN ("child")
		AND pid != ?',
        [getmypid()]
    );

    if (cacti_sizeof($processes)) {
        foreach ($processes as $p) {
            if (cacti_process_still_running($p['pid'])) {
                cacti_log(sprintf('WARNING: Killing Cleanup %s PID %d due to another due to signal or overrun.', ucfirst($p['taskname']), $p['pid']), false, 'PUSHOUT');
                if (!cacti_process_kill($p['pid'], SIGTERM, 'PUSHOUT') && cacti_process_kill_denied($p['pid'])) {
                    cacti_log(sprintf('WARNING: Unable to kill Cleanup %s PID %d owned by another user, leaving it registered.', ucfirst($p['taskname']), $p['pid']), false, 'PUSHOUT');

                    continue;
                }
            }

            unregister_process($p['tasktype'], $p['taskname'], $p['taskid'], $p['pid']);
        }
    }
}
