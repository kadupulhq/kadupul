<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Loads the installed csrf-magic.php in a child process with a debug log
// directory and runs $body there; returns what it printed and the log text.
function runCsrfMagicProbe(string $body): array
{
    $directory = sys_get_temp_dir() . '/csrf-magic-probe-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('frame-breaker', false);
    csrf_conf('secret', 'probe-secret-0123456789abcdef0123456789');
    csrf_conf('log_file', $GLOBALS['argv'][2] . '/csrf.log');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/kadupul/graphs.php?probe-query-value';
$_GET = array('probe-get-key' => 'probe-get-value');
$_POST = array('probe-post-key' => array('probe-post-value'));
session_id('probe-session');
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
PHP;

    try {
        $process = proc_open(
            array(PHP_BINARY, '-r', $program . $body, dirname(__DIR__, 3), $directory),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)
            ->and($stderr)->toBe('');

        $log = '';
        foreach (glob($directory . '/*') as $file) {
            $log .= file_get_contents($file);
        }

        return array($stdout, $log);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('token collections and timestamps are bounded before hashing', function () {
    [$stdout] = runCsrfMagicProbe(<<<'PHP'
$valid = 'sid:' . csrf_hash(session_id());
$future = 'sid:' . csrf_hash(session_id(), time() + 3600);
echo json_encode(array(
    'valid' => csrf_check_tokens($valid),
    'valid among eight' => csrf_check_tokens(str_repeat('sid:x,1;', 7) . $valid),
    'valid among nine' => csrf_check_tokens(str_repeat('sid:x,1;', 8) . $valid),
    'non-string entry' => csrf_check_tokens(array(array(), $valid)),
    'future time' => csrf_check_tokens($future),
    'non-numeric time' => csrf_check_tokens('sid:abc,soon'),
    'empty time' => csrf_check_tokens('sid:abc,'),
));
PHP);

    expect(json_decode($stdout, true))->toBe(array(
        'valid' => true,
        'valid among eight' => true,
        'valid among nine' => false,
        'non-string entry' => true,
        'future time' => false,
        'non-numeric time' => false,
        'empty time' => false,
    ));
});

test('generated secrets come from random_bytes', function () {
    [$stdout] = runCsrfMagicProbe(<<<'PHP'
$a = csrf_generate_secret();
$b = csrf_generate_secret();
try {
    csrf_generate_secret(8);
    $short = 'accepted';
} catch (InvalidArgumentException $e) {
    $short = 'refused';
}
echo json_encode(array(preg_match('/\A[0-9a-f]{64}\z/', $a), $a !== $b, $short));
PHP);

    expect(json_decode($stdout, true))->toBe(array(1, true, 'refused'));
});

test('the debug log records no secret, token, form data or query string', function () {
    [$stdout, $log] = runCsrfMagicProbe(<<<'PHP'
$token = csrf_get_tokens();
csrf_check_tokens($token);
csrf_flattenpost($_POST);
csrf_ob_handler('<html><body><form method="post"></form></body></html>', 0);
ob_start();
csrf_callback($token);
$page = ob_get_clean();
echo json_encode(array('hash' => explode(',', substr($token, 4))[0], 'page' => $page));
PHP);
    $result = json_decode($stdout, true);

    expect($log)->toContain('issued token type: sid')
        ->and($log)->toContain('post_fields=1; get_fields=1')
        ->and($log)->not->toContain($result['hash'])
        ->and($log)->not->toContain('probe-secret-0123456789abcdef0123456789')
        ->and($log)->not->toContain('probe-post-value')
        ->and($log)->not->toContain('probe-get-value')
        ->and($log)->not->toContain('probe-query-value')
        ->and($result['page'])->not->toContain($result['hash'])
        ->and($result['page'])->not->toContain('probe-post-value');
});
