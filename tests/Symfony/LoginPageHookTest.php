<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0010 keeps the login page's plugin hooks: their order, their arguments
 * and where their output lands. This renders the real auth_login.php for an
 * anonymous visit with recording stubs, so a Symfony login page can be held to
 * the same contract.
 */
final class LoginPageHookTest extends TestCase
{
    /** @return iterable<string, array{int, bool, list<array{string, mixed}>}> */
    public static function pages(): iterable
    {
        $form = [
            ['custom_login', 0],
            ['login_title', 'Login to Kadupul'],
            ['login_before', ['error' => false, 'error_msg' => '', 'username' => '', 'user_enabled' => true, 'action' => '']],
            ['login_after', null],
        ];
        $ldap = [
            $form[0],
            $form[1],
            $form[2],
            ['login_realms', ['1' => ['name' => 'Local', 'selected' => false], '2' => ['name' => 'LDAP', 'selected' => true]]],
            $form[3],
        ];

        yield 'local login' => [1, false, $form];
        yield 'LDAP login asks plugins for the realm list' => [3, false, $ldap];
        yield 'a reskinning plugin stops before the form hooks' => [1, true, [$form[0]]];
    }

    /** @param list<array{string, mixed}> $expected */
    #[DataProvider('pages')]
    public function testLoginPageFiresHooksInOrderWithTheirArguments(int $method, bool $reskin, array $expected): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/login-hooks-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/prelude.php', $this->prelude($method, $reskin));
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-r', 'require $argv[1]; require $argv[2];', $directory . '/prelude.php', $root . '/auth_login.php'],
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
            unlink($directory . '/prelude.php');
            rmdir($directory);
        }

        self::assertSame('', $errors);
        self::assertSame(0, $status);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $result['hooks']);
        if (!$reskin) {
            // The title, form hooks and realm list reach the page in the place they are called.
            self::assertStringContainsString('<!--login_before-->', $result['page']);
            self::assertStringContainsString('<!--login_after-->', $result['page']);
            self::assertLessThan(strpos($result['page'], 'id=\'login_username\''), strpos($result['page'], '<!--login_before-->'));
            self::assertGreaterThan(strpos($result['page'], 'id=\'login_password\''), strpos($result['page'], '<!--login_after-->'));
        }
    }

    private function prelude(int $method, bool $reskin): string
    {
        $custom = $reskin ? 'OPER_MODE_RESKIN' : 'OPER_MODE_NATIVE';

        return <<<PHP
            <?php
            define('OPER_MODE_NATIVE', 0);
            define('OPER_MODE_RESKIN', 2);
            define('CACTI_LOCALE', 'en-US');
            define('COPYRIGHT_YEARS_SHORT', '2004-2026');
            \$GLOBALS['hooks'] = [];
            function api_plugin_hook(string \$name): void { \$GLOBALS['hooks'][] = [\$name, null]; print '<!--' . \$name . '-->'; }
            function api_plugin_hook_function(string \$name, \$arg = null)
            {
                \$GLOBALS['hooks'][] = [\$name, \$arg];
                if (\$name === 'custom_login') { return {$custom}; }
                if (\$name === 'login_before') { print '<!--login_before-->'; }

                return \$arg;
            }
            function set_default_action(\$default = ''): void {}
            function read_config_option(string \$name, \$force = false) { return \$name === 'auth_method' ? '{$method}' : ''; }
            function cacti_require_post_actions(array \$actions): void {}
            function auth_get_username(): string { return ''; }
            function get_cacti_version(): string { return '1.3.0'; }
            function auth_resource_id(\$value) { return (int) \$value; }
            function get_nfilter_request_var(string \$name, \$default = '') { return \$default; }
            function die_html_input_error(string \$name): void { exit(1); }
            function get_selected_theme(): string { return 'modern'; }
            function html_common_header(string \$title): void {}
            function get_current_page(): string { return 'index.php'; }
            function html_escape(string \$value): string { return htmlspecialchars(\$value); }
            function __(string \$text, ...\$args): string { return vsprintf(\$text, \$args); }
            function __esc(string \$text): string { return \$text; }
            function isempty_request_var(string \$name): bool { return true; }
            function cacti_sizeof(\$value): int { return is_array(\$value) ? count(\$value) : 0; }
            function read_user_setting(string \$name, \$default = false) { return 0; }
            function is_realm_allowed(int \$realm): bool { return false; }
            function isset_request_var(string \$name): bool { return false; }
            function kill_session_var(string \$name): void {}
            function sanitize_uri(string \$uri, bool \$strict = true): string { return \$uri; }
            function appendHeaderSuppression(string \$uri): string { return \$uri; }
            function get_auth_realms(bool \$login = false): array { return []; }
            \$config = ['url_path' => '/kadupul/', 'cacti_version' => '1.3.0', 'cacti_server_os' => 'unix'];
            function display_output_messages(\$refresh = false): string { return "''"; }
            function csrf_get_tokens(): string { return 'token'; }
            function get_filter_request_var(string \$name, \$filter = 0, \$options = []) { return ''; }
            class CactiSecureHeaders {
                public static function getNonceAttribute(): string { return ''; }
                public static function getNonce(): string { return ''; }
            }
            register_shutdown_function(function (): void {
                \$page = ob_get_clean();
                print json_encode(['hooks' => \$GLOBALS['hooks'], 'page' => \$page]);
            });
            ob_start();
            PHP;
    }
}
