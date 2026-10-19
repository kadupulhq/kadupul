<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConsoleExtensionRuntimeTest extends TestCase
{
    public static function modes(): iterable
    {
        yield 'modern full page' => ['modern', [], 1, ['show_tree', 'show_list', 'show_preview'], true, true];
        yield 'classic full page' => ['classic', [], 1, ['show_tree', 'show_list', 'show_preview'], true, true];
        yield 'explicit full page' => ['modern', ['header' => 'true'], 1, ['show_list'], true, true];
        yield 'body fragment' => ['modern', ['header' => 'false'], 1, ['show_list'], false, false];
        yield 'header fragment' => ['modern', ['headercontent' => '1'], 1, ['show_list'], false, false];
        yield 'authentication disabled' => ['modern', [], 0, ['show_list'], true, false];
    }

    /** @param array<string, string> $query @param list<string> $views */
    #[DataProvider('modes')]
    public function testPrintedNativeChromeIsCapturedWithoutLeakingIntoWorkerOutput(string $theme, array $query, int $authentication, array $views, bool $graphTabs, bool $login): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/tests/Helpers/PhpSource.php';
        $html = file_get_contents($root . '/lib/html.php');
        $functions = file_get_contents($root . '/lib/functions.php');
        self::assertNotFalse($html);
        self::assertNotFalse($functions);
        $native = \test_php_function_source($functions, 'draw_login_status');
        foreach (['html_graph_tabs_right', 'html_escape_charset', 'html_escape'] as $name) {
            $native .= "\n" . \test_php_function_source($html, $name);
        }
        $program = 'require "include/vendor/autoload.php"; eval(' . var_export($native, true) . ');'
            . '$fixture = ' . var_export(compact('theme', 'query', 'authentication', 'views'), true) . ';'
            . <<<'PHP'
// Only environmental dependencies are fixtures. The two native renderers,
// their escaping helpers, the adapter and session-footer include are real.
$config = ['url_path' => '/kadupul/', 'poller_id' => 1, 'cacti_version' => '1.3.0', 'cacti_server_os' => 'unix'];
$_GET = $fixture['query'];
$_SESSION = ['sess_user_id' => 7];
$_SERVER['SCRIPT_NAME'] = '/kadupul/index.php';
$_SERVER['REQUEST_URI'] = '/kadupul/index.php';
$GLOBALS['hooks'] = [];
function isset_request_var($name) { return array_key_exists($name, $_GET); }
function get_nfilter_request_var($name) { return $_GET[$name] ?? ''; }
function get_filter_request_var(...$arguments) { return ''; }
function get_selected_theme() { return $GLOBALS['fixture']['theme']; }
function is_view_allowed($view) { return in_array($view, $GLOBALS['fixture']['views'], true); }
function read_config_option($name) { return match ($name) { 'auth_method' => $GLOBALS['fixture']['authentication'], 'guest_user' => 0, default => '' }; }
function read_user_setting($name) { return 0; }
function get_guest_account() { return 0; }
function is_realm_allowed($realm) { return in_array($realm, [8, 20, 28], true); }
function db_fetch_row_prepared(...$arguments) { return ['username' => '<operator&test>', 'password_change' => 'on', 'realm' => 0]; }
function db_fetch_assoc_prepared(...$arguments) { return []; }
function api_plugin_hook($name) { $GLOBALS['hooks'][] = $name; }
function api_plugin_hook_function($name, $value) { return $value; }
function draw_navigation_text($type = 'url') { return $type === 'title' ? 'Console' : '<nav>Console</nav>'; }
function html_common_header($title) { echo '<meta name="console-title" content="' . html_escape($title) . '">'; }
function html_show_tabs_left() { echo '<nav>Console tabs</nav>'; }
function draw_menu() { echo '<tr><td>Console menu</td></tr>'; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function debug_log_clear() {}
function sanitize_uri($uri) { return $uri; }
function display_output_messages($output) { return '[]'; }
function csrf_get_tokens() { return 'fixture'; }
function __($text, ...$values) { return $values === [] ? $text : sprintf($text, ...$values); }
function __esc($text, ...$values) { return html_escape(__($text, ...$values)); }
class CactiSecureHeaders {
    public static function contentSecurityPolicies() { return ['Content-Security-Policy' => "default-src 'self'"]; }
    public static function getNonce() { return str_repeat('n', 24); }
    public static function getNonceAttribute() { return ''; }
}
ob_start();
$result = (new \Kadupul\Platform\Infrastructure\Legacy\ConsoleExtensionRuntime())->capture();
$leaked = ob_get_clean();
echo json_encode(['result' => $result, 'leaked' => $leaked, 'hooks' => $GLOBALS['hooks']], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'error_reporting=-1', '-r', $program], $root);
        $process->setTimeout(15);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        $observed = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $observed['leaked']);
        $chrome = $observed['result']['chrome'];
        if ($graphTabs) {
            $document = new \DOMDocument();
            @$document->loadHTML($chrome['graph_tabs'], LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            foreach (['show_tree' => 'treeview', 'show_list' => 'listview', 'show_preview' => 'preview'] as $permission => $id) {
                self::assertSame(in_array($permission, $views, true) ? 1 : 0, $xpath->query('//a[@id="' . $id . '"]')->length);
            }
        } else {
            self::assertSame('', $chrome['graph_tabs']);
        }
        if ($login) {
            $document = new \DOMDocument();
            @$document->loadHTML('<div>' . $chrome['login'], LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            self::assertSame('<operator&test>', $xpath->query('//*[@id="user"]')->item(0)?->textContent);
            self::assertSame(1, $xpath->query('//a[@href="/kadupul/logout.php"]')->length);
            self::assertSame(0, $xpath->query('//operator')->length);
        } else {
            self::assertSame('', $chrome['login']);
        }
        foreach (['nav_login_before', 'nav_login_after'] as $hook) {
            self::assertSame($login ? 1 : 0, count(array_filter($observed['hooks'], static fn(string $called): bool => $called === $hook)));
        }
    }
}
