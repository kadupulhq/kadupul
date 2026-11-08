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
 * #110: package readers stop at an expanded-size limit before metadata,
 * signature or XML processing, and shipped signed packages still read.
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function package_size_run($file, $memory, $limit = null) {
	$root    = dirname(__DIR__, 3);
	$source  = file_get_contents($root . '/lib/import.php');
	expect($source)->toBeString();
	$program = 'namespace PackageSizeProbe;const POLLER_VERBOSITY_LOW=1;const POLLER_VERBOSITY_MEDIUM=2;$logs=array();'
		. ($limit === null ? '' : 'define("IMPORT_PACKAGE_MAX_EXPANDED_BYTES",' . $limit . ');')
		. 'function cacti_log($m,$o=false,$e="",$l=0){$GLOBALS["logs"][]=$m;}'
		. 'function cacti_sizeof($a){return is_countable($a)?count($a):0;}';
	foreach (array('import_package_read', 'import_package_get_details', 'import_package_get_public_key', 'import_read_package_data',
		'is_cacti_public_key', 'get_public_key_sha1', 'get_public_key_sha256', 'get_public_key', 'xml_to_array') as $function) {
		$program .= test_php_function_source($source, $function);
	}
	$program .= '$file=' . var_export($file, true) . ';$key="";$details=import_package_get_details($file);$data=import_read_package_data($file,$key);'
		. 'echo json_encode(array("name"=>$details["name"]??null,"read"=>is_array($data),"files"=>isset($data["files"]),"logs"=>$GLOBALS["logs"],"peak"=>memory_get_peak_usage(true)));';

	$process = proc_open(array(PHP_BINARY, '-d', 'memory_limit=' . $memory, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $err);

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

function package_size_inflating_file($bytes) {
	$path = tempnam(sys_get_temp_dir(), 'package-size-');
	expect($path)->toBeString();
	$out     = fopen($path, 'wb');
	$context = deflate_init(ZLIB_ENCODING_GZIP, array('level' => 9));
	fwrite($out, deflate_add($context, '<xml><info><name>large</name></info><files>', ZLIB_NO_FLUSH));
	$chunk = str_repeat("<!-- padding -->\n", 4096);
	for ($written = 0; $written < $bytes; $written += strlen($chunk)) {
		fwrite($out, deflate_add($context, $chunk, ZLIB_NO_FLUSH));
	}
	fwrite($out, deflate_add($context, '</files></xml>', ZLIB_FINISH));
	fclose($out);

	return $path;
}

test('a shipped signed package still reads its details and verified data', function () {
	$state = package_size_run(dirname(__DIR__, 3) . '/install/templates/Synology_NAS.xml.gz', '128M');

	expect($state['name'])->toBe('Synology NAS')->and($state['read'])->toBeTrue()->and($state['files'])->toBeTrue()
		->and(implode("\n", $state['logs']))->not->toContain('FATAL');
});

test('a package that expands beyond the limit is refused before it is parsed', function () {
	$path = package_size_inflating_file(96 * 1024 * 1024);
	try {
		expect(filesize($path))->toBeLessThan(1024 * 1024);
		$state = package_size_run($path, '64M');
	} finally {
		unlink($path);
	}

	expect($state['name'])->toBeNull()->and($state['read'])->toBeFalse()
		->and(implode("\n", $state['logs']))->toContain('expands beyond 33554432 bytes and was not read.')
		->and($state['peak'])->toBeLessThan(64 * 1024 * 1024);
});

test('the limit can be lowered in configuration, and an empty or missing package is refused', function () {
	$lowered = package_size_run(dirname(__DIR__, 3) . '/install/templates/Synology_NAS.xml.gz', '128M', 4096);
	$missing = package_size_run(sys_get_temp_dir() . '/no-such-package.xml.gz', '128M');
	$empty   = tempnam(sys_get_temp_dir(), 'package-empty-');
	try {
		$blank = package_size_run($empty, '128M');
	} finally {
		unlink($empty);
	}

	expect($lowered['read'])->toBeFalse()->and(implode("\n", $lowered['logs']))->toContain('expands beyond 4096 bytes')
		->and($missing['read'])->toBeFalse()->and(implode("\n", $missing['logs']))->toContain('Unable to open')
		->and($blank['read'])->toBeFalse()->and(implode("\n", $blank['logs']))->toContain('Unable to read');
});
