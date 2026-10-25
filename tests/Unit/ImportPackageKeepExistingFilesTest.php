<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace ImportPackageKeepExistingFilesTest;

/*
 * import_package() writes every script and resource file in a package over the
 * file at the same path, so installing a device template replaced a tracked
 * script with an older copy embedded in the package. The installer now asks it
 * to keep a file that already exists and still write one that is missing. The
 * real import_package() runs here against a temp directory with signature
 * checks, logging and XML import stubbed; the web page and the CLI do not pass
 * the option, so their behavior is checked to be unchanged.
 */

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
    define('POLLER_VERBOSITY_MEDIUM', 3);
}

if (!defined('POLLER_VERBOSITY_LOW')) {
    define('POLLER_VERBOSITY_LOW', 2);
}

const STUBS = <<<'PHP'
    function import_read_package_data($xmlfile, &$public_key)
    {
        $public_key = str_repeat('k', 300);

        return $GLOBALS['keep_existing']['data'];
    }

    function openssl_verify($data, $signature, $key, $algo)
    {
        return 1;
    }

    function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '')
    {
        $GLOBALS['keep_existing']['logs'][] = $message;
    }

    function __($text, ...$args)
    {
        return count($args) ? vsprintf($text, $args) : $text;
    }

    function cacti_sizeof($value)
    {
        return is_array($value) ? count($value) : 0;
    }

    function import_xml_data($xml)
    {
        return array('graph_template' => array());
    }
    PHP;

/** Extract the named top-level functions from a repository file. */
function extract_functions($path, array $names)
{
    $source = file_get_contents($path);
    $code   = '';

    foreach ($names as $name) {
        if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $source, $match) !== 1) {
            throw new \RuntimeException('Unable to extract ' . $name . '()');
        }

        $code .= $match[0];
    }

    return $code;
}

$root = dirname(__DIR__, 2);

// test-only eval of source read from this repository, not external input
eval('namespace ' . __NAMESPACE__ . ';' . STUBS // nosemgrep: php.lang.security.eval-use.eval-use
    . extract_functions($root . '/lib/functions.php', array('validate_relative_path_within', 'cacti_path_is_within'))
    . extract_functions($root . '/lib/import.php', array('import_package')));

function package_data(array $files)
{
    $entries = array();

    foreach ($files as $name => $content) {
        $entries[] = array('name' => $name, 'data' => base64_encode($content), 'filesignature' => '');
    }

    $entries[] = array('name' => 'Test_Template.xml', 'data' => base64_encode('<template/>'), 'filesignature' => '');

    return array('info' => array(), 'files' => array('file' => $entries));
}

/** Import with the options the installer passes, or the ones the page and CLI pass. */
function run_import(array $files, $keep_existing = false, $preview = false, array $import_files = array())
{
    $GLOBALS['keep_existing'] = array('data' => package_data($files), 'logs' => array());

    return import_package('package.xml.gz', 1, false, false, $preview, false, false, array(), $import_files, '', $keep_existing);
}

beforeEach(function () {
    $this->tmp  = realpath(sys_get_temp_dir()) . '/kadupul-keep-existing-' . bin2hex(random_bytes(4));
    $this->base = $this->tmp . '/cacti';

    mkdir($this->base . '/scripts', 0755, true);
    mkdir($this->base . '/resource/snmp_queries', 0755, true);

    $this->saved_config             = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
    $GLOBALS['config']['base_path'] = $this->base;

    $this->package = array(
        'scripts/ss_packaged.php'         => "<?php\n/* older copy from the package */\n",
        'resource/snmp_queries/query.xml' => "<query>packaged</query>\n",
    );
});

afterEach(function () {
    exec('chmod -R u+w ' . escapeshellarg($this->tmp) . ' && rm -rf ' . escapeshellarg($this->tmp));

    $GLOBALS['config'] = $this->saved_config;
});

test('without the option an existing file is still overwritten', function () {
    file_put_contents($this->base . '/scripts/ss_packaged.php', "<?php\n/* newer file in the tree */\n");

    $result = run_import($this->package);

    expect($result)->toBeArray()
        ->and($result[1])->toBe(array('scripts/ss_packaged.php' => 'written', 'resource/snmp_queries/query.xml' => 'written'))
        ->and(file_get_contents($this->base . '/scripts/ss_packaged.php'))->toBe($this->package['scripts/ss_packaged.php']);
});

test('with the option an existing file keeps its content and is reported as kept', function () {
    $tree = "<?php\n/* newer file in the tree */\n";
    file_put_contents($this->base . '/scripts/ss_packaged.php', $tree);

    $result = run_import($this->package, true);

    expect($result)->toBeArray()
        ->and($result[1]['scripts/ss_packaged.php'])->toBe('kept')
        ->and(file_get_contents($this->base . '/scripts/ss_packaged.php'))->toBe($tree)
        ->and($GLOBALS['keep_existing']['logs'])->toContain('Keeping existing file: ' . $this->base . '/scripts/ss_packaged.php')
        ->and($GLOBALS['keep_existing']['logs'])->not->toContain('Writing file: ' . $this->base . '/scripts/ss_packaged.php');
});

test('with the option a file that is missing is still written', function () {
    $result = run_import($this->package, true);

    expect($result)->toBeArray()
        ->and($result[1])->toBe(array('scripts/ss_packaged.php' => 'written', 'resource/snmp_queries/query.xml' => 'written'))
        ->and(file_get_contents($this->base . '/scripts/ss_packaged.php'))->toBe($this->package['scripts/ss_packaged.php'])
        ->and(file_get_contents($this->base . '/resource/snmp_queries/query.xml'))->toBe($this->package['resource/snmp_queries/query.xml']);
});

test('with the option one existing and one missing file are handled independently', function () {
    $tree = "<query>tree</query>\n";
    file_put_contents($this->base . '/resource/snmp_queries/query.xml', $tree);

    $result = run_import($this->package, true);

    expect($result)->toBeArray()
        ->and($result[1])->toBe(array('scripts/ss_packaged.php' => 'written', 'resource/snmp_queries/query.xml' => 'kept'))
        ->and(file_get_contents($this->base . '/resource/snmp_queries/query.xml'))->toBe($tree)
        ->and($GLOBALS['keep_existing']['logs'])->toContain('File creation complete');
});

test('with the option an identical existing file is kept without being rewritten', function () {
    $path = $this->base . '/scripts/ss_packaged.php';
    file_put_contents($path, $this->package['scripts/ss_packaged.php']);
    touch($path, 1000000000);
    clearstatcache();

    $result = run_import($this->package, true);

    clearstatcache();

    expect($result[1]['scripts/ss_packaged.php'])->toBe('kept')
        ->and(filemtime($path))->toBe(1000000000);
});

test('with the option an existing read-only file is kept and does not fail the import', function () {
    $path = $this->base . '/scripts/ss_packaged.php';
    file_put_contents($path, 'tree');
    chmod($path, 0444);
    clearstatcache();

    if (is_writable($path)) {
        $this->markTestSkipped('permissions are not enforced for this user');
    }

    $result = run_import(array('scripts/ss_packaged.php' => $this->package['scripts/ss_packaged.php']), true);

    expect($result)->toBeArray()
        ->and($result[1]['scripts/ss_packaged.php'])->toBe('kept')
        ->and(file_get_contents($path))->toBe('tree');
});

test('without the option an existing read-only file still fails the import', function () {
    $path = $this->base . '/scripts/ss_packaged.php';
    file_put_contents($path, 'tree');
    chmod($path, 0444);
    clearstatcache();

    if (is_writable($path)) {
        $this->markTestSkipped('permissions are not enforced for this user');
    }

    expect(run_import(array('scripts/ss_packaged.php' => $this->package['scripts/ss_packaged.php'])))->toBeFalse();
});

test('the option does not change a preview', function () {
    file_put_contents($this->base . '/scripts/ss_packaged.php', "<?php\n/* newer file in the tree */\n");

    $with    = run_import($this->package, true, true);
    $without = run_import($this->package, false, true);

    expect($with[1])->toBe($without[1])
        ->and($with[1]['scripts/ss_packaged.php'])->toBe('writable, differences')
        ->and($with[1]['resource/snmp_queries/query.xml'])->toBe('writable, new');
});

test('the option applies only to the files selected for import', function () {
    file_put_contents($this->base . '/scripts/ss_packaged.php', 'tree');

    $result = run_import($this->package, true, false, array('resource/snmp_queries/query.xml'));

    expect($result[1])->toBe(array('resource/snmp_queries/query.xml' => 'written'))
        ->and(file_get_contents($this->base . '/scripts/ss_packaged.php'))->toBe('tree');
});

test('the installer asks for existing files to be kept and the page and CLI do not', function () {
    $root = dirname(__DIR__, 2);

    expect(file_get_contents($root . '/lib/installer.php'))
        ->toContain("import_package(\$path . \$package, \$this->profile, false, false, false, false, true, array(), array(), \$info['class'], true)");

    foreach (array('/package_import.php', '/cli/import_package.php') as $caller) {
        expect(preg_match_all('/import_package\(([^;]*)\);/', file_get_contents($root . $caller), $calls))->toBeGreaterThan(0);

        foreach ($calls[1] as $arguments) {
            expect(substr_count($arguments, ','))->toBeLessThan(10);
        }
    }
});
