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
    /** @return iterable<string, array{string, bool, list<string>}> */
    public static function flows(): iterable
    {
        $teardown = ['hook:logout_pre_session_destroy', 'revoke_token', 'cookie_logout', 'session_destroy', 'hook:logout_post_session_destroy', 'hook_function:custom_logout_message'];

        yield 'plain logout ends with the redirect and no page' => ['', false, [...$teardown]];
        yield 'timeout shows the page' => ['timeout', false, [...$teardown, 'page']];
        yield 'a reskinning plugin ends the request' => ['timeout', true, $teardown];
    }

    /** @param list<string> $expected */
    #[DataProvider('flows')]
    public function testLogoutFiresHooksAndRevokesInOrder(string $action, bool $reskin, array $expected): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/logout-order-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/auth.php', $this->stubs($reskin));
        file_put_contents($directory . '/include/global_session.php', "<?php\n");
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=', '-r', '$_GET["action"] = $argv[1]; require $argv[2];', $action, $root . '/logout.php'],
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
            rmdir($directory . '/include');
            rmdir($directory);
        }

        self::assertSame('', $errors);
        self::assertSame(0, $status);
        $events = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $events);
    }

    private function stubs(bool $reskin): string
    {
        $result = $reskin ? 'OPER_MODE_RESKIN' : 'OPER_MODE_NATIVE';

        return <<<PHP
            <?php
            define('OPER_MODE_NATIVE', 0);
            define('OPER_MODE_RESKIN', 2);
            define('COPYRIGHT_YEARS_SHORT', '2004-2026');
            \$GLOBALS['events'] = [];
            function note(string \$event): void { \$GLOBALS['events'][] = \$event; }
            function api_plugin_hook(string \$name): void { note('hook:' . \$name); }
            function api_plugin_hook_function(string \$name, \$arg = null) { note('hook_function:' . \$name); return {$result}; }
            function clear_auth_cookie(): void { note('revoke_token'); }
            function cacti_cookie_logout(): void { note('cookie_logout'); }
            function cacti_session_destroy(): void { note('session_destroy'); }
            function set_default_action(\$default = ''): void {}
            function get_cacti_version(): string { return '1.3.0'; }
            function get_request_var(string \$name, \$default = '') { return \$_GET[\$name] ?? \$default; }
            function __(string \$text, ...\$args): string { return vsprintf(\$text, \$args); }
            function html_common_header(string \$title): void { note('page'); }
            class CactiSecureHeaders { public static function getNonceAttribute(): string { return ''; } }
            register_shutdown_function(function (): void {
                ob_end_clean();
                print json_encode(\$GLOBALS['events']);
            });
            ob_start();
            PHP;
    }
}
