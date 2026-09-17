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
 * poller_boost.php writes create and update commands to one rrdtool pipe, and
 * rrdtool reads them later.  A new RRD therefore does not exist when the update
 * is queued.  1.2.31 accepted that; a failure here marks the whole Boost run
 * failed and keeps the archive tables.
 */

$root = dirname(__DIR__, 4);
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval(str_replace('rrd_acknowledged_pipes', 'boostPipedCreate_rrd_acknowledged_pipes', test_php_function_source(file_get_contents($root . '/lib/rrd.php'), 'rrd_acknowledged_pipes')));
require_once $root . '/lib/rrd_maintenance.php';
foreach (array('rrdtool_last_rejection', 'rrdtool_rejection_is_permanent') as $function) {
    if (!function_exists($function)) { eval(test_php_function_source(file_get_contents($root . '/lib/rrd.php'), $function)); }
}


foreach (array('RRDTOOL_OUTPUT_STDOUT' => 1, 'RRDTOOL_OUTPUT_STDERR' => 2, 'RRDTOOL_OUTPUT_GRAPH_DATA' => 3, 'RRDTOOL_OUTPUT_BOOLEAN' => 4, 'RRDTOOL_OUTPUT_RETURN_STDERR' => 5, 'POLLER_VERBOSITY_NONE' => 1, 'POLLER_VERBOSITY_HIGH' => 4, 'POLLER_VERBOSITY_DEBUG' => 5) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

function boostPipedCreate_cacti_rrdtool_valid_path($path) {
	return is_string($path) && $path !== '' && !preg_match('/[\x00-\x1f\x7f]/', $path);
}

function boostPipedCreate_read_config_option($name) {
	return '';
}

function boostPipedCreate_rrdtool_execute_path_command($command, $path) {
	return file_exists($path);
}

function boostPipedCreate_db_fetch_cell_prepared($sql, $params = array()) {
	return 1;
}

function boostPipedCreate_boost_rrdtool_function_create($local_data_id, $show_source, &$rrdtool_pipe) {
	$state =& $GLOBALS['boost_piped_create'];
	$state['creates']++;

	if ($state['create_writes_file']) {
		touch($state['path']);
	}

	return $state['create_return'];
}

function boostPipedCreate_get_rrdtool_version() {
	return $GLOBALS['boost_piped_create']['version'] ?? '1.7';
}

function boostPipedCreate_cacti_version_compare($a, $b, $operator) {
	return version_compare($a, $b, $operator);
}

function boostPipedCreate_cacti_rrdtool_valid_ds_template($template) {
	return true;
}

function boostPipedCreate_cacti_has_control_chars($value) {
	return preg_match('/[\x00-\x1f\x7f]/', (string) $value) === 1;
}

function boostPipedCreate_cacti_log($message) {
	$GLOBALS['boost_piped_create']['logs'][] = $message;
}

function boostPipedCreate_rrdtool_execute($command, $log = false, $output = null, $pipe = false) {
	$GLOBALS['boost_piped_create']['last_execute_pipe'] = $pipe;
	$GLOBALS['boost_piped_create']['executed'][] = $command;

	if (!empty($GLOBALS['boost_piped_create']['real_binary'])) {
		$reason =& rrdtool_last_rejection();
		$reason = null;
		try {
			$result = boostPipedCreateRealCommand(array_merge(array($GLOBALS['boost_piped_create']['real_binary']), preg_split('/\s+/', trim($command))));
		} catch (RuntimeException $error) {
			$reason = preg_replace('/^ERROR:\s*/', '', trim($error->getMessage()));
			return false;
		}
		return $output === RRDTOOL_OUTPUT_BOOLEAN ? trim($result) === '' : $result;
	}

	/* The update contract requires explicit acknowledgement. */
	return $GLOBALS['boost_piped_create']['execute_return'];
}

function boostPipedCreate_rrd_close($rrdtool_pipe) {
	$GLOBALS['boost_piped_create']['closed']++;

	if (is_resource($rrdtool_pipe)) {
		if (!empty($GLOBALS['boost_piped_create']['real_pipe'])) {
			pclose($rrdtool_pipe);
		} else {
			fclose($rrdtool_pipe);
		}
	}
}

function boostPipedCreate_rrd_init() {
	switch ($GLOBALS['boost_piped_create']['init']) {
		case 'fails':
			return false;
		case 'counted':
			return fopen('boostpipedcreate://restart', 'w');
		default:
			/* the restarted rrdtool cannot take input either */
			return fopen('php://memory', 'r');
	}
}

/* a pipe that refuses every write and counts the handles still open */
class BoostPipedCreateDeadPipe {
	public $context;

	public function stream_open($path, $mode, $options, &$opened_path) {
		$GLOBALS['boost_piped_create']['open_pipes']++;

		return true;
	}

	public function stream_write($data) {
		return false;
	}

	public function stream_flush() {
		return true;
	}

	public function stream_close() {
		$GLOBALS['boost_piped_create']['open_pipes']--;
	}
}

if (!in_array('boostpipedcreate', stream_get_wrappers(), true)) {
	stream_wrapper_register('boostpipedcreate', 'BoostPipedCreateDeadPipe');
}

function boostPipedCreate_escape_command($command) {
	return $command;
}

function boostPipedCreateRrdExecute($root, $command, $rrdtool_pipe) {
	if (!function_exists('boostPipedCreate___rrd_execute')) {
		$source = file_get_contents($root . '/lib/rrd.php');
		$start  = strpos($source, 'function __rrd_execute(');
		$end    = strpos($source, "\nfunction ", $start + 1);

		expect($start)->not->toBeFalse()
			->and($end)->not->toBeFalse();

		eval(preg_replace('/\b(__rrd_execute|rrd_acknowledged_pipes|rrd_close|rrd_init|escape_command|read_config_option|cacti_log)\(/', 'boostPipedCreate_$1(', substr($source, $start, $end - $start)));
	}

	$saved = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;

	$GLOBALS['config'] = array('cacti_server_os' => 'unix');

	/* a write to a dead pipe raises a notice before fwrite() returns false */
	set_error_handler(function () {
		return true;
	});

	try {
		return boostPipedCreate___rrd_execute($command, false, RRDTOOL_OUTPUT_STDOUT, $rrdtool_pipe, 'BOOST');
	} finally {
		restore_error_handler();

		$GLOBALS['config'] = $saved;
	}
}

function boostPipedCreateLoad($root) {
	if (function_exists('boostPipedCreate_boost_rrdtool_function_update')) {
		return;
	}

	if (!function_exists('rrd_check_path')) {
		preg_match('/^function rrd_check_path\(.*?^}\n/ms', file_get_contents($root . '/lib/rrd.php'), $match);
		eval($match[0]);
	}

	$source = file_get_contents($root . '/lib/boost.php');

	foreach (array('boost_rrdtool_pipe_creates', 'boost_rrdtool_function_update') as $name) {
		$start = strpos($source, 'function ' . $name . '(');
		$end   = strpos($source, "\nfunction ", $start + 1);

		expect($start)->not->toBeFalse()
			->and($end)->not->toBeFalse();

		boostPipedCreateEval(substr($source, $start, $end - $start));
	}
}

function boostPipedCreateEval($code) {
	eval(preg_replace('/\b(rrd_close|boost_rrdtool_pipe_creates|boost_rrdtool_get_last_update_time|boost_rrdtool_function_update|boost_rrdtool_function_create|rrdtool_execute_path_command|rrdtool_execute|cacti_rrdtool_valid_ds_template|cacti_rrdtool_valid_path|cacti_has_control_chars|cacti_version_compare|get_rrdtool_version|read_config_option|db_fetch_cell_prepared|cacti_log)\(/', 'boostPipedCreate_$1(', $code));
}

/* The acknowledgement test Boost applies to this return value. */
function boostPipedCreateFails($return_value) {
	return trim((string) $return_value) !== 'OK';
}

beforeEach(function () use ($root) {
    $reason =& rrdtool_last_rejection();
    $reason = null;
	boostPipedCreateLoad($root);

	$this->tmp  = sys_get_temp_dir() . '/boost-piped-create-' . bin2hex(random_bytes(4));
	$this->pipe = fopen('php://memory', 'w');
	mkdir($this->tmp . '/rra', 0700, true);

	$GLOBALS['boost_piped_create'] = array(
		'path'               => $this->tmp . '/rra/12.rrd',
		'creates'            => 0,
		'create_return'      => null,
		'create_writes_file' => false,
		'executed'           => array(),
		'execute_return'     => true,
		'closed'             => 0,
		'init'               => 'dead',
		'open_pipes'         => 0,
		'logs'               => array(),
	);
});

afterEach(function () {
	if (is_resource($this->pipe)) {
		fclose($this->pipe);
	}

	@unlink($this->tmp . '/rra/12.rrd');
	@rmdir($this->tmp . '/rra');
	@rmdir($this->tmp);
});

test('a new RRD created through the rrdtool pipe is updated and acknowledged', function () {
	$values = ' 1000:1 1300:2';
	$path   = $GLOBALS['boost_piped_create']['path'];

	$result = boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $this->pipe);

	expect($result)->toBe('OK')
		->and(boostPipedCreateFails($result))->toBeFalse()
		->and($GLOBALS['boost_piped_create']['creates'])->toBe(1)
		->and($GLOBALS['boost_piped_create']['executed'])->toHaveCount(1)
		->and($GLOBALS['boost_piped_create']['executed'][0])->toStartWith('update ' . $path . ' ');
});

test('later updates for the same new RRD on one pipe do not queue another create', function () {
	$path = $GLOBALS['boost_piped_create']['path'];

	for ($i = 0; $i < 3; $i++) {
		$values = ' ' . (1000 + $i * 300) . ':1';

		expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $this->pipe))->toBe('OK');
	}

	/* rrdtool create overwrites an existing file, so a second create would drop the queued updates */
	expect($GLOBALS['boost_piped_create']['creates'])->toBe(1)
		->and($GLOBALS['boost_piped_create']['executed'])->toHaveCount(3);
});

test('a pipe Boost forgot when closing it sends the create again under the same resource id', function () {
	$path   = $GLOBALS['boost_piped_create']['path'];
	$values = ' 1000:1';

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $this->pipe))->toBe('OK');

	boostPipedCreate_boost_rrdtool_pipe_creates('forget', $this->pipe);

	/* the same handle stands in for a later pipe that reused the id; the file is still missing */
	$values = ' 1300:1';

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $this->pipe))->toBe('OK')
		->and($GLOBALS['boost_piped_create']['creates'])->toBe(2);
});

test('creates queued on a closed pipe do not carry over to the next pipe', function () {
	$path   = $GLOBALS['boost_piped_create']['path'];
	$values = ' 1000:1';
	$first  = fopen('php://memory', 'w');

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $first))->toBe('OK');

	fclose($first);

	$values = ' 1300:1';

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $this->pipe))->toBe('OK')
		->and($GLOBALS['boost_piped_create']['creates'])->toBe(2);
});

test('Boost forgets queued creates at every owned pipe close', function () use ($root) {
	$source = file_get_contents($root . '/lib/boost.php');
	$forget = "boost_rrdtool_pipe_creates('forget', \$rrdtool_pipe);";

	foreach (array('boost_fetch_cache_check', 'boost_process_poller_output') as $name) {
		$start = strpos($source, 'function ' . $name . '(');
		$body  = substr($source, $start, strpos($source, "\nfunction ", $start + 1) - $start);

		expect(preg_match_all('/' . preg_quote($forget, '/') . '\s*rrd_close\(\$rrdtool_pipe\);/', $body))->toBe(substr_count($body, 'rrd_close($rrdtool_pipe);'), $name)
			->and(substr_count($body, 'rrd_close($rrdtool_pipe);'))->toBeGreaterThan(0);
	}

});

test('a create that Boost refused still fails on the pipe', function () {
	$values = ' 1000:1';

	$GLOBALS['boost_piped_create']['create_return'] = false;

	$result = boostPipedCreate_boost_rrdtool_function_update(12, $GLOBALS['boost_piped_create']['path'], '', $values, $this->pipe);

	expect($result)->toBe('ERROR: Unable to create RRD file')
		->and($GLOBALS['boost_piped_create']['executed'])->toBe(array());
});

test('without a pipe the create is still confirmed on disk', function () {
	$values = ' 1000:1';
	$pipe   = false;
	$path   = $GLOBALS['boost_piped_create']['path'];

	$GLOBALS['boost_piped_create']['create_return'] = '';

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $pipe))->toBe('ERROR: Unable to create RRD file')
		->and($GLOBALS['boost_piped_create']['executed'])->toBe(array());

	$GLOBALS['boost_piped_create']['create_writes_file'] = true;

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $pipe))->toBe('OK')
		->and($GLOBALS['boost_piped_create']['executed'])->toHaveCount(1);
});

test('a piped update rrdtool never received is not acknowledged', function () {
	$values = ' 1000:1';

	$GLOBALS['boost_piped_create']['execute_return'] = false;

	$result = boostPipedCreate_boost_rrdtool_function_update(12, $GLOBALS['boost_piped_create']['path'], '', $values, $this->pipe);

	expect(boostPipedCreateFails($result))->toBeTrue()
		->and($GLOBALS['boost_piped_create']['executed'])->toHaveCount(1);
});

test('rrdtool_execute returns false once every pipe restart fails and null for a written command', function () use ($root) {
	$dead = fopen('php://memory', 'r');

	expect(boostPipedCreateRrdExecute($root, 'create /rra/12.rrd --step 300', $dead))->toBeFalse()
		->and($GLOBALS['boost_piped_create']['closed'])->toBe(6)
		->and(implode("\n", $GLOBALS['boost_piped_create']['logs']))->toContain('Restart Attempts Exceeded');

	expect(boostPipedCreateRrdExecute($root, 'update /rra/12.rrd 1000:1', $this->pipe))->toBeNull();
});

test('rrdtool_execute returns false instead of writing to a restart that failed', function () use ($root) {
	$GLOBALS['boost_piped_create']['init'] = 'fails';

	expect(boostPipedCreateRrdExecute($root, 'update /rra/12.rrd 1000:1', fopen('php://memory', 'r')))->toBeFalse()
		->and($GLOBALS['boost_piped_create']['closed'])->toBe(1)
		->and(implode("\n", $GLOBALS['boost_piped_create']['logs']))->toContain('could not be restarted');
});

test('no restarted rrdtool pipe is left open once rrdtool_execute gives up', function () use ($root) {
	$GLOBALS['boost_piped_create']['init'] = 'counted';

	$dead = fopen('boostpipedcreate://first', 'w');

	expect(boostPipedCreateRrdExecute($root, 'update /rra/12.rrd 1000:1', $dead))->toBeFalse()
		->and($GLOBALS['boost_piped_create']['closed'])->toBe(6)
		->and(is_resource($dead))->toBeFalse()
		->and($GLOBALS['boost_piped_create']['open_pipes'])->toBe(0);
});


function boostPipedCreateRealCommand($args) {
	$process = proc_open($args, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ($status !== 0) {
		throw new RuntimeException($stderr);
	}
	return $stdout;
}

test('Boost retries skip consumed timestamps and still apply newer samples in a real RRD', function ($version) {
	$GLOBALS['boost_piped_create']['version'] = $version;
	$binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
	if (!is_executable($binary)) {
		$this->markTestSkipped('RRDtool is required for the real replay check');
	}
	$path = $GLOBALS['boost_piped_create']['path'];
	boostPipedCreateRealCommand(array($binary, 'create', $path, '--start', '1700000000', '--step', '60', 'DS:value:GAUGE:120:U:U', 'RRA:AVERAGE:0.5:1:10'));
	$GLOBALS['boost_piped_create']['real_binary'] = $binary;
	$pipe = false;
	$values = '1700000060:10';
	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, 'value', $values, $pipe))->toBe('OK');
	$before = boostPipedCreateRealCommand(array($binary, 'lastupdate', $path));
	$values = '1700000060:999';
	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, 'value', $values, $pipe))->toBe('OK')
		->and(boostPipedCreateRealCommand(array($binary, 'lastupdate', $path)))->toBe($before);
	$values = '1700000060:999 1700000120:20';
	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, 'value', $values, $pipe))->toBe('OK')
		->and(boostPipedCreateRealCommand(array($binary, 'lastupdate', $path)))->toContain('1700000120: 20');
})->with(array('1.7', '1.4'));

function boostPipedCreate_boost_rrdtool_get_last_update_time($path, &$pipe) {
	if (!empty($GLOBALS['boost_piped_create']['real_binary'])) {
		return trim(boostPipedCreateRealCommand(array($GLOBALS['boost_piped_create']['real_binary'], 'last', $path)));
	}
	return $GLOBALS['boost_piped_create']['last_update'] ?? '0';
}

test('legacy retry refuses to discard samples when the last-update query is unreadable', function () {
	$GLOBALS['boost_piped_create']['version'] = '1.4';
	$GLOBALS['boost_piped_create']['last_update'] = 'ERROR';
	$path = $GLOBALS['boost_piped_create']['path'];
	touch($path);
	$pipe = false;
	$values = '1700000060:10';
	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, 'value', $values, $pipe))->toContain('ERROR:')
		->and($values)->toBe('1700000060:10')
		->and($GLOBALS['boost_piped_create']['executed'])->toBe(array());
});


test('legacy filtering preserves rejection of control characters before tokenizing values', function () {
	$GLOBALS['boost_piped_create']['version'] = '1.4';
	$path = $GLOBALS['boost_piped_create']['path'];
	touch($path);
	$pipe = false;
	$values = "1700000060:10\n1700000120:20";
	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, 'value', $values, $pipe))->toContain('ERROR:')
		->and($values)->toBe("1700000060:10\n1700000120:20")
		->and($GLOBALS['boost_piped_create']['executed'])->toBe(array());
});


test('legacy updates wait for pending real pipe writes before filtering retained samples', function () {
	$binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
	if (!is_executable($binary)) { $this->markTestSkipped('RRDtool is required'); }
	$path = $GLOBALS['boost_piped_create']['path'];
	boostPipedCreateRealCommand(array($binary, 'create', $path, '--start', '1700000000', '--step', '60', 'DS:value:GAUGE:120:U:U', 'RRA:AVERAGE:0.5:1:10'));
	$GLOBALS['boost_piped_create']['version'] = '1.4';
	$GLOBALS['boost_piped_create']['real_binary'] = $binary;
	$GLOBALS['boost_piped_create']['real_pipe'] = true;
	$pipe = popen(escapeshellarg($binary) . ' - > /dev/null', 'w');
	fwrite($pipe, 'update ' . $path . " 1700000060:10\n");
	$values = '1700000060:999 1700000120:20';
	try {
		expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, 'value', $values, $pipe))->toBe('OK')
			->and($pipe)->toBeFalse()
			->and($GLOBALS['boost_piped_create']['closed'])->toBe(1)
			->and($GLOBALS['boost_piped_create']['last_execute_pipe'])->toBeFalse()
			->and($values)->toBe('1700000120:20')
			->and(boostPipedCreateRealCommand(array($binary, 'lastupdate', $path)))->toContain('1700000120: 20');
	} finally {
		if (is_resource($pipe)) { pclose($pipe); }
	}
});


test('Boost retains schema mismatches and replays buffered timestamps after repair', function ($version, $template) {
    $GLOBALS['boost_piped_create']['version'] = $version;
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) { $this->markTestSkipped('RRDtool is required.'); }
    $path = $GLOBALS['boost_piped_create']['path'];
    boostPipedCreateRealCommand(array($binary, 'create', $path, '--start', '1700000000', '--step', '60', 'DS:value:GAUGE:120:U:U', 'RRA:AVERAGE:0.5:1:10'));
    $GLOBALS['boost_piped_create']['real_binary'] = $binary;
    $pipe = false;
    $values = $template === 'value' ? '1700000060:1:2 1700000120:20' : '1700000060:10 1700000120:20';
    expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, $template, $values, $pipe))->toContain('retain samples for retry');
    expect(trim(boostPipedCreateRealCommand(array($binary, 'last', $path))))->toBe('1700000000');
    expect(implode("\n", $GLOBALS['boost_piped_create']['logs']))->not->toContain('Permanently rejected');
    if ($template === 'stale') {
        boostPipedCreateRealCommand(array($binary, 'tune', $path, '--data-source-rename', 'value:stale'));
    } else {
        // Explicitly repair malformed fixture values; a schema mismatch reuses the original buffer.
        $values = '1700000060:10 1700000120:20';
    }
    expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, $template, $values, $pipe))->toBe('OK');
    expect(boostPipedCreateRealCommand(array($binary, 'lastupdate', $path)))->toContain('1700000120: 20');
})->with(array('1.7', '1.4'))->with(array('value', 'stale'));

test('Boost recovery bounds commands while preserving valid later samples', function ($version, $failure) {
    $GLOBALS['boost_piped_create']['version'] = $version;
    $binary = $version === '1.4' ? getenv('RRDTOOL_LEGACY_TEST_BINARY') : getenv('RRDTOOL_TEST_BINARY');
    if (!$binary || !is_executable($binary)) { $this->markTestSkipped('Requested real RRDtool binary is required.'); }
    $path = $GLOBALS['boost_piped_create']['path'];
    boostPipedCreateRealCommand(array($binary, 'create', $path, '--start', '1700000000', '--step', '60', 'DS:value:GAUGE:120:U:U', 'RRA:AVERAGE:0.5:1:1024'));
    $GLOBALS['boost_piped_create']['real_binary'] = $binary;
    $samples = array();
    for ($i = 1; $i <= 512; $i++) {
        $samples[] = (1700000000 + 60 * $i) . (($failure === 'all' || ($failure === 'one' && $i === 1)) ? ':1:2' : ':20');
    }
    $values = implode(' ', $samples);
    $pipe = false;
    $status = boostPipedCreate_boost_rrdtool_function_update(12, $path, $failure === 'schema' ? 'missing' : 'value', $values, $pipe);
    expect(count($GLOBALS['boost_piped_create']['executed']))->toBeLessThanOrEqual($failure === 'schema' ? 1 : ($failure === 'one' ? 19 : 65));
    expect($status)->toContain('retain samples for retry');
    expect(trim(boostPipedCreateRealCommand(array($binary, 'last', $path))))->toBe('1700000000');
    if ($failure === 'schema') {
        boostPipedCreateRealCommand(array($binary, 'tune', $path, '--data-source-rename', 'value:missing'));
        expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, 'missing', $values, $pipe))->toBe('OK');
        expect(trim(boostPipedCreateRealCommand(array($binary, 'last', $path))))->toBe('1700030720');
    }
})->with(array('1.7', '1.4'))->with(array('schema', 'one', 'all'));
