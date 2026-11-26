<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Security;

use Kadupul\Inventory\Domain\Device;
use Kadupul\Inventory\Domain\NewDevice;
use Kadupul\Platform\Domain\NotificationReceiverChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SnmpAndPluginHardeningTest extends TestCase
{
    private const array RECEIVER_BASE = [
        'description' => 'NOC Receiver',
        'hostname' => 'trap.example.net',
        'disabled' => false,
        'max_log_size' => 31,
        'snmp_version' => 2,
        'snmp_auth_protocol' => '[None]',
        'snmp_priv_protocol' => '[None]',
        'snmp_engine_id' => '',
        'snmp_port' => 162,
        'snmp_message_type' => 1,
        'notes' => '',
    ];

    private const array NO_CREDENTIALS = [
        'snmp_community' => '',
        'snmp_username' => '',
        'snmp_password' => '',
        'snmp_priv_passphrase' => '',
    ];

    /**
     * S10-01: api_plugin_load_realms() loads internal realms first, requires
     * pc.status = 1 for external plugins, and refuses to overwrite core or
     * internal realm filenames.
     */
    public function testPluginLoadRealmsProtectsCoreAndInternalAndRequiresEnabledStatus(): void
    {
        $root = dirname(__DIR__, 3);
        $program = <<<'PHP'
            $root = $argv[1];
            require_once $root . '/tests/Helpers/PhpSource.php';
            $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('CREATE TABLE plugin_config(id INTEGER PRIMARY KEY, directory TEXT, status INTEGER)');
            $db->exec('CREATE TABLE plugin_realms(id INTEGER PRIMARY KEY, plugin TEXT, file TEXT, display TEXT)');
            $db->exec("INSERT INTO plugin_config VALUES (1, 'zplugin', 1), (2, 'disabled_plug', 4), (3, 'awaiting_plug', 2)");
            $db->exec("INSERT INTO plugin_realms VALUES
                (1, 'internal', 'plugins.php', 'Plugin Management'),
                (2, 'zplugin', 'settings.php,plugins.php,zplugin_view.php', 'Hijack And Valid'),
                (3, 'disabled_plug', 'disabled_view.php', 'Disabled Plugin'),
                (4, 'awaiting_plug', 'awaiting_view.php', 'Awaiting Plugin'),
                (5, 'uninstalled_plug', 'uninstalled_view.php', 'Uninstalled Plugin')");
            $GLOBALS['logs'] = [];
            function cacti_log($msg, $output = false, $environ = 'CACTI', $level = '') { $GLOBALS['logs'][] = [$environ, $msg]; }
            function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
            function db_fetch_assoc($sql) { return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
            require $root . '/lib/plugins.php';
            $user_auth_realms = [15 => 'Settings'];
            $user_auth_realm_filenames = ['settings.php' => 15, 'user_admin.php' => 1];
            api_plugin_load_realms();
            echo json_encode([
                'filenames' => $user_auth_realm_filenames,
                'realms' => $user_auth_realms,
                'logs' => $GLOBALS['logs'],
            ], JSON_THROW_ON_ERROR);
            PHP;

        $result = $this->runPhp($program, [$root]);

        self::assertSame(15, $result['filenames']['settings.php']);
        self::assertSame(1, $result['filenames']['user_admin.php']);
        self::assertSame(101, $result['filenames']['plugins.php']);
        self::assertSame(102, $result['filenames']['zplugin_view.php']);
        self::assertArrayNotHasKey('disabled_view.php', $result['filenames']);
        self::assertArrayNotHasKey('awaiting_view.php', $result['filenames']);
        self::assertArrayNotHasKey('uninstalled_view.php', $result['filenames']);
        self::assertArrayNotHasKey(103, $result['realms']);
        self::assertArrayNotHasKey(104, $result['realms']);
        self::assertArrayNotHasKey(105, $result['realms']);

        $securityLogs = array_values(array_filter($result['logs'], static fn(array $entry): bool => $entry[0] === 'SECURITY'));
        self::assertCount(2, $securityLogs);
    }

    /**
     * S10-01 + S10-05: api_plugin_register_realm() validates plugin names and
     * filenames, rejects core/internal collisions, and binds LIKE predicates
     * with prepared placeholders and db_like_escape().
     */
    public function testPluginRegisterRealmRejectsCollisionsInvalidNamesAndWildcardInjection(): void
    {
        $root = dirname(__DIR__, 3);
        $program = <<<'PHP'
            $root = $argv[1];
            require_once $root . '/tests/Helpers/PhpSource.php';
            $dbSource = file_get_contents($root . '/lib/database.php');
            eval(test_php_function_source($dbSource, 'db_like_escape'));
            $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('CREATE TABLE plugin_realms(id INTEGER PRIMARY KEY AUTOINCREMENT, plugin TEXT, file TEXT, display TEXT)');
            $db->exec('CREATE TABLE user_auth_realm(user_id INTEGER, realm_id INTEGER, PRIMARY KEY(user_id, realm_id))');
            $db->exec('CREATE TABLE user_auth_group_realm(group_id INTEGER, realm_id INTEGER, PRIMARY KEY(group_id, realm_id))');
            $db->exec("INSERT INTO plugin_realms(id, plugin, file, display) VALUES (1, 'internal', 'plugins.php', 'Plugin Management')");
            $GLOBALS['logs'] = [];
            $GLOBALS['queries'] = [];
            function cacti_log($msg, $output = false, $environ = 'CACTI', $level = '') { $GLOBALS['logs'][] = [$environ, $msg]; }
            function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
            function array_rekey($rows, ...$args) { return []; }
            function read_config_option($k) { return $k === 'admin_user' ? 1 : 300; }
            function api_plugin_replicate_config_noop() {}
            function db_fetch_assoc($sql) { return []; }
            function db_fetch_assoc_prepared($sql, $params = []) {
                $GLOBALS['queries'][] = [$sql, $params];
                $stmt = $GLOBALS['db']->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            function db_fetch_cell_prepared($sql, $params = []) {
                $stmt = $GLOBALS['db']->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchColumn();
            }
            function db_fetch_row_prepared($sql, $params = []) {
                $stmt = $GLOBALS['db']->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            function db_execute_prepared($sql, $params = []) {
                $stmt = $GLOBALS['db']->prepare($sql);
                $stmt->execute($params);
                return true;
            }
            $config = ['base_path' => $root];
            $user_auth_realm_filenames = ['settings.php' => 15, 'spikekill.php' => 1043];
            require $root . '/lib/plugins.php';
            function plugin_test_setup($plugin, $file, $display) {
                return api_plugin_register_realm($plugin, $file, $display, false);
            }
            $outcomes = [
                'bad_plugin' => plugin_test_setup('../evil', 'view.php', 'Evil'),
                'core_collision' => plugin_test_setup('goodplug', 'settings.php', 'Hijack Core'),
                'spikekill_collision' => plugin_test_setup('goodplug', 'spikekill.php', 'Hijack Spikekill'),
                'internal_collision' => plugin_test_setup('goodplug', 'plugins.php', 'Hijack Internal'),
                'traversal_file' => plugin_test_setup('goodplug', '../outside.php', 'Traversal'),
                'quote_file' => plugin_test_setup('goodplug', 'a" OR 1=1 --.php', 'SQLi'),
                'valid_trimmed' => plugin_test_setup('goodplug', ' my_view.php , other.php ', 'Good View'),
            ];
            $rows = $db->query('SELECT plugin, file, display FROM plugin_realms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'outcomes' => $outcomes,
                'rows' => $rows,
                'queries' => $GLOBALS['queries'],
                'logs' => $GLOBALS['logs'],
            ], JSON_THROW_ON_ERROR);
            PHP;

        $result = $this->runPhp($program, [$root]);

        self::assertFalse($result['outcomes']['bad_plugin']);
        self::assertFalse($result['outcomes']['core_collision']);
        self::assertFalse($result['outcomes']['spikekill_collision']);
        self::assertFalse($result['outcomes']['internal_collision']);
        self::assertFalse($result['outcomes']['traversal_file']);
        self::assertFalse($result['outcomes']['quote_file']);
        self::assertCount(2, $result['rows']);
        self::assertSame(['plugin' => 'goodplug', 'file' => 'my_view.php,other.php', 'display' => 'Good View'], $result['rows'][1]);

        $lookupQueries = array_values(array_filter(
            $result['queries'],
            static fn(array $q): bool => str_contains($q[0], 'FROM plugin_realms') && str_contains($q[0], 'LIKE ?')
        ));
        self::assertCount(1, $lookupQueries);
        self::assertStringNotContainsString('my_view.php', $lookupQueries[0][0]);
        self::assertContains('my\_view.php,%', $lookupQueries[0][1]);
    }

    /**
     * S10-02: format_plugin_row() escapes author, requires, and version and
     * rejects unsafe URL schemes in webpage href via html_safe_href().
     */
    public function testFormatPluginRowEscapesMetadataAndNeutralizesJavascriptHref(): void
    {
        $root = dirname(__DIR__, 3);
        $program = <<<'PHP'
            $root = $argv[1];
            require_once $root . '/tests/Helpers/PhpSource.php';
            $htmlSource = file_get_contents($root . '/lib/html.php');
            $pluginsSource = file_get_contents($root . '/plugins.php');
            eval(test_php_function_source($htmlSource, 'html_safe_href'));
            eval(test_php_function_source($pluginsSource, 'format_plugin_row'));
            function html_escape($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
            function __($s, ...$a) { return $a ? vsprintf($s, $a) : $s; }
            function __esc($s, ...$a) { return html_escape(__($s, ...$a)); }
            function get_request_var($k) { return ''; }
            function filter_value($v, $f) { return html_escape($v); }
            function plugin_actions($p, $t) { return '<td>actions</td>'; }
            $status_names = [0 => 'Not Installed', 1 => 'Active'];
            $config = ['poller_id' => 1, 'url_path' => '/'];
            $xssPlugin = [
                'directory' => 'evil',
                'infoname' => 'Evil',
                'name' => 'Evil Plugin',
                'status' => 0,
                'webpage' => 'javascript:alert(document.cookie)',
                'author' => '<script>alert(1)</script>',
                'requires' => '<b>dep</b>',
                'version' => '1.0"><img src=x onerror=alert(1)>',
            ];
            $encodedHrefPlugin = [
                'directory' => 'encoded',
                'infoname' => 'Encoded',
                'name' => 'Encoded Plugin',
                'status' => 1,
                'webpage' => '&#106;avascript:alert(1)',
                'author' => 'Safe Author',
                'requires' => '',
                'version' => '2.0',
            ];
            $safePlugin = [
                'directory' => 'safe',
                'infoname' => 'Safe',
                'name' => 'Safe Plugin',
                'status' => 1,
                'webpage' => 'https://example.com/plugin?a=1&b=2',
                'author' => 'Alice & Bob',
                'requires' => 'core',
                'version' => '1.2.3',
            ];
            echo json_encode([
                'xss' => format_plugin_row($xssPlugin, false, false, 'plugin_temp'),
                'encoded' => format_plugin_row($encodedHrefPlugin, false, false, 'plugin_temp'),
                'safe' => format_plugin_row($safePlugin, true, false, 'plugin_temp'),
            ], JSON_THROW_ON_ERROR);
            PHP;

        $result = $this->runPhp($program, [$root]);

        self::assertStringContainsString("href='#'", $result['xss']);
        self::assertStringNotContainsString('javascript:', $result['xss']);
        self::assertStringNotContainsString('<script>', $result['xss']);
        self::assertStringNotContainsString('<img', $result['xss']);
        self::assertStringNotContainsString('<b>dep</b>', $result['xss']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result['xss']);
        self::assertStringContainsString('&lt;b&gt;dep&lt;/b&gt;', $result['xss']);
        self::assertStringContainsString('1.0&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $result['xss']);

        self::assertStringContainsString("href='#'", $result['encoded']);
        self::assertStringContainsString("href='https://example.com/plugin?a=1&amp;b=2'", $result['safe']);
    }

    /**
     * S10-03: Both hook dispatchers fail closed on invalid/missing plugin
     * paths, allowlist internal callbacks, reject PHP internal functions and
     * out-of-scope user functions, and require pc.status = 1 for non-config hooks.
     */
    public function testPluginHookDispatchersEnforceAllowlistContainmentAndPluginStatus(): void
    {
        $root = dirname(__DIR__, 3);
        $sandbox = sys_get_temp_dir() . '/kadupul-plugin-hook-test-' . bin2hex(random_bytes(8));
        mkdir($sandbox . '/plugins/good', 0700, true);
        mkdir($sandbox . '/plugins/disabled', 0700, true);
        file_put_contents($sandbox . '/plugins/good/setup.php', "<?php\nfunction good_hook_fn(\$arg) { \$GLOBALS['ran'][] = ['good', \$arg]; return is_string(\$arg) ? \$arg . ':good' : \$arg; }\n");
        file_put_contents($sandbox . '/plugins/disabled/setup.php', "<?php\nfunction disabled_hook_fn(\$arg) { \$GLOBALS['ran'][] = ['disabled', \$arg]; return \$arg; }\nfunction disabled_config_fn(\$arg) { \$GLOBALS['ran'][] = ['disabled_cfg', \$arg]; return \$arg; }\n");

        $program = <<<'PHP'
            $root = $argv[1];
            $sandbox = $argv[2];
            require_once $root . '/tests/Helpers/PhpSource.php';
            $fnSource = file_get_contents($root . '/lib/functions.php');
            foreach (['cacti_path_is_within', 'cacti_normalize_windows_path', 'validate_relative_path_within', 'cacti_plugin_path'] as $fn) {
                eval(test_php_function_source($fnSource, $fn));
            }
            $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('CREATE TABLE plugin_config(id INTEGER PRIMARY KEY, directory TEXT, status INTEGER)');
            $db->exec('CREATE TABLE plugin_hooks(id INTEGER PRIMARY KEY, name TEXT, hook TEXT, file TEXT, `function` TEXT, status INTEGER)');
            $db->exec("INSERT INTO plugin_config VALUES (1, 'good', 1), (2, 'disabled', 4)");
            $db->exec("INSERT INTO plugin_hooks VALUES
                (1, 'internal', 'test_hook', '', 'phpinfo', 1),
                (2, 'internal', 'test_hook', '', 'out_of_scope_fn', 1),
                (3, 'good', 'test_hook', '../outside.php', 'out_of_scope_fn', 1),
                (4, 'good', 'test_hook', 'missing.php', 'out_of_scope_fn', 1),
                (5, 'good', 'test_hook', 'setup.php', 'strtoupper', 1),
                (6, 'good', 'test_hook', 'setup.php', 'out_of_scope_fn', 1),
                (7, 'disabled', 'test_hook', 'setup.php', 'disabled_hook_fn', 1),
                (8, 'uninstalled', 'test_hook', 'setup.php', 'out_of_scope_fn', 1),
                (9, 'good', 'test_hook', 'setup.php', 'good_hook_fn', 1),
                (10, 'internal', 'draw_navigation_text', '', 'plugin_draw_navigation_text', 1),
                (11, 'disabled', 'config_settings', 'setup.php', 'disabled_config_fn', 1),
                (12, 'uninstalled', 'config_settings', 'setup.php', 'out_of_scope_fn', 1)");
            $GLOBALS['ran'] = [];
            $GLOBALS['logs'] = [];
            function out_of_scope_fn($arg) { $GLOBALS['ran'][] = ['out_of_scope', $arg]; return 'PWNED'; }
            function cacti_log($msg, $output = false, $environ = 'CACTI', $level = '') { $GLOBALS['logs'][] = [$environ, $msg]; }
            function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
            function db_table_exists($t) { return true; }
            function debounce_run_notification($k) { return false; }
            function __($s) { return $s; }
            function db_fetch_assoc_prepared($sql, $params = []) {
                $stmt = $GLOBALS['db']->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $config = ['base_path' => $sandbox, 'poller_id' => 1, 'connection' => 'online'];
            $plugins_integrated = [];
            $plugin_hooks = [];
            require $root . '/lib/plugins.php';
            api_plugin_hook('test_hook');
            $fnResult = api_plugin_hook_function('test_hook', 'hello');
            $navResult = api_plugin_hook_function('draw_navigation_text', []);
            api_plugin_hook('config_settings');
            echo json_encode([
                'ran' => $GLOBALS['ran'],
                'fnResult' => $fnResult,
                'navKeys' => array_keys($navResult),
                'securityLogs' => count(array_filter($GLOBALS['logs'], static fn($l) => $l[0] === 'SECURITY')),
            ], JSON_THROW_ON_ERROR);
            PHP;

        try {
            $result = $this->runPhp($program, [$root, $sandbox]);
        } finally {
            foreach ([$sandbox . '/plugins/good/setup.php', $sandbox . '/plugins/disabled/setup.php'] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            foreach ([$sandbox . '/plugins/good', $sandbox . '/plugins/disabled', $sandbox . '/plugins', $sandbox] as $d) {
                if (is_dir($d)) {
                    rmdir($d);
                }
            }
        }

        $ranNames = array_column($result['ran'], 0);
        self::assertNotContains('out_of_scope', $ranNames);
        self::assertNotContains('disabled', $ranNames);
        self::assertContains('good', $ranNames);
        self::assertContains('disabled_cfg', $ranNames);
        self::assertSame('hello:good', $result['fnResult']);
        self::assertSame(['plugins.php:'], $result['navKeys']);
        self::assertGreaterThanOrEqual(10, $result['securityLogs']);
    }

    /**
     * S10-04 + S10-05: INFO readers, plugin_actions(), cli/plugin_manage.php
     * reject traversal plugin names, and plugin_required_for_others() uses
     * prepared LIKE escaping.
     */
    public function testPluginPathContainmentAndRequiredForOthersPreparedQuery(): void
    {
        $root = dirname(__DIR__, 3);
        $sandbox = sys_get_temp_dir() . '/kadupul-plugin-path-test-' . bin2hex(random_bytes(8));
        mkdir($sandbox . '/plugins', 0700, true);
        mkdir($sandbox . '/escape', 0700, true);
        file_put_contents($sandbox . '/escape/INFO', "[info]\nname = escape\ncompat = 1.0.0\nrequires = other:1.0\ncapabilities = remote_collect:1\n");
        file_put_contents($sandbox . '/escape/setup.php', "<?php\n");

        $program = <<<'PHP'
            $root = $argv[1];
            $sandbox = $argv[2];
            require_once $root . '/tests/Helpers/PhpSource.php';
            $fnSource = file_get_contents($root . '/lib/functions.php');
            $dbSource = file_get_contents($root . '/lib/database.php');
            $pluginsPageSource = file_get_contents($root . '/plugins.php');
            foreach (['cacti_path_is_within', 'cacti_normalize_windows_path', 'validate_relative_path_within', 'cacti_plugin_path'] as $fn) {
                eval(test_php_function_source($fnSource, $fn));
            }
            eval(test_php_function_source($dbSource, 'db_like_escape'));
            eval(test_php_function_source($pluginsPageSource, 'plugin_required_for_others'));
            eval(test_php_function_source($pluginsPageSource, 'plugin_actions'));
            define('CACTI_VERSION', '1.3.0');
            $config = ['base_path' => $sandbox, 'poller_id' => 1, 'url_path' => '/'];
            $plugins_integrated = [];
            $info_data = [];
            $GLOBALS['preparedCalls'] = [];
            function cacti_log(...$args) {}
            function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
            function __($s, ...$a) { return $a ? vsprintf($s, $a) : $s; }
            function __esc($s, ...$a) { return htmlspecialchars(__($s, ...$a), ENT_QUOTES, 'UTF-8'); }
            function db_fetch_cell_prepared($sql, $params = []) {
                $GLOBALS['preparedCalls'][] = [$sql, $params];
                return '';
            }
            require $root . '/lib/plugins.php';
            $deps = api_plugin_get_dependencies('../escape');
            $caps = api_plugin_remote_capabilities('../escape');
            $compat = plugin_is_compatible('../escape');
            $actions = plugin_actions(['status' => 99, 'directory' => '../escape', 'name' => 'escape'], 'plugin_temp');
            plugin_required_for_others(['directory' => "foo_bar' OR 1=1 --"], 'plugin_temp');
            echo json_encode([
                'deps' => $deps,
                'caps' => $caps,
                'compat' => $compat['compat'],
                'actions' => $actions,
                'prepared' => $GLOBALS['preparedCalls'],
            ], JSON_THROW_ON_ERROR);
            PHP;

        try {
            $result = $this->runPhp($program, [$root, $sandbox]);
            $cliOut = $this->runCliPluginManageTraversal($root, $sandbox);
        } finally {
            foreach ([$sandbox . '/escape/INFO', $sandbox . '/escape/setup.php'] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            foreach ([$sandbox . '/escape', $sandbox . '/plugins', $sandbox] as $d) {
                if (is_dir($d)) {
                    rmdir($d);
                }
            }
        }

        self::assertFalse($result['deps']);
        self::assertFalse($result['caps']);
        self::assertFalse($result['compat']);
        self::assertStringContainsString('is invalid', $result['actions']);
        self::assertCount(1, $result['prepared']);
        self::assertStringContainsString('WHERE requires LIKE ?', $result['prepared'][0][0]);
        self::assertSame(["%foo\\_bar' OR 1=1 --%"], $result['prepared'][0][1]);
        self::assertSame(1, $cliOut['exit']);
        self::assertStringContainsString('is invalid', $cliOut['stdout']);
    }

    /**
     * S8-05: cacti_snmp_build_binary_command() inserts '--' before target and
     * OID, rejects leading-hyphen OIDs, and snmpagent_notification() inserts
     * '--' before host:port for v1, v2c, and v3 traps.
     */
    public function testSnmpBinaryAndTrapCommandsInsertEndOfOptionsAndRejectOptionOids(): void
    {
        $root = dirname(__DIR__, 3);
        $program = <<<'PHP'
            $root = $argv[1];
            require_once $root . '/include/global_constants.php';
            require_once $root . '/tests/Helpers/PhpSource.php';
            $fnSource = file_get_contents($root . '/lib/functions.php');
            foreach (['cacti_format_ipv6_colon', 'cacti_escapeshellarg', 'cacti_escapeshellcmd', 'cacti_sizeof', 'cacti_is_sensitive_key'] as $fn) {
                eval(test_php_function_source($fnSource, $fn));
            }
            $GLOBALS['logs'] = [];
            $GLOBALS['traps'] = [];
            function cacti_log($msg, $o = false, $env = 'SNMP', $lvl = '') { $GLOBALS['logs'][] = [$env, $msg]; }
            function read_config_option($name) { return $name === 'path_snmptrap' ? '/usr/bin/snmptrap' : '/usr/bin/snmpget'; }
            $config = ['php_snmp_support' => false, 'include_path' => $root . '/include', 'cacti_server_os' => 'unix', 'library_path' => $root . '/lib'];
            require $root . '/lib/snmp.php';
            $validCmd = cacti_snmp_build_binary_command('/usr/bin/snmpget', 'fntevU', ['-c', 'public'], '2c', 2, 1, '-Lf/tmp/pwn:161', '.1.3.6.1');
            $badOidCmd = cacti_snmp_build_binary_command('/usr/bin/snmpget', 'fntevU', ['-c', 'public'], '2c', 2, 1, '192.0.2.1:161', '-Lf/tmp/pwn');
            $spacedBadOidCmd = cacti_snmp_build_binary_command('/usr/bin/snmpget', 'fntevU', ['-c', 'public'], '2c', 2, 1, '192.0.2.1:161', '  --defCommunity=x');
            $badWalk = cacti_snmp_walk('192.0.2.1', 'public', '-Lf/tmp/pwn', '2', '', '', '', '', '', '', 161, 500, 1, 1);

            $trapLib = sys_get_temp_dir() . '/kadupul-trap-lib-' . bin2hex(random_bytes(6));
            mkdir($trapLib, 0700);
            file_put_contents($trapLib . '/poller.php', "<?php\n");
            $config['library_path'] = $trapLib;
            $snmpagent_event_severity = [1 => 'LOW', 2 => 'MEDIUM', 3 => 'HIGH', 4 => 'CRITICAL'];
            function db_fetch_cell_prepared($sql, $params = []) { return '.1.3.6.1.4.1.23925.1.6'; }
            function db_fetch_assoc_prepared($sql, $params = []) {
                if (str_contains($sql, 'snmpagent_cache_notifications')) {
                    return [];
                }
                return [
                    ['id' => 1, 'hostname' => '-Lf/tmp/pwn', 'snmp_port' => 162, 'snmp_version' => 1, 'snmp_community' => 'pub', 'snmp_message_type' => 1, 'snmp_engine_id' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '', 'snmp_priv_protocol' => ''],
                    ['id' => 2, 'hostname' => '-Lf/tmp/pwn', 'snmp_port' => 162, 'snmp_version' => 2, 'snmp_community' => 'pub', 'snmp_message_type' => 2, 'snmp_engine_id' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '', 'snmp_priv_protocol' => ''],
                    ['id' => 3, 'hostname' => '-Lf/tmp/pwn', 'snmp_port' => 162, 'snmp_version' => 3, 'snmp_community' => '', 'snmp_message_type' => 1, 'snmp_engine_id' => '0x80', 'snmp_username' => 'u', 'snmp_password' => 'authpass1', 'snmp_auth_protocol' => 'SHA', 'snmp_priv_passphrase' => 'privpass1', 'snmp_priv_protocol' => 'AES'],
                ];
            }
            function exec_background($bin, $args = '') { $GLOBALS['traps'][] = $args; }
            function sql_save($save, $table) { return 1; }
            require $root . '/lib/snmpagent.php';
            snmpagent_notification('cactiNotifyDeviceDown', 'CACTI-MIB', [], SNMPAGENT_EVENT_SEVERITY_HIGH);
            unlink($trapLib . '/poller.php');
            rmdir($trapLib);

            echo json_encode([
                'validCmd' => $validCmd,
                'badOidCmd' => $badOidCmd,
                'spacedBadOidCmd' => $spacedBadOidCmd,
                'badWalk' => $badWalk,
                'traps' => $GLOBALS['traps'],
            ], JSON_THROW_ON_ERROR);
            PHP;

        $result = $this->runPhp($program, [$root]);

        self::assertSame(
            ['/usr/bin/snmpget', '-O', 'fntevU', '-c', 'public', '-v', '2c', '-t', '2', '-r', '1', '--', '-Lf/tmp/pwn:161', '.1.3.6.1'],
            $result['validCmd']
        );
        self::assertNull($result['badOidCmd']);
        self::assertNull($result['spacedBadOidCmd']);
        self::assertSame([], $result['badWalk']);
        self::assertCount(3, $result['traps']);
        foreach ($result['traps'] as $trapArgs) {
            self::assertStringContainsString(" -- '-Lf/tmp/pwn:162'", $trapArgs);
        }
    }

    /**
     * S8-05: Device::revise(), NewDevice, and NotificationReceiverChange reject
     * leading-hyphen hostnames while accepting valid hostnames and IPv6 forms.
     */
    #[DataProvider('unsafeHostnames')]
    public function testDomainModelsRejectUnsafeHostnames(string $hostname): void
    {
        $device = new Device(1, 'Router', 'router.example.net', '', true, '', '');
        try {
            $device->revise('Router', $hostname, '', true, '', '', $device->revision());
            self::fail('Device::revise() accepted unsafe hostname: ' . $hostname);
        } catch (\InvalidArgumentException) {
            self::assertSame('router.example.net', $device->hostname());
        }

        try {
            new NewDevice(['description' => 'Router', 'hostname' => $hostname]);
            self::fail('NewDevice accepted unsafe hostname: ' . $hostname);
        } catch (\InvalidArgumentException) {
        }

        try {
            new NotificationReceiverChange(['hostname' => $hostname] + self::RECEIVER_BASE, false, self::NO_CREDENTIALS);
            self::fail('NotificationReceiverChange accepted unsafe hostname: ' . $hostname);
        } catch (\InvalidArgumentException) {
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeHostnames(): iterable
    {
        yield 'short flag' => ['-Lf/tmp/pwn'];
        yield 'long flag' => ['--defCommunity=public'];
        yield 'leading hyphen with whitespace' => ['  -v'];
        yield 'single hyphen' => ['-'];
        yield 'leading dot' => ['.example.net'];
        yield 'slash in host' => ['host/path'];
        yield 'equals in host' => ['host=value'];
    }

    #[DataProvider('validHostnames')]
    public function testDomainModelsAcceptValidHostnames(string $hostname): void
    {
        $device = new Device(1, 'Router', 'before.example.net', '', true, '', '');
        $device->revise('Router', $hostname, '', true, '', '', $device->revision());
        self::assertSame($hostname, $device->hostname());

        $newDevice = new NewDevice(['description' => 'Router', 'hostname' => $hostname]);
        self::assertSame($hostname, $newDevice->fields['hostname']);

        $change = new NotificationReceiverChange(['hostname' => $hostname] + self::RECEIVER_BASE, false, self::NO_CREDENTIALS);
        self::assertSame($hostname, $change->settings['hostname']);
    }

    /** @return iterable<string, array{string}> */
    public static function validHostnames(): iterable
    {
        yield 'fqdn with internal hyphen' => ['router-01.example.net'];
        yield 'ipv4' => ['192.0.2.10'];
        yield 'ipv6 bare' => ['2001:db8::1'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 bracketed' => ['[2001:db8::1]'];
        yield 'ipv6 link-local with zone' => ['fe80::1%eth0'];
        yield 'transport prefix' => ['udp6:[2001:db8::1]'];
    }

    /**
     * S8-05: api_device_save() hostname form_input_validate() pattern rejects
     * leading hyphens and accepts valid hostnames/IPs.
     */
    public function testApiDeviceSaveHostnamePatternRejectsLeadingHyphens(): void
    {
        $root = dirname(__DIR__, 3);
        $source = file_get_contents($root . '/lib/api_device.php');
        self::assertIsString($source);
        self::assertSame(
            1,
            preg_match("/form_input_validate\\(trim\\(\\\$hostname\\),\\s*'hostname',\\s*'([^']+)'/", $source, $match),
            'api_device_save() must validate hostname with a non-empty regex'
        );
        $pattern = '/' . $match[1] . '/';

        foreach (['-Lf/tmp/pwn', '--opt=1', '-host', 'host;id', 'host/path'] as $bad) {
            self::assertSame(0, preg_match($pattern, $bad), 'Expected rejection for: ' . $bad);
        }
        foreach (['router-01.example.net', '192.0.2.1', '2001:db8::1', '::1', '[2001:db8::1]', 'fe80::1%eth0'] as $good) {
            self::assertSame(1, preg_match($pattern, $good), 'Expected acceptance for: ' . $good);
        }
    }

    /** @return array{exit: int, stdout: string} */
    private function runCliPluginManageTraversal(string $root, string $sandbox): array
    {
        $wrapper = sys_get_temp_dir() . '/kadupul-cli-wrap-' . bin2hex(random_bytes(6)) . '.php';
        $script = file_get_contents($root . '/cli/plugin_manage.php');
        self::assertIsString($script);
        $patched = preg_replace('/^#!.*\n/', '', $script);
        $patched = str_replace(
            "require(__DIR__ . '/../include/cli_check.php');",
            'require_once ' . var_export($root . '/tests/Helpers/PhpSource.php', true) . ';'
            . '$fnSrc = file_get_contents(' . var_export($root . '/lib/functions.php', true) . ');'
            . 'foreach (["cacti_path_is_within", "cacti_normalize_windows_path", "validate_relative_path_within", "cacti_plugin_path"] as $fn) { eval(test_php_function_source($fnSrc, $fn)); }'
            . 'function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }'
            . '$config = ["base_path" => ' . var_export($sandbox, true) . '];',
            (string) $patched
        );
        file_put_contents($wrapper, $patched);
        try {
            $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', $wrapper, '--plugin=../escape', '--install']);
            $process->setTimeout(15);
            $process->run();
            self::assertSame('', $process->getErrorOutput());
            return ['exit' => $process->getExitCode() ?? -1, 'stdout' => $process->getOutput()];
        } finally {
            if (is_file($wrapper)) {
                unlink($wrapper);
            }
        }
    }

    /** @param list<string> $arguments */
    private function runPhp(string $program, array $arguments): array
    {
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, ...$arguments]);
        $process->setTimeout(20);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . "\n" . $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
