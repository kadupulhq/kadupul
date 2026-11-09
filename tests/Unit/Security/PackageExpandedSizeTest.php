<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * #110: package readers stop at an expanded-size limit before metadata,
 * signature or XML processing, and shipped signed packages still read.
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

/** Run the production package readers in a child with a fixed memory limit. */
function package_size_run(string $file, string $memory, ?int $limit = null): array
{
    $root = dirname(__DIR__, 3);
    $source = file_get_contents($root . '/lib/import.php');
    expect($source)->not->toBeFalse();
    $program = 'namespace PackageSizeProbe;const POLLER_VERBOSITY_LOW=1;const POLLER_VERBOSITY_MEDIUM=2;$logs=[];'
        . ($limit === null ? '' : 'define("IMPORT_PACKAGE_MAX_EXPANDED_BYTES",' . $limit . ');')
        . 'function cacti_log($m,$o=false,$e="",$l=0){$GLOBALS["logs"][]=$m;}'
        . 'function cacti_sizeof($a){return is_countable($a)?count($a):0;}';
    foreach (['import_package_read', 'import_package_get_details', 'import_package_get_public_key', 'import_read_package_data',
        'is_cacti_public_key', 'get_public_key_sha1', 'get_public_key_sha256', 'get_public_key_kadupul', 'get_public_key', 'xml_to_array'] as $function) {
        $program .= test_php_function_source($source, $function);
    }
    $program .= '$file=' . var_export($file, true) . ';$key="";$details=import_package_get_details($file);$data=import_read_package_data($file,$key);'
        . 'echo json_encode(["name"=>$details["name"]??null,"read"=>is_array($data),"files"=>isset($data["files"]),"logs"=>$GLOBALS["logs"],"peak"=>memory_get_peak_usage(true)]);';

    return test_php_run([PHP_BINARY, '-d', 'memory_limit=' . $memory, '-r', $program]);
}

/** A gzip file that expands to $bytes of XML without building it in memory. */
function package_size_inflating_file(int $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'package-size-');
    expect($path)->not->toBeFalse();
    $out = fopen($path, 'wb');
    $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
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
    $result = package_size_run(dirname(__DIR__, 3) . '/install/templates/Synology_NAS.xml.gz', '128M');
    expect($result['status'])->toBe(0, $result['err']);
    $state = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);

    expect($state['name'])->toBe('Synology NAS')->and($state['read'])->toBeTrue()->and($state['files'])->toBeTrue()
        ->and(implode("\n", $state['logs']))->not->toContain('FATAL');
});

test('a package that expands beyond the limit is refused before it is parsed', function () {
    // 96 MiB of XML from a file of a few hundred kilobytes, read under a 64 MiB memory limit.
    $path = package_size_inflating_file(96 * 1024 * 1024);
    try {
        expect(filesize($path))->toBeLessThan(1024 * 1024);
        $result = package_size_run($path, '64M');
    } finally {
        unlink($path);
    }
    expect($result['status'])->toBe(0, $result['err']);
    $state = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);

    expect($state['name'])->toBeNull()->and($state['read'])->toBeFalse()
        ->and(implode("\n", $state['logs']))->toContain('expands beyond 33554432 bytes and was not read.')
        ->and($state['peak'])->toBeLessThan(64 * 1024 * 1024);
});

test('the limit can be lowered in configuration, and an empty or missing package is refused', function () {
    $lowered = package_size_run(dirname(__DIR__, 3) . '/install/templates/Synology_NAS.xml.gz', '128M', 4096);
    $empty = tempnam(sys_get_temp_dir(), 'package-empty-');
    try {
        $missing = package_size_run(sys_get_temp_dir() . '/no-such-package.xml.gz', '128M');
        $blank = package_size_run($empty, '128M');
    } finally {
        unlink($empty);
    }

    foreach ([$lowered, $missing, $blank] as $result) {
        expect($result['status'])->toBe(0, $result['err']);
    }
    $state = json_decode($lowered['out'], true, 512, JSON_THROW_ON_ERROR);
    expect($state['read'])->toBeFalse()->and(implode("\n", $state['logs']))->toContain('expands beyond 4096 bytes');
    expect(json_decode($missing['out'], true)['read'])->toBeFalse()
        ->and(implode("\n", json_decode($missing['out'], true)['logs']))->toContain('Unable to open');
    expect(json_decode($blank['out'], true)['read'])->toBeFalse()
        ->and(implode("\n", json_decode($blank['out'], true)['logs']))->toContain('Unable to read');
});
