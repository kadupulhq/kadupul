<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace ImportPackageWriteFailureTest;

/*
 * import_package() recorded a package file as written whenever fopen()
 * succeeded, so a short write left a truncated script behind, and a file that
 * could not be opened or was not writable still returned the import as a
 * success. The web page then reported success and removed the package, and
 * the CLI exited 0. The real import_package() runs here against a temp
 * directory with signature checks, logging and XML import stubbed, and with
 * fwrite()/fclose() stubbed for one named file so a short write or failed
 * close can be simulated. The page's form_save() and the CLI import block run
 * in a child process because both end in exit.
 */

if (!defined('POLLER_VERBOSITY_LOW')) {
    define('POLLER_VERBOSITY_LOW', 2);
}

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
    define('POLLER_VERBOSITY_MEDIUM', 3);
}

const STUBS = <<<'PHP'
    function import_read_package_data($xmlfile, &$public_key)
    {
        $public_key = str_repeat('k', 300);

        return $GLOBALS['write_failure']['data'];
    }

    function openssl_verify($data, $signature, $key, $algo)
    {
        return 1;
    }

    /* the real cacti_log() prints a message flagged for output on the command line */
    function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '')
    {
        $GLOBALS['write_failure']['logs'][] = $message;

        if ($output && !empty($GLOBALS['write_failure']['print'])) {
            print $message . PHP_EOL;
        }
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

    function write_failure_target($handle)
    {
        $uri = stream_get_meta_data($handle)['uri'];

        return basename($uri) === $GLOBALS['write_failure']['target'];
    }

    /* one byte short for the target file, otherwise the real write */
    function fwrite($handle, $data, $length = null)
    {
        if ($GLOBALS['write_failure']['mode'] === 'short' && write_failure_target($handle)) {
            return \fwrite($handle, substr($data, 0, -1));
        }

        return \fwrite($handle, $data, $length);
    }

    function fclose($handle)
    {
        $fail = $GLOBALS['write_failure']['mode'] === 'close' && write_failure_target($handle);

        \fclose($handle);

        return $fail ? false : true;
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

function import_code()
{
    $root = dirname(__DIR__, 2);

    return extract_functions($root . '/lib/functions.php', array('validate_relative_path_within', 'cacti_path_is_within'))
        . extract_functions($root . '/lib/import.php', array('import_package'));
}

// test-only eval of source read from this repository, not external input
eval('namespace ' . __NAMESPACE__ . ';' . STUBS . import_code()); // nosemgrep: php.lang.security.eval-use.eval-use

function package_data(array $files)
{
    $entries = array();

    foreach ($files as $name => $content) {
        $entries[] = array('name' => $name, 'data' => base64_encode($content), 'filesignature' => '');
    }

    $entries[] = array('name' => 'Test_Template.xml', 'data' => base64_encode('<template/>'), 'filesignature' => '');

    return array('info' => array(), 'files' => array('file' => $entries));
}

function run_import(array $files, $mode = '', $target = '')
{
    $GLOBALS['write_failure'] = array(
        'data'   => package_data($files),
        'logs'   => array(),
        'mode'   => $mode,
        'target' => $target,
    );

    return import_package('package.xml.gz', 1, false, false, false, false, false);
}

/** Run a page or CLI block in a child process against the same stubs and the real import_package(). */
function run_child($base, $block, $prologue, array $files, $mode = '', $target = '')
{
    $dir = dirname($base) . '/child';

    mkdir($dir, 0700);

    $runner = '<?php' . "\n"
        . 'namespace ' . __NAMESPACE__ . 'Child;' . "\n"
        . 'const POLLER_VERBOSITY_LOW = 2; const POLLER_VERBOSITY_MEDIUM = 3; const MESSAGE_LEVEL_INFO = 1; const MESSAGE_LEVEL_ERROR = 3;' . "\n"
        . STUBS . "\n"
        . import_code() . "\n"
        . $prologue . "\n"
        . '$GLOBALS["write_failure"] = array("data" => json_decode(getenv("PACKAGE"), true), "logs" => array(), "mode" => getenv("MODE"), "target" => getenv("TARGET"), "print" => true);' . "\n"
        . '$config = array("base_path" => getenv("BASE"));' . "\n"
        . $block . "\n";

    file_put_contents($dir . '/run.php', $runner);

    $process = proc_open(
        array(PHP_BINARY, $dir . '/run.php'),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $dir,
        array_merge(getenv(), array('BASE' => $base, 'OUT' => $dir, 'PACKAGE' => json_encode(package_data($files)), 'MODE' => $mode, 'TARGET' => $target))
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array('status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr, 'dir' => $dir);
}

function run_page($base, array $files, $mode = '', $target = '')
{
    $prologue = <<<'PHP'
        function validate_request_vars() {}
        function isset_request_var($name) { return $name === 'save_component_import'; }
        function get_filter_request_var($name) { return '1'; }
        function get_request_var($name) { return '1'; }
        function get_nfilter_request_var($name) { return ''; }
        function package_validate_signature($xmlfile) { return true; }
        function import_package_get_name($xmlfile) { return 'Test Package'; }
        function import_package_get_details($xmlfile) { return array('class' => ''); }
        function html_escape($text) { return $text; }
        function raise_message($id, $message, $level) { $GLOBALS['page']['messages'][] = array($id, $message); }
        function header($value) { $GLOBALS['page']['header'] = $value; }

        $_SESSION = array();
        $_POST    = array();
        $_FILES   = array('import_file' => array('tmp_name' => getenv('OUT') . '/upload.xml'));
        $preview_only = false;

        file_put_contents(getenv('OUT') . '/upload.xml', 'uploaded package');

        register_shutdown_function(function () {
            $GLOBALS['page']['session'] = isset($_SESSION['sess_import_package']);
            file_put_contents(getenv('OUT') . '/page.json', json_encode($GLOBALS['page']));
        });
        PHP;

    $root   = dirname(__DIR__, 2);
    $result = run_child($base, extract_functions($root . '/package_import.php', array('package_import_normalize_selected_file', 'form_save')) . 'form_save();', $prologue, $files, $mode, $target);

    $result['page']   = json_decode((string) @file_get_contents($result['dir'] . '/page.json'), true);
    $result['upload'] = file_exists($result['dir'] . '/upload.xml');

    return $result;
}

function run_cli($base, array $files, $mode = '', $target = '')
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/cli/import_package.php');

    expect(preg_match('/^\tif \(\$filename != \'\'\) \{.*?^\t\}\R/ms', $source, $match))->toBe(1);

    $prologue = 'function import_display_results($debug, $filestatus, $web, $preview) { print "RESULTS " . json_encode($filestatus) . PHP_EOL; }' . "\n"
        . 'function display_help() {}' . "\n"
        . '$filename = getenv("OUT") . "/package.xml.gz"; file_put_contents($filename, "package");' . "\n"
        . '$profile_id = 1; $remove_orphans = false; $replace_svalues = false; $preview_only = false;';

    return run_child($base, $match[0], $prologue, $files, $mode, $target);
}

beforeEach(function () {
    $this->tmp  = realpath(sys_get_temp_dir()) . '/kadupul-write-failure-' . bin2hex(random_bytes(4));
    $this->base = $this->tmp . '/cacti';

    mkdir($this->base . '/scripts', 0755, true);
    mkdir($this->base . '/resource/snmp_queries', 0755, true);

    $this->saved_config             = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
    $GLOBALS['config']['base_path'] = $this->base;

    $this->files = array(
        'scripts/ss_first.php'            => "<?php\n/* first */\n",
        'resource/snmp_queries/query.xml' => "<query>\0binary\xff</query>\n",
    );
});

afterEach(function () {
    exec('chmod -R u+w ' . escapeshellarg($this->tmp) . ' && rm -rf ' . escapeshellarg($this->tmp));

    $GLOBALS['config'] = $this->saved_config;
});

test('a complete import writes every file byte for byte and returns its status', function () {
    $result = run_import($this->files);

    expect($result)->toBeArray()
        ->and($result[1])->toBe(array('scripts/ss_first.php' => 'written', 'resource/snmp_queries/query.xml' => 'written'));

    foreach ($this->files as $name => $content) {
        expect(file_get_contents($this->base . '/' . $name))->toBe($content);
    }
});

test('a short write fails the import and is not recorded as written', function () {
    $result = run_import($this->files, 'short', 'query.xml');

    expect($result)->toBeFalse()
        ->and($GLOBALS['write_failure']['logs'])->toContain('Write Status file: ' . $this->base . '/resource/snmp_queries/query.xml, with Status not written')
        ->and($GLOBALS['write_failure']['logs'])->toContain('FATAL: Package file was not fully written: ' . $this->base . '/resource/snmp_queries/query.xml, with Status not written')
        ->and($GLOBALS['write_failure']['logs'])->not->toContain('File creation complete')
        /* the file before the failure stays written */
        ->and(file_get_contents($this->base . '/scripts/ss_first.php'))->toBe($this->files['scripts/ss_first.php']);
});

test('a failed close fails the import', function () {
    $result = run_import($this->files, 'close', 'ss_first.php');

    expect($result)->toBeFalse()
        ->and($GLOBALS['write_failure']['logs'])->toContain('Write Status file: ' . $this->base . '/scripts/ss_first.php, with Status not written');
});

test('an unwritable directory or read-only file fails the import', function ($chmod, $existing) {
    if ($existing) {
        file_put_contents($this->base . '/scripts/ss_first.php', 'kept');
    }

    chmod($this->base . '/' . $chmod, 0500);
    clearstatcache();

    if (is_writable($this->base . '/' . $chmod)) {
        $this->markTestSkipped('permissions are not enforced for this user');
    }

    $result = run_import(array('scripts/ss_first.php' => $this->files['scripts/ss_first.php']));

    expect($result)->toBeFalse()
        ->and($GLOBALS['write_failure']['logs'])->toContain('FATAL: Package file was not fully written: ' . $this->base . '/scripts/ss_first.php, with Status not writable');

    if ($existing) {
        expect(file_get_contents($this->base . '/scripts/ss_first.php'))->toBe('kept');
    } else {
        expect(file_exists($this->base . '/scripts/ss_first.php'))->toBeFalse();
    }
})->with(array(
    'an unwritable directory' => array('scripts', false),
    'a read-only file'        => array('scripts/ss_first.php', true),
));

test('a retry after a short write overwrites the files with the full content', function () {
    expect(run_import($this->files, 'short', 'query.xml'))->toBeFalse();

    $result = run_import($this->files);

    expect($result)->toBeArray();

    foreach ($this->files as $name => $content) {
        expect(file_get_contents($this->base . '/' . $name))->toBe($content);
    }
});

test('the page keeps the uploaded package and reports a failed import', function () {
    $result = run_page($this->base, $this->files, 'short', 'query.xml');

    expect($result['page']['messages'])->toBe(array(array('import_fail', 'The Package Test Package Import Failed')))
        ->and($result['page']['header'])->toBe('Location: package_import.php')
        ->and($result['page']['session'])->toBeTrue()
        ->and($result['upload'])->toBeTrue();
});

test('the page removes the uploaded package after a successful import', function () {
    $result = run_page($this->base, $this->files);

    expect($result['page']['messages'])->toBe(array(array('import_success', 'The Package Test Package Imported Successfully')))
        ->and($result['page']['session'])->toBeFalse()
        ->and($result['upload'])->toBeFalse();

    foreach ($this->files as $name => $content) {
        expect(file_get_contents($this->base . '/' . $name))->toBe($content);
    }
});

test('the CLI exits non-zero and names the file that was not written', function () {
    $result = run_cli($this->base, $this->files, 'short', 'query.xml');

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toContain('FATAL: Package file was not fully written: ' . $this->base . '/resource/snmp_queries/query.xml, with Status not written')
        ->and($result['stdout'])->not->toContain('RESULTS');
});

test('the CLI exits 0 after a complete import', function () {
    $result = run_cli($this->base, $this->files);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('RESULTS {"scripts\/ss_first.php":"written","resource\/snmp_queries\/query.xml":"written"}')
        ->and($result['stderr'])->toBe('');
});
