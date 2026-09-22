<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('directory write probes create exclusively and preserve existing files', function ($file, $scenario) {
	$namespace = 'DirectoryProbeCase_' . str_replace(['/', '.'], '_', $file) . '_' . $scenario;
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	$function = test_php_function_source($source, 'is_resource_writable');
	$GLOBALS['probe_entropy_failure'] = $scenario === 'entropy';
	$stubs = <<<'PHP'
function random_bytes($length) {
	if ($GLOBALS['probe_entropy_failure']) throw new \Exception('entropy unavailable');
	return str_repeat("\x2a", $length);
}
PHP;
	eval('namespace ' . $namespace . ';' . $stubs . $function);
	$call = $namespace . '\\is_resource_writable';
	$directory = sys_get_temp_dir() . '/kadupul-probe-' . bin2hex(random_bytes(12));
	mkdir($directory, 0700);
	$probe = $directory . '/' . str_repeat('2a', 16) . '.tmp';
	$target = $directory . '/target';
	try {
		file_put_contents($target, 'preserve me');
		if ($scenario === 'file') file_put_contents($probe, 'collision');
		if ($scenario === 'symlink') symlink($target, $probe);
		if ($scenario === 'dangling') symlink($directory . '/absent', $probe);
		if ($scenario === 'directory') mkdir($probe);
		$path = $scenario === 'missing' ? $directory . '/missing/' : $directory . '/';
		expect($call($path))->toBe($scenario === 'success');
		expect(file_get_contents($target))->toBe('preserve me');
		expect(file_exists($directory . '/absent'))->toBeFalse();
		if ($scenario === 'file') expect(file_get_contents($probe))->toBe('collision');
		if (in_array($scenario, ['symlink', 'dangling'], true)) expect(is_link($probe))->toBeTrue();
		if ($scenario === 'directory') expect(is_dir($probe))->toBeTrue();
		if (in_array($scenario, ['success', 'entropy', 'missing'], true)) expect(file_exists($probe))->toBeFalse();
		// Explicit file-path behavior stays compatible with LTS.
		expect($call($target))->toBeTrue();
		expect(file_get_contents($target))->toBe('preserve me');
	} finally {
		if (is_link($probe) || is_file($probe)) unlink($probe);
		elseif (is_dir($probe)) rmdir($probe);
		unlink($target);
		if (file_exists($directory . '/absent')) unlink($directory . '/absent');
		rmdir($directory);
	}
})->with(['lib/functions.php', 'cli/splice_rrd.php'])
	->with(['success', 'file', 'symlink', 'dangling', 'directory', 'missing', 'entropy']);
