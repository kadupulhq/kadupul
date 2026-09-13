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
 * cli/install_cacti.php --install printed the step summary after the install in
 * 1.2.31, and provisioning scripts read it. The tail of the script runs in a
 * child process because it may call exit().
 */

function install_cli_summary_run(int $final_step, bool $install_result, bool $should_install = true) : array {
	$source = file_get_contents(dirname(__DIR__, 3) . '/cli/install_cacti.php');
	$start  = strpos($source, "\$message = '';");
	$end    = strpos($source, '/*  get_install_option');

	if ($source === false || $start === false || $end === false) {
		throw new RuntimeException('Unable to extract the install_cacti.php summary block');
	}

	$tail = substr($source, $start, $end - $start);
	$stub = '<?php
class Installer {
	const STEP_INSTALL_CONFIRM = 10;
	const STEP_INSTALL = 97;
	const STEP_COMPLETE = 98;
	const STEP_ERROR = 99;

	public $step = self::STEP_INSTALL_CONFIRM;

	public function getStep() {
		return $this->step;
	}

	public function getErrors() {
		return array();
	}

	public static function beginInstall($time, $installer) {
		$installer->step = ' . $final_step . ';

		return ' . ($install_result ? 'true' : 'false') . ';
	}
}

function log_install_always($section, $text) {
	print "[$section] $text" . PHP_EOL;
}

function log_install_high($section, $text) {
}

function process_install_errors($results) {
	print "errors" . PHP_EOL;
}

$installer      = new Installer();
$should_install = ' . ($should_install ? 'true' : 'false') . ';
$force_install  = false;
' . $tail . '
print "end" . PHP_EOL;
';

	$script = tempnam(sys_get_temp_dir(), 'kadupul_install_cli_');
	file_put_contents($script, $stub);

	$output = array();
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $code);
	@unlink($script);

	return array(implode("\n", $output), $code);
}

test('a completed --install prints the step summary', function () {
	[$output, $code] = install_cli_summary_run(98, true);

	expect($output)->toContain('[cli] Finished installation...')
		->and($output)->toContain('[cli] Installation has now completed, you may launch the web console')
		->and($output)->toEndWith('end')
		->and($code)->toBe(0);
});

test('a failed --install prints the error summary and exits 1', function () {
	[$output, $code] = install_cli_summary_run(99, false);

	expect($output)->toContain('[cli] One or more errors occurred during install, please refer to log files')
		->and($output)->toContain('errors')
		->and($output)->not->toContain('end')
		->and($code)->toBe(1);
});

test('without --install the confirmation summary is unchanged', function () {
	[$output, $code] = install_cli_summary_run(98, true, false);

	expect($output)->toContain('[cli] No errors were detected.  Install not performed as --install not specified')
		->and($output)->not->toContain('Starting installation...')
		->and($code)->toBe(0);
});
