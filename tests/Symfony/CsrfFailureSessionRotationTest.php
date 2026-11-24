<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A logout POST whose token has expired goes through the configured CSRF
 * failure callback before logout.php runs. The callback moves the visitor to
 * a new session identifier; the identifier the request arrived with must not
 * remain stored or signed in afterwards, in either session engine.
 */
final class CsrfFailureSessionRotationTest extends TestCase
{
    // Runs in a child process: one request signs in and stores its session,
    // the next posts a logout with an expired token to the configured callback.
    // argv: repository root, session engine, scratch directory for the files
    // engine, the CSRF secret and the result.
    private const CHILD = <<<'PHP'
[, $root, $engine, $scratch] = $argv;
$config = ['base_path' => $root, 'library_path' => $root . '/lib', 'include_path' => $root . '/include',
    'url_path' => '/kadupul/', 'is_web' => true, 'proxy_headers' => [], 'path_csrf_web_root' => $root,
    'path_csrf_secret' => $scratch . '/secret.php', 'config_options_array' => ['log_verbosity' => 0, 'selective_debug' => '']];
$database_total_queries = 0;
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
function __($message) { return $message; }
$messages = ['csrf_timeout' => ['message' => 'Session expired', 'type' => 'error']];
file_put_contents($config['path_csrf_secret'], '<?php $secret = ' . var_export(str_repeat('c', 64), true) . ';');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['SERVER_PORT'] = 80;
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');

$schema = null;
if ($engine === 'database') {
    $dsn = (string) getenv('KADUPUL_TEST_MYSQL_DSN');
    $admin = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = 'csrf_rotation_test_' . bin2hex(random_bytes(8));
    $admin->exec('CREATE DATABASE `' . $schema . '`');
    $admin->exec('USE `' . $schema . '`');
    if (preg_match('/CREATE TABLE `sessions`\s*\([^;]*;/s', file_get_contents($root . '/cacti.sql'), $ddl) !== 1) {
        throw new RuntimeException('The shipped sessions table is missing');
    }
    $admin->exec($ddl[0]);
    $database_hostname = 'csrf-fixture';
    $database_port = 1;
    $database_default = $schema;
    $database_sessions = ['csrf-fixture:1:' . $schema => $admin];
    define('KADUPUL_BROWSER_COMPATIBILITY', 1);
    require $root . '/include/session.php';
    $stored = static fn(string $id): bool => (int) $admin->query('SELECT COUNT(*) FROM sessions WHERE id=' . $admin->quote($id))->fetchColumn() > 0;
} else {
    ini_set('session.save_path', $scratch);
    $stored = static fn(string $id): bool => is_file($scratch . '/sess_' . $id);
}

// The request that signed in and stored the session.
session_start();
$previous = session_id();
$_SESSION = ['sess_user_id' => 42, 'sess_user_credential' => str_repeat('a', 64), 'sess_user_epoch' => str_repeat('b', 64)];
session_write_close();
$before = $stored($previous);

// The logout POST, arriving with that identifier and an expired token. The
// library checks POSTs as it loads, so it loads first and the request becomes
// a POST once the token can be computed.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/kadupul/logout.php';
$_GET = [];
$_POST = [];
session_id($previous);
session_start();
require $root . '/include/csrf.php';
csrf_conf('log_file', $scratch . '/csrf.log');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => '', '__csrf_magic' => 'sid:' . csrf_hash($previous, time() - $GLOBALS['csrf']['expires'] - 3600)];

register_shutdown_function(static function () use ($previous, $before, $stored, $engine, $schema, $scratch): void {
    $current = session_id();
    session_write_close();
    $result = [
        'callback' => ($_SESSION['sess_messages']['csrf_timeout']['message'] ?? null) === 'Session expired',
        'rotated' => $current !== $previous,
        'stored before' => $before,
        'previous stored' => $stored($previous),
        'current stored' => $stored($current),
    ];
    session_id($previous);
    session_start();
    $result['previous signed in'] = isset($_SESSION['sess_user_id']);
    session_abort();
    session_id($current);
    session_start();
    $result['current signed in'] = ($_SESSION['sess_user_id'] ?? null) === 42;
    session_abort();
    if ($engine === 'database') {
        $GLOBALS['admin']->exec('DROP DATABASE `' . $schema . '`');
    }
    // The callback's redirect discards page output, so report through a file.
    file_put_contents($scratch . '/result.json', json_encode($result, JSON_THROW_ON_ERROR));
});
csrf_check();
PHP;

    public static function engines(): iterable
    {
        yield 'files' => ['files'];
        yield 'database' => ['database'];
    }

    #[DataProvider('engines')]
    public function testExpiredLogoutTokenLeavesNoSessionUnderThePreviousIdentifier(string $engine): void
    {
        if ($engine === 'database' && getenv('KADUPUL_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN for isolated real-schema tests.');
        }
        $scratch = sys_get_temp_dir() . '/csrf-rotation-' . bin2hex(random_bytes(8));
        mkdir($scratch, 0700);
        try {
            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', '-r', self::CHILD, dirname(__DIR__, 2), $engine, $scratch], timeout: 60);
            $process->run();
            self::assertSame('', $process->getErrorOutput());
            self::assertSame(0, $process->getExitCode());
            self::assertSame([
                'callback' => true,
                'rotated' => true,
                'stored before' => true,
                'previous stored' => false,
                'current stored' => true,
                'previous signed in' => false,
                'current signed in' => true,
            ], json_decode((string) @file_get_contents($scratch . '/result.json'), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            foreach (glob($scratch . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($scratch);
        }
    }
}
