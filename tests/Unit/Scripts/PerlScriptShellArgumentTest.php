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
	foreach (array('second-shell', 'access_log', 'access log') as $name) {
		@unlink($this->dir . '/' . $name);
	}

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
