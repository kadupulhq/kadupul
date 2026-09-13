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
 * The poller hands a Data Source input value to webhits.pl and diskfree.pl
 * as one shell-quoted argument, and both scripts paste it into a command
 * string that Perl runs through a second shell. The scripts must refuse an
 * argument carrying shell metacharacters, and give the same output as
 * 1.2.31 for every other argument.
 */

$scriptsDir = dirname(__DIR__, 3) . '/scripts';

$runPerl = function (string $script, array $args): string {
	$process = proc_open(array_merge(array('perl', $script), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	return $output;
};

/* runs a script with $^O set first, which selects the FreeBSD or the other df command */
$runPerlAs = function (string $os, string $script, array $args): string {
	$process = proc_open(array_merge(array('perl', '-e', '$^O = shift @ARGV; $script = shift @ARGV; do $script;', $os, $script), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	/* df block counts drift between two runs; the percentage and layout do not */
	return preg_replace('/megabytes:[0-9]+/', 'megabytes:N', $output);
};

beforeEach(function () {
	if (trim((string) shell_exec('command -v perl')) === '') {
		$this->markTestSkipped('perl is not installed');
	}

	$this->dir    = sys_get_temp_dir() . '/perl-arg-' . bin2hex(random_bytes(4));
	$this->marker = $this->dir . '/second-shell';

	mkdir($this->dir, 0700);
	file_put_contents($this->dir . '/access_log', "a\nb\nc\n");
	file_put_contents($this->dir . '/access log', "a\nb\n");
});

afterEach(function () {
	foreach (array('second-shell', 'access_log', 'access log', 'diskfree-1.2.31.pl') as $name) {
		@unlink($this->dir . '/' . $name);
	}

	@rmdir($this->dir . '/with space');
	@rmdir($this->dir);
});

dataset('shell payloads', array(
	'semicolon' => array('%s; touch %s'),
	'command substitution' => array('%s$(touch %s)'),
	'backticks' => array('%s`touch %s`'),
	'pipe' => array('%s | touch %s'),
	'and list' => array('%s && touch %s'),
	'redirect' => array('%s > %s'),
	'newline' => array("%s\ntouch %s"),
));

test('webhits.pl refuses an argument that would run a second command', function (string $payload) use ($scriptsDir, $runPerl) {
	$output = $runPerl($scriptsDir . '/webhits.pl', array(sprintf($payload, $this->dir . '/access_log', $this->marker)));

	expect(file_exists($this->marker))->toBeFalse()
		->and($output)->toBe('');
})->with('shell payloads');

test('diskfree.pl refuses an argument that would run a second command', function (string $payload) use ($scriptsDir, $runPerl) {
	$output = $runPerl($scriptsDir . '/diskfree.pl', array(sprintf($payload, '/', $this->marker)));

	expect(file_exists($this->marker))->toBeFalse()
		->and($output)->toBe('');
})->with('shell payloads');

test('webhits.pl still counts lines for plain and quoted paths as 1.2.31 did', function () use ($scriptsDir, $runPerl) {
	expect($runPerl($scriptsDir . '/webhits.pl', array($this->dir . '/access_log')))->toBe('3')
		->and($runPerl($scriptsDir . '/webhits.pl', array('"' . $this->dir . '/access log"')))->toBe('2')
		->and($runPerl($scriptsDir . '/webhits.pl', array("'" . $this->dir . "/access log'")))->toBe('2')
		->and($runPerl($scriptsDir . '/webhits.pl', array($this->dir . '/missing_log')))->toBe('');
});

test('diskfree.pl gives the 1.2.31 output for legitimate arguments on both df commands', function () use ($scriptsDir, $runPerlAs) {
	$guard = '# the argument is pasted into a shell command, so refuse shell metacharacters' . "\n"
		. 'if ($ARGV[0] =~ /[`\$;&|<>\r\n]/) {' . "\n\texit;\n}\n\n";

	/* removing the guard must give back the 1.2.31 file byte for byte */
	$legacy = str_replace($guard, '', file_get_contents($scriptsDir . '/diskfree.pl'), $count);

	expect($count)->toBe(1)
		->and(hash('sha256', $legacy))->toBe('498b0e999d4f3e0f3df6e734b79ab823d48bf39723e73d10cb2303487d8ee972');

	file_put_contents($this->dir . '/diskfree-1.2.31.pl', $legacy);
	mkdir($this->dir . '/with space');

	$df_line = explode("\n", trim((string) shell_exec('df -P /')));
	$device  = preg_split('/\s+/', $df_line[1])[0];

	$cases = array(
		'device path'            => array($device),
		'mount point'            => array('/'),
		'no argument'            => array(),
		'path with a space'      => array($this->dir . '/with space'),
		'quoted path with space' => array('"' . $this->dir . '/with space"'),
	);

	$old = array();
	$new = array();

	foreach (array('freebsd', 'linux') as $os) {
		foreach ($cases as $label => $args) {
			$old["$os df command, $label"] = $runPerlAs($os, $this->dir . '/diskfree-1.2.31.pl', $args);
			$new["$os df command, $label"] = $runPerlAs($os, $scriptsDir . '/diskfree.pl', $args);
		}
	}

	/* at least one df command must work on this host, or the comparison proves nothing */
	expect($new)->toBe($old)
		->and(implode('', $new))->toContain('megabytes:');
});
