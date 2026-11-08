<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0010 asks that logout keep the order in which it fires its hooks, revokes
 * the remembered token and tears the session down. This runs the real
 * logout.php with recording stubs for everything it calls, so a Symfony logout
 * can be held to the same sequence.
 */
final class LogoutHookOrderTest extends TestCase
{
    /** @return iterable<string, array{string, bool, list<string>, string|null}> */
    public static function flows(): iterable
    {
        $teardown = ['hook:logout_pre_session_destroy', 'revoke_token', 'cookie_logout', 'session_destroy', 'hook:logout_post_session_destroy', 'hook_function:custom_logout_message'];

        yield 'plain logout ends with the redirect and no page' => ['', false, [...$teardown], null];
        yield 'timeout shows the page' => ['timeout', false, [...$teardown, 'page'], null];
        yield 'suspended account shows the page' => ['disabled', false, [...$teardown, 'page'], null];
        yield 'collector state change shows the page' => ['remote', false, [...$teardown, 'page'], null];
        yield 'a reskinning plugin ends the request' => ['timeout', true, $teardown, null];
        foreach (['revoke_token', 'cookie_logout', 'session_destroy'] as $failure) {
            yield 'failure at ' . $failure => ['timeout', false, array_slice($teardown, 0, array_search($failure, $teardown, true) + 1), $failure];
        }
    }

    /** @param list<string> $expected */
    #[DataProvider('flows')]
    public function testLogoutFiresHooksAndRevokesInOrder(string $action, bool $reskin, array $expected, ?string $failure): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/logout-order-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/auth.php', $this->stubs($reskin, $failure));
        file_put_contents($directory . '/include/global_session.php', '<?php if (session_status() !== PHP_SESSION_ACTIVE || ($_SESSION["post_logout"] ?? null) !== "retained") { throw new RuntimeException("Lost post-hook session state"); }');
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', '$_SERVER["REQUEST_METHOD"] = "POST"; $_POST["action"] = $_GET["action"] = $argv[1]; require $argv[2];', $action, $root . '/logout.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $directory,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
        } finally {
            array_map('unlink', glob($directory . '/include/*') ?: []);
            array_map('unlink', glob($directory . '/sess_*') ?: []);
            rmdir($directory . '/include');
            rmdir($directory);
        }

        if ($failure === null) {
            self::assertSame('', $errors);
            self::assertSame(0, $status);
        } else {
            self::assertStringContainsString('Fixture logout failure.', $errors);
            self::assertNotSame(0, $status);
        }
        $events = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $events);
    }

    private function stubs(bool $reskin, ?string $failure): string
    {
        $result = $reskin ? 'OPER_MODE_RESKIN' : 'OPER_MODE_NATIVE';
        $failureValue = var_export($failure, true);

        return <<<PHP
            <?php
            define('OPER_MODE_NATIVE', 0);
            define('OPER_MODE_RESKIN', 2);
            define('COPYRIGHT_YEARS_SHORT', '2004-2026');
            \$GLOBALS['events'] = [];
            function note(string \$event): void {
                \$GLOBALS['events'][] = \$event;
                if (\$event === {$failureValue}) { throw new RuntimeException('Fixture logout failure.'); }
            }
            function api_plugin_hook(string \$name): void {
                note('hook:' . \$name);
                if (\$name === 'logout_pre_session_destroy') {
                    \$_SESSION['hook_token'] = 'before';
                } else {
                    if (\$_SESSION !== [] || (\$GLOBALS['version'] ?? null) !== '1.3.0') { throw new RuntimeException('Incorrect post-hook state'); }
                    session_save_path(dirname(__DIR__));
                    if (!session_start(['use_cookies' => false])) { throw new RuntimeException('Fixture session failed'); }
                    \$_SESSION['post_logout'] = 'retained';
                    if ((\$_GET['action'] ?? '') === 'timeout') { \$_GET['action'] = 'remote'; }
                    \$GLOBALS['version'] = 'plugin-version';
                }
            }
            function api_plugin_hook_function(string \$name, \$arg = null) {
                if (\$arg !== OPER_MODE_NATIVE || (\$_SESSION['post_logout'] ?? null) !== 'retained') { throw new RuntimeException('Incorrect custom-hook state'); }
                note('hook_function:' . \$name);
                return {$result};
            }
            function clear_auth_cookie(): void {
                if ((\$_SESSION['hook_token'] ?? null) !== 'before') { throw new RuntimeException('Lost pre-hook session change'); }
                note('revoke_token');
            }
            function cacti_cookie_logout(): void { note('cookie_logout'); }
            function cacti_session_destroy(): void { \$_SESSION = []; note('session_destroy'); }
            function csrf_check(bool \$fatal = true): bool { return true; }
            function set_default_action(\$default = ''): void {}
            function get_cacti_version(): string { return '1.3.0'; }
            function get_request_var(string \$name, \$default = '') { return \$_GET[\$name] ?? \$default; }
            function __(string \$text, ...\$args): string { return vsprintf(\$text, \$args); }
            function html_common_header(string \$title): void { note('page'); }
            class CactiSecureHeaders {
                public static function getNonceAttribute(): string { return ''; }
                public static function contentSecurityPolicies(): array { return ['Content-Security-Policy' => "default-src 'self'"]; }
            }
            \$_SERVER['APP_ENV'] = 'test';
            \$_SERVER['APP_DEBUG'] = '1';
            \$_SERVER['SHELL_VERBOSITY'] = \$_ENV['SHELL_VERBOSITY'] = '-1';
            register_shutdown_function(function (): void {
                \$html = ob_get_clean();
                if (in_array('page', \$GLOBALS['events'], true) && !str_contains(\$html, 'Version plugin-version')) {
                    throw new RuntimeException('Lost post-hook version change');
                }
                if (in_array('page', \$GLOBALS['events'], true) && (\$_GET['action'] ?? '') === 'remote' && !str_contains(\$html, 'Remote Data Collector')) {
                    throw new RuntimeException('Lost post-hook logout reason');
                }
                if (session_status() === PHP_SESSION_ACTIVE) { session_abort(); }
                print json_encode(\$GLOBALS['events']);
            });
            ob_start();
            PHP;
    }
}
