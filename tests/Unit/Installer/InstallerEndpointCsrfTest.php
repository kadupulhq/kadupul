<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('installer JSON requires token-protected POST before dispatch', function ($method, $token, $expected) {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/installer-endpoint-' . bin2hex(random_bytes(8));
    foreach (array('install', 'lib', 'include') as $part) {
        mkdir($dir . '/' . $part, 0700, true);
    }
    // Execute an exact copy of the endpoint with an isolated bootstrap, no DB.
    copy($root . '/install/step_json.php', $dir . '/install/step_json.php');
    file_put_contents($dir . '/lib/functions.php', '<?php');
    file_put_contents($dir . '/lib/html_utility.php', '<?php function set_request_var($name, $value) {}');
    file_put_contents($dir . '/include/auth.php', '<?php if (!is_string($_POST["__csrf_magic"] ?? null)) throw new RuntimeException("Malformed token reached auth bootstrap");');
    file_put_contents($dir . '/install/functions.php', '<?php echo "DISPATCH"; exit;');
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'installer-endpoint-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
session_id('installer-endpoint-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[3];
$_POST = $_GET = $_REQUEST = array();
if ($argv[4] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[4] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
if ($argv[4] === 'array') $_POST['__csrf_magic'] = array(csrf_get_tokens());
if ($argv[4] === 'nested') $_POST['__csrf_magic'] = array(array(csrf_get_tokens()));
if ($argv[4] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[2] . '/install/step_json.php';
PHP;
    try {
        $process = proc_open(array(PHP_BINARY, '-r', $program, $root, $dir, $method, $token), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')->and($stdout)->toBe($expected);
    } finally {
        foreach (array('install', 'lib', 'include') as $part) {
            foreach (glob($dir . '/' . $part . '/*') as $file) {
                unlink($file);
            }
            rmdir($dir . '/' . $part);
        }
        rmdir($dir);
    }
})->with(array(
    array('GET', 'missing', 'STATUS:405'),
    array('GET', 'valid', 'STATUS:405'),
    array('HEAD', 'missing', 'STATUS:405'),
    array('POST', 'missing', 'STATUS:403'),
    array('POST', 'forged', 'STATUS:403'),
    array('POST', 'array', 'STATUS:403'),
    array('POST', 'nested', 'STATUS:403'),
    array('POST', 'query', 'STATUS:403'),
    array('POST', 'valid', 'DISPATCHSTATUS:200'),
));

test('application CSRF bootstrap rejects malformed token shapes', function ($token) {
    $root = dirname(__DIR__, 3);
    $program = <<<'PHP'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('__csrf_magic' => json_decode($argv[2], true));
$config = array('include_path' => $argv[1] . '/include', 'is_web' => true, 'url_path' => '/');
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[1] . '/include/csrf.php';
echo 'UNEXPECTED_DISPATCH';
PHP;
    $process = proc_open(array(PHP_BINARY, '-r', $program, $root, $token), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')->and($stdout)->toBe('STATUS:403');
})->with(array('["token"]', '[["token"]]', '17', 'null'));
