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
 * Data input commands built by the production get_full_script_path() (poller
 * cache) and get_full_test_script_path() (test_data_source) are parsed by a
 * real /bin/sh. The cases follow Cacti PR #8238 (GHSA-5v3j-wcrr-jxjg).
 */

function data_input_substitution_run(string $kind, string $program, array $arguments = array()): mixed {
	$root = dirname(__DIR__, 4);
	$prelude = '$config = ["is_web" => false, "cacti_server_os" => "unix", "base_path" => $argv[2],'
		. ' "config_options_array" => ["path_php_binary" => "/usr/bin/php", "path_snmpget" => "/usr/bin/snmpget"]];'
		. 'require $argv[1] . "/include/global_constants.php";'
		. 'require $argv[1] . "/lib/functions.php";'
		. 'require $argv[1] . "/tests/fixtures/data-input-substitution-native.php";'
		. 'require $argv[1] . "/tests/fixtures/script-substitution-matrix.php";'
		. '$arguments = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);';
	$base = $arguments['base'] ?? '/opt/kadupul';
	$pipes = array();
	$process = proc_open(
		array(PHP_BINARY, '-r', $prelude . $program, $root, $base, json_encode($arguments, JSON_THROW_ON_ERROR)),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);
	expect(is_resource($process))->toBeTrue($kind);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect($err)->toBe('', $kind);
	expect(proc_close($process))->toBe(0, $kind);

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

test('bundled data input methods keep their command lines', function () {
	$sql = file_get_contents(dirname(__DIR__, 4) . '/cacti.sql');
	preg_match_all("/^INSERT INTO data_input VALUES \\(\\d+,'[0-9a-f]{32}','[^']*','([^']+)',1\\);$/m", $sql, $shipped);

	$methods = array(
		'<path_cacti>/scripts/diskfree.sh <partition>' => array(
			array('partition' => '/dev/sda1'),
			"/opt/kadupul/scripts/diskfree.sh '/dev/sda1'",
			array('/opt/kadupul/scripts/diskfree.sh', '/dev/sda1'),
		),
		'perl <path_cacti>/scripts/loadavg_multi.pl' => array(
			array(),
			'perl /opt/kadupul/scripts/loadavg_multi.pl',
			array('perl', '/opt/kadupul/scripts/loadavg_multi.pl'),
		),
		'perl <path_cacti>/scripts/unix_users.pl <username>' => array(
			array('username' => 'root'),
			"perl /opt/kadupul/scripts/unix_users.pl 'root'",
			array('perl', '/opt/kadupul/scripts/unix_users.pl', 'root'),
		),
		'perl <path_cacti>/scripts/linux_memory.pl <grepstr>' => array(
			array('grepstr' => "Mem Free's"),
			"perl /opt/kadupul/scripts/linux_memory.pl 'Mem Free'\\''s'",
			array('perl', '/opt/kadupul/scripts/linux_memory.pl', "Mem Free's"),
		),
		'perl <path_cacti>/scripts/unix_processes.pl' => array(
			array(),
			'perl /opt/kadupul/scripts/unix_processes.pl',
			array('perl', '/opt/kadupul/scripts/unix_processes.pl'),
		),
		'perl <path_cacti>/scripts/unix_tcp_connections.pl <grepstr>' => array(
			array('grepstr' => ''),
			"perl /opt/kadupul/scripts/unix_tcp_connections.pl ''",
			array('perl', '/opt/kadupul/scripts/unix_tcp_connections.pl', ''),
		),
		'perl <path_cacti>/scripts/webhits.pl <log_path>' => array(
			array('log_path' => '/var/log/httpd/access_log'),
			"perl /opt/kadupul/scripts/webhits.pl '/var/log/httpd/access_log'",
			array('perl', '/opt/kadupul/scripts/webhits.pl', '/var/log/httpd/access_log'),
		),
		'perl <path_cacti>/scripts/ping.pl <ip>' => array(
			array('ip' => '192.0.2.10'),
			"perl /opt/kadupul/scripts/ping.pl '192.0.2.10'",
			array('perl', '/opt/kadupul/scripts/ping.pl', '192.0.2.10'),
		),
		// Script server shape from the bundled device packages.
		'<path_cacti>/scripts/ss_fping.php ss_fping <hostname> <attempts> <protocol> <port>' => array(
			array('hostname' => 'host.example', 'attempts' => '3', 'protocol' => 'ICMP', 'port' => '23'),
			"/opt/kadupul/scripts/ss_fping.php ss_fping 'host.example' '3' 'ICMP' '23'",
			array('/opt/kadupul/scripts/ss_fping.php', 'ss_fping', 'host.example', '3', 'ICMP', '23'),
		),
	);

	expect($shipped[1])->toBe(array_slice(array_keys($methods), 0, 8));

	$result = data_input_substitution_run('bundled', <<<'PHP'
$out = array();
foreach ($arguments['methods'] as $template => $method) {
	$poller = data_input_command('poller', $template, $method[0]);
	$test = data_input_command('test', $template, $method[0], array('id' => 9));
	$out[] = array($template, $poller, $test, data_input_shell_words($poller));
}
echo json_encode($out);
PHP, array('methods' => $methods));

	foreach ($result as [$template, $poller, $test, $shell]) {
		[$fields, $expected, $words] = $methods[$template];
		expect($poller)->toBe($expected, $template);
		// test_data_source passes an empty value as an empty string, not ''.
		expect($test)->toBe($fields === array('grepstr' => '') ? 'perl /opt/kadupul/scripts/unix_tcp_connections.pl ' : $expected, $template);
		expect($shell)->toBe(array('status' => 0, 'words' => $words, 'err' => ''), $template);
	}
});

test('bundled data input methods build the same bytes as 1.2.31 for any field value', function () {
	$sql = file_get_contents(dirname(__DIR__, 4) . '/cacti.sql');
	preg_match_all("/^INSERT INTO data_input VALUES \\(\\d+,'[0-9a-f]{32}','[^']*','([^']+)',1\\);$/m", $sql, $shipped);
	expect($shipped[1])->toHaveCount(8);

	$values = array('', '0', 'plain', 'two words', "it's", '"dq"', '$(id)', '`id`', '${HOME}', 'a\\b', '<other>', 'x<y>z', "tab\there", '; id #', 'é 日本語');
	$result = data_input_substitution_run('legacy-bytes', <<<'PHP'
// The 1.2.31 field loop of get_full_script_path(), kept as the reference.
function legacy_full_script_path($template, $fields) {
	global $config;
	$full_path = $template;
	foreach ($fields as $name => $raw) {
		$value = cacti_escapeshellarg($raw);
		if ($value == '') {
			$value = "''";
		}
		$full_path = str_replace('<' . $name . '>', $value, $full_path);
	}
	$search    = array('<path_cacti>', '<path_snmpget>', '<path_php_binary>');
	$replace   = array($config['base_path'], read_config_option('path_snmpget'), read_config_option('path_php_binary'));
	$full_path = str_replace($search, $replace, $full_path);
	return preg_replace('/(<[A-Za-z0-9_]+>)+/', '', $full_path);
}
$checked = 0;
foreach ($arguments['templates'] as $template) {
	preg_match_all('/<([A-Za-z0-9_]+)>/', $template, $tokens);
	$names = array_values(array_diff($tokens[1], array('path_cacti', 'path_snmpget', 'path_php_binary')));
	foreach ($arguments['values'] as $value) {
		$fields = array();
		foreach ($names as $name) {
			$fields[$name] = $value;
		}
		$expected = legacy_full_script_path($template, $fields);
		$actual = data_input_command('poller', $template, $fields);
		if ($actual !== $expected) {
			throw new RuntimeException('Changed bytes for ' . $template . ' with ' . var_export($value, true) . ': ' . $actual . ' != ' . $expected);
		}
		$checked++;
	}
}
echo json_encode($checked);
PHP, array('templates' => $shipped[1], 'values' => $values));

	expect($result)->toBe(8 * count($values));
});

test('host fields reach test_data_source escaped and unchanged', function () {
	$result = data_input_substitution_run('host', <<<'PHP'
echo json_encode(data_input_command('test', '<path_cacti>/scripts/ss_hstats.php ss_hstats <host_id> <hostname> uptime', array('host_id' => '', 'hostname' => ''), array('id' => 9, 'hostname' => "it's here")));
PHP);

	expect($result)->toBe("/opt/kadupul/scripts/ss_hstats.php ss_hstats '9' 'it'\\''s here' uptime");
});

test('quoted placeholders keep field values literal when the shell runs the command', function (string $caller, string $template, array $words) {
	$result = data_input_substitution_run('quoted', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
$payloads = array(
	'$(touch ' . $marker . ')',
	'`touch ' . $marker . '`',
	'"; touch ' . $marker . '; "',
	"'; touch " . $marker . "; '",
	'it\'s "quoted" $HOME',
);
$out = array();
try {
	foreach ($payloads as $payload) {
		$command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => $payload, 'arg2' => 'two'), array('id' => 9));
		$shell = data_input_shell_words($command);
		$out[] = array($payload, $shell, file_exists($marker));
	}
} finally {
	if (file_exists($marker)) {
		unlink($marker);
	}
	rmdir($directory);
}
echo json_encode($out);
PHP, array('caller' => $caller, 'template' => $template));

	expect($result)->toHaveCount(5);

	foreach ($result as [$payload, $shell, $executed]) {
		expect($executed)->toBeFalse($template);
		$expected = array_map(static fn(string $word): string => str_replace('{1}', $payload, $word), $words);
		expect($shell)->toBe(array('status' => 0, 'words' => $expected, 'err' => ''), $template);
	}
})->with(array('poller', 'test'))->with(array(
	'double quoted' => array('"<arg1>"', array('{1}')),
	'single quoted' => array("'<arg1>'", array('{1}')),
	'double quoted prefix' => array('"--name=<arg1>"', array('--name={1}')),
	'single quoted suffix' => array("'<arg1>.txt'", array('{1}.txt')),
	'two in one word' => array('"<arg1><arg2>"', array('{1}two')),
	'command substitution' => array('"$(printf %s <arg1>)"', array('{1}')),
	'mixed with options' => array('-x "<arg1>" \'<arg2>\' <arg2>', array('-x', '{1}', 'two', 'two')),
));

test('a field value cannot place another field into the command', function () {
	$result = data_input_substitution_run('single-pass', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
$command = data_input_command('poller', 'cmd <arg1> <arg2>', array('arg1' => '<arg2>', 'arg2' => '; touch ' . $marker . ' #'));
$shell = data_input_shell_words($command);
$executed = file_exists($marker);
if ($executed) {
	unlink($marker);
}
rmdir($directory);
echo json_encode(array($shell, $executed, $marker));
PHP);

	[$shell, $executed, $marker] = $result;
	expect($executed)->toBeFalse();
	// As in 1.2.31, token-shaped text inside a value is removed.
	expect($shell)->toBe(array('status' => 0, 'words' => array('cmd', '', '; touch ' . $marker . ' #'), 'err' => ''));
});

test('unsupported grammar inside a command substitution produces no command', function (string $template) {
	$result = data_input_substitution_run('rejected', <<<'PHP'
echo json_encode(array(
	data_input_command('poller', $arguments['template'], array('arg1' => 'value')),
	data_input_command('test', $arguments['template'], array('arg1' => 'value'), array('id' => 9)),
));
PHP, array('template' => $template));

	expect($result)->toBe(array('', ''));
})->with(array(
	'subshell' => array('"$( (true); printf %s <arg1>)"'),
	'case' => array('"$(case x in x) printf %s <arg1>;; esac)"'),
	'comment' => array("\"\$(printf %s # )\n<arg1>)\""),
	'redirect' => array('"$(printf %s > /dev/null; printf %s <arg1>)"'),
	'backtick subshell' => array('"`(true); printf %s <arg1>`"'),
));

test('backslashes inside backticks and fields inside ${} produce no command', function (string $caller, string $template) {
	$result = data_input_substitution_run('nested-rejected', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
try {
	$command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => '$(touch ' . $marker . ')'), array('id' => 9));
	$shell = data_input_shell_words($command);
	$executed = file_exists($marker);
} finally {
	if (file_exists($marker)) {
		unlink($marker);
	}
	rmdir($directory);
}
echo json_encode(array($command, $executed, $shell['status']));
PHP, array('caller' => $caller, 'template' => $template));

	expect($result)->toBe(array('', false, 0));
})->with(array('poller', 'test'))->with(array(
	'escaped quotes in backticks' => array('`printf "%s" \\\\"<arg1>\\\\"`'),
	'parameter default' => array('"${X:-<arg1>}"'),
	'parameter default with prefix' => array('"${X:-x<arg1>}"'),
));

test('bash ANSI-C and locale quoting produce no command', function (string $shell, string $caller, string $template) {
	$path = $shell === 'bash' ? trim((string) shell_exec('command -v bash')) : $shell;
	if ($path === '') {
		$this->markTestSkipped('bash is not installed');
	}

	$result = data_input_substitution_run('ansi-c-rejected', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
try {
	$command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => '$(touch ' . $marker . ')'), array('id' => 9));
	$shell = data_input_shell_words($command, $arguments['shell']);
	$executed = file_exists($marker);
} finally {
	if (file_exists($marker)) {
		unlink($marker);
	}
	rmdir($directory);
}
echo json_encode(array($command, $executed, $shell['status']));
PHP, array('caller' => $caller, 'template' => $template, 'shell' => $path));

	expect($result)->toBe(array('', false, 0));
})->with(array('/bin/sh', 'bash'))->with(array('poller', 'test'))->with(array(
	'escaped quote in ANSI-C string' => array("$'x\\'<arg1>'"),
	'ANSI-C string before a field' => array("printf %s $'a\\'b' <arg1>"),
	'locale string' => array('$"<arg1>"'),
));

test('a field directly after an unescaped dollar produces no command', function (string $shell, string $caller, string $template) {
	$path = $shell === 'bash' ? trim((string) shell_exec('command -v bash')) : $shell;
	if ($path === '') {
		$this->markTestSkipped('bash is not installed');
	}

	$result = data_input_substitution_run('dollar-rejected', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
try {
	// A backslash and quote at the start of the value desynchronize $'...'.
	$command = data_input_command($arguments['caller'], $arguments['template'], array('arg1' => "\\'\$(touch " . $marker . ') #'), array('id' => 9));
	$shell = data_input_shell_words($command, $arguments['shell']);
	$executed = file_exists($marker);
} finally {
	if (file_exists($marker)) {
		unlink($marker);
	}
	rmdir($directory);
}
echo json_encode(array($command, $executed, $shell['status']));
PHP, array('caller' => $caller, 'template' => $template, 'shell' => $path));

	expect($result)->toBe(array('', false, 0));
})->with(array('/bin/sh', 'bash'))->with(array('poller', 'test'))->with(array(
	'bare' => array('$<arg1>'),
	'after text' => array('x$<arg1>'),
	'double quoted' => array('"$<arg1>"'),
	'single quoted' => array("'$<arg1>'"),
	'after escaped backslash' => array('\\\\$<arg1>'),
	'before a backslash' => array('$\\<arg1>'),
	'after an empty field' => array('$<unknown><arg1>'),
	'after text and an empty field' => array('x$<unknown><arg1>'),
	'quoted after an empty field' => array('$<unknown>"<arg1>"'),
));

test('an empty field does not change how a quoted field is parsed', function (string $shell) {
	$path = $shell === 'bash' ? trim((string) shell_exec('command -v bash')) : $shell;
	if ($path === '') {
		$this->markTestSkipped('bash is not installed');
	}

	$result = data_input_substitution_run('empty-neighbour', <<<'PHP'
$command = data_input_command('poller', '"<unknown><arg1>"', array('arg1' => "\\'\$(printf x) #"));
echo json_encode(array($command, data_input_shell_words($command, $arguments['shell'])));
PHP, array('shell' => $path));

	expect($result[0])->toBe("'\\'\\''\$(printf x) #'");
	expect($result[1]['words'])->toBe(array("\\'\$(printf x) #"));
})->with(array('/bin/sh', 'bash'));

test('test_data_source runs a script server method only when its function name is a plain word', function (string $template, array $fields, bool $expected) {
	$result = data_input_substitution_run('test-source', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
file_put_contents($directory . '/probe.php', '<?php print 1;');
$config['base_path'] = $directory;
$config['config_options_array']['path_php_binary'] = PHP_BINARY;
$config['config_options_array']['data_source_trace'] = '';
try {
	$fields = str_replace('{marker}', $marker, $arguments['fields']);
	$passed = data_input_test_source($arguments['template'], $fields, DATA_INPUT_TYPE_PHP_SCRIPT_SERVER);
	$executed = file_exists($marker);
} finally {
	if (file_exists($marker)) {
		unlink($marker);
	}
	unlink($directory . '/probe.php');
	rmdir($directory);
}
echo json_encode(array($passed, $executed));
PHP, array('template' => $template, 'fields' => $fields));

	expect($result)->toBe(array($expected, false));
})->with(array(
	'function name' => array('<path_cacti>/probe.php probe_fn <arg1>', array('arg1' => 'a b'), true),
	'field in the function position' => array('<path_cacti>/probe.php <arg1>', array('arg1' => "x \$(touch {marker}) '"), false),
));

test('an escaped dollar before a field stays literal', function () {
	$result = data_input_substitution_run('dollar-escaped', <<<'PHP'
$command = data_input_command('poller', 'printf %s \\$<arg1>', array('arg1' => 'a b'));
echo json_encode(array($command, data_input_shell_words($command)));
PHP);

	expect($result[0])->toBe("printf %s \\$'a b'");
	expect($result[1]['words'])->toBe(array('printf', '%s', '$a b'));
});

test('removing an empty or unknown field does not join its neighbours into new syntax', function (string $shell, string $template, string $expected) {
	$path = $shell === 'bash' ? trim((string) shell_exec('command -v bash')) : $shell;
	if ($path === '') {
		$this->markTestSkipped('bash is not installed');
	}

	$result = data_input_substitution_run('neighbour-join', <<<'PHP'
$directory = sys_get_temp_dir() . '/kadupul-data-input-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$marker = $directory . '/executed';
$out = array();
try {
	foreach (array('$(touch ' . $marker . ')', 'x ;touch ' . $marker . "; '") as $payload) {
		// test_data_source passes an empty field as an empty string.
		$command = data_input_command('test', $arguments['template'], array('arg1' => $payload, 'empty' => ''), array('id' => 9));
		data_input_shell_words($command, $arguments['shell']);
		$out[] = array($command === str_replace('{1}', escapeshellarg($payload), $arguments['expected']), file_exists($marker));
		if (file_exists($marker)) {
			unlink($marker);
		}
	}
} finally {
	rmdir($directory);
}
echo json_encode($out);
PHP, array('template' => $template, 'expected' => $expected, 'shell' => $path));

	expect($result)->toBe(array(array(true, false), array(true, false)));
})->with(array('/bin/sh', 'bash'))->with(array(
	'unknown before an escaped quote' => array('\<unknown>"<arg1>"', '\"{1}"'),
	'unknown inside $(' => array('"$<unknown>(<arg1>)"', '"$({1})"'),
	'unknown before a quoted field' => array("$<unknown>'<arg1>'", ''),
	'empty before an escaped quote' => array('\<empty>"<arg1>"', '\"{1}"'),
	'empty inside $(' => array('"$<empty>(<arg1>)"', '"$({1})"'),
	'empty before a quoted field' => array("$<empty>'<arg1>'", ''),
));

test('random templates never let a field value run under sh or bash', function () {
	$bash = trim((string) shell_exec('command -v bash'));
	$shells = $bash === '' ? array('/bin/sh') : array('/bin/sh', $bash);
	$result = data_input_substitution_run('property', 'echo json_encode(script_substitution_property(20261027, 3000, $arguments["shells"]));', array('shells' => $shells));

	expect($result['cases'])->toBe(3000);
	expect($result['commands'])->toBeGreaterThan(1000);
	expect($result['runs'])->toBe($result['commands'] * count($shells));
});

test('parameter expansions without fields keep their command lines', function () {
	$result = data_input_substitution_run('param-kept', <<<'PHP'
$command = data_input_command('poller', 'printf "${X:-%s}" "<arg1>"', array('arg1' => 'a b'));
echo json_encode(array($command, data_input_shell_words($command)));
PHP);

	expect($result[0])->toBe("printf \"\${X:-%s}\" 'a b'");
	expect($result[1]['words'])->toBe(array('printf', '%s', 'a b'));
});

test('raw path settings keep the quoting the template gives them', function () {
	$result = data_input_substitution_run('paths', <<<'PHP'
$command = data_input_command('poller', '"<path_php_binary>" -q "<path_cacti>/scripts/x.php" <arg1>', array('arg1' => 'a b'));
echo json_encode(array($command, data_input_shell_words($command)));
PHP, array('base' => '/opt/kadupul base'));

	expect($result[0])->toBe("\"/usr/bin/php\" -q \"/opt/kadupul base/scripts/x.php\" 'a b'");
	expect($result[1]['words'])->toBe(array('/usr/bin/php', '-q', '/opt/kadupul base/scripts/x.php', 'a b'));
});

test('substitution matrix keeps every value literal through /bin/sh', function () {
	expect(data_input_substitution_run('matrix', 'echo json_encode(script_substitution_matrix());'))->toBe(1331);
});
