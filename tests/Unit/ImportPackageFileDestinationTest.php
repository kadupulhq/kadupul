<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * import_package() takes the destination of a package file from the package
 * itself. The name must start in scripts/ or resource/, at the Cacti base or
 * in a plugin, and no component may be a symlink that moves the write or the
 * preview read out of the Cacti tree. The real function runs against a temp
 * directory; only signature checks, logging and XML import are stubbed.
 */

$root = dirname(__DIR__, 2);

foreach (array('POLLER_VERBOSITY_LOW' => 2, 'POLLER_VERBOSITY_MEDIUM' => 3, 'OPENSSL_ALGO_SHA1' => 1, 'OPENSSL_ALGO_SHA256' => 7) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

function importPkgDest_import_validate_signature($xmlfile)
{
    return true;
}

function importPkgDest_import_read_package_data($xmlfile, &$public_key)
{
    $public_key = openssl_pkey_get_details($GLOBALS['import_pkg_dest']['key'])['key'];

    return $GLOBALS['import_pkg_dest']['data'];
}

function importPkgDest_cacti_log($message)
{
    $GLOBALS['import_pkg_dest']['logs'][] = $message;
}

function importPkgDest___($text)
{
    return $text;
}

function importPkgDest_cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}

/* the realm decision is not what this test checks */
function importPkgDest_import_data_input_realm_allowed()
{
    return true;
}

function importPkgDest_import_xml_data($xml)
{
    $GLOBALS['import_pkg_dest']['xml'][] = $xml;

    return array();
}

function importPkgDestLoad($root)
{
    if (function_exists('importPkgDest_import_package')) {
        return;
    }

    $functions = file_get_contents($root . '/lib/functions.php');

    foreach (array('validate_relative_path_within', 'cacti_path_is_within') as $name) {
        if (!function_exists($name)) {
            preg_match('/^function ' . $name . '\(.*?^}\n/ms', $functions, $match);
            eval($match[0]);
        }
    }

    $source = file_get_contents($root . '/lib/import.php');
    $start  = strpos($source, 'function import_package(');
    $end    = strpos($source, "\nfunction ", $start + 1);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    eval(preg_replace('/\b(import_package|import_validate_signature|import_read_package_data|cacti_log|__|cacti_sizeof|import_xml_data|import_data_input_realm_allowed)\(/', 'importPkgDest_$1(', substr($source, $start, $end - $start)));
}

function importPkgDestRun($names, $preview = false, $importFiles = array())
{
    $files = array();

    foreach ($names as $name) {
        openssl_sign('payload for ' . $name, $signature, $GLOBALS['import_pkg_dest']['key'], OPENSSL_ALGO_SHA256);
        $files[] = array('name' => $name, 'data' => base64_encode('payload for ' . $name), 'filesignature' => base64_encode($signature));
    }

    /* every real package carries its template, and import_package() needs one */
    openssl_sign('<template/>', $signature, $GLOBALS['import_pkg_dest']['key'], OPENSSL_ALGO_SHA256);
    $files[] = array('name' => 'Test_Template.xml', 'data' => base64_encode('<template/>'), 'filesignature' => base64_encode($signature));

    $GLOBALS['import_pkg_dest']['data'] = array('info' => array(), 'files' => array('file' => $files));

    return importPkgDest_import_package('package.xml.gz', 1, false, false, $preview, false, false, array(), $importFiles);
}

beforeEach(function () use ($root) {
    importPkgDestLoad($root);

    static $key;
    $key = $key ?: openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
    expect($key)->not->toBeFalse();
    $GLOBALS['import_pkg_dest'] = array('logs' => array(), 'xml' => array(), 'key' => $key);

    $this->saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
    $this->tmp          = realpath(sys_get_temp_dir()) . '/import-pkg-dest-' . bin2hex(random_bytes(4));
    $this->base         = $this->tmp . '/cacti';
    $this->outside      = $this->tmp . '/outside';

    foreach (array('/scripts', '/resource/script_server', '/plugins/thold/scripts', '/plugins/thold/resource', '/evil/scripts') as $dir) {
        mkdir($this->base . $dir, 0700, true);
    }

    mkdir($this->outside, 0700);
    file_put_contents($this->outside . '/secret.txt', 'secret');

    $GLOBALS['config']['base_path'] = $this->base;
});

afterEach(function () {
    $GLOBALS['config'] = $this->saved_config;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($this->tmp);
});

test('writes script and resource files at the Cacti base and in a plugin', function () {
    $names = array('scripts/ss_test.php', 'resource/script_server/test.xml', 'plugins/thold/scripts/t.php', 'plugins/thold/resource/t.xml');

    $result = importPkgDestRun($names);

    foreach ($names as $name) {
        expect(file_get_contents($this->base . '/' . $name))->toBe('payload for ' . $name)
            ->and($result[1][$this->base . '/' . $name])->toBe('written');
    }
});

test('normalizes backslash package destinations before dispatching writes', function () {
    $names = array('scripts\\ss_backslash.php', 'plugins\\thold\\resource\\backslash.xml');

    $result = importPkgDestRun($names);

    expect(file_get_contents($this->base . '/scripts/ss_backslash.php'))->toBe('payload for scripts\\ss_backslash.php')
        ->and($result[1][$this->base . '/scripts/ss_backslash.php'])->toBe('written')
        ->and(file_get_contents($this->base . '/plugins/thold/resource/backslash.xml'))->toBe('payload for plugins\\thold\\resource\\backslash.xml')
        ->and($result[1][$this->base . '/plugins/thold/resource/backslash.xml'])->toBe('written');
});

test('matches selective import files after normalizing package destinations', function () {
    $result = importPkgDestRun(array('scripts\\selective.php', 'scripts\\skipped.php'), false, array('scripts/selective.php'));

    expect(file_get_contents($this->base . '/scripts/selective.php'))->toBe('payload for scripts\\selective.php')
        ->and($result[1][$this->base . '/scripts/selective.php'])->toBe('written')
        ->and(file_exists($this->base . '/scripts/skipped.php'))->toBeFalse();
});

test('previews an existing script file', function () {
    file_put_contents($this->base . '/scripts/ss_test.php', 'payload for scripts/ss_test.php');

    $result = importPkgDestRun(array('scripts/ss_test.php'), true);

    expect($result[1])->toBe(array($this->base . '/scripts/ss_test.php' => 'writable, identical'));
});

test('refuses a scripts directory that is not at the Cacti base or in a plugin', function () {
    $result = importPkgDestRun(array('evil/scripts/payload.php', 'plugins/thold/evil/resource/x.php'));

    expect(file_exists($this->base . '/evil/scripts/payload.php'))->toBeFalse()
        ->and($result[1])->toBe(array())
        ->and($GLOBALS['import_pkg_dest']['xml'])->toBe(array('<template/>'));
});

test('refuses a write through a symlinked directory', function () {
    expect(symlink($this->outside, $this->base . '/scripts/link'))->toBeTrue();

    $result = importPkgDestRun(array('scripts/link/payload.php'));

    expect(file_exists($this->outside . '/payload.php'))->toBeFalse()
        ->and($result[1])->toBe(array());
});

test('refuses a write through a dangling symlink', function () {
    expect(symlink($this->outside . '/created.php', $this->base . '/scripts/dangling.php'))->toBeTrue();

    $result = importPkgDestRun(array('scripts/dangling.php'));

    expect(file_exists($this->outside . '/created.php'))->toBeFalse()
        ->and($result[1])->toBe(array());
});

test('refuses to preview a file behind a symlink', function () {
    expect(symlink($this->outside . '/secret.txt', $this->base . '/resource/secret.xml'))->toBeTrue();

    $result = importPkgDestRun(array('resource/secret.xml'), true);

    expect($result[1])->toBe(array());
});


test('rejects traversal and absolute package destinations without writing outside the application', function ($name) {
    $result = importPkgDestRun(array($name));
    expect($result[1])->toBe(array())
        ->and(file_get_contents($this->outside . '/secret.txt'))->toBe('secret')
        ->and(file_exists($this->tmp . '/escaped.txt'))->toBeFalse();
})->with(array('scripts/../../escaped.txt', 'resource/../../outside/secret.txt',
    'scripts/../escaped.txt', 'scripts\\..\\..\\escaped.txt', '/scripts/absolute.txt',
    'C:/scripts/absolute.txt', "scripts/null\0.txt"));
