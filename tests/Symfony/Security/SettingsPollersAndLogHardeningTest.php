<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Security;

use Kadupul\IdentityAccess\Application\Command\TransitionAuthenticationSession;
use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionAccounts;
use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionSession;
use Kadupul\Platform\Domain\BoundedRegexPattern;
use Kadupul\Platform\Domain\IpAddressPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SettingsPollersAndLogHardeningTest extends TestCase
{
    public function testIpAddressPolicyAndLegacyIsIpAddressRejectZoneInjectionAndReservedTargets(): void
    {
        $this->assertTrue(IpAddressPolicy::isValidIpAddress('10.20.30.40'));
        $this->assertTrue(IpAddressPolicy::isValidIpAddress('fe80::1%eth0'));
        $this->assertTrue(IpAddressPolicy::isValidIpAddress('[2606:4700:4700::1111]'));

        $this->assertFalse(IpAddressPolicy::isValidIpAddress("127.0.0.1%' OR 1=1--"));
        $this->assertFalse(IpAddressPolicy::isValidIpAddress('10.0.0.1%eth0'));
        $this->assertFalse(IpAddressPolicy::isValidIpAddress("fe80::1%eth0' OR 1=1"));
        $this->assertFalse(IpAddressPolicy::isValidIpAddress("10.0.0.1\n"));

        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('127.0.0.1'));
        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('0.0.0.0'));
        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('169.254.169.254'));
        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('224.0.0.1'));
        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('::1'));
        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('::'));
        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('fe80::1%eth0'));
        $this->assertTrue(IpAddressPolicy::isLoopbackOrReservedIp('::ffff:127.0.0.1'));
        $this->assertFalse(IpAddressPolicy::isLoopbackOrReservedIp('10.20.30.40'));
        $this->assertFalse(IpAddressPolicy::isLoopbackOrReservedIp('8.8.8.8'));
        $this->assertFalse(IpAddressPolicy::isLoopbackOrReservedIp('2606:4700:4700::1111'));

        $this->assertFalse(IpAddressPolicy::isSafeRemoteHostSyntax('localhost'));
        $this->assertFalse(IpAddressPolicy::isSafeRemoteHostSyntax('db.localhost'));
        $this->assertFalse(IpAddressPolicy::isSafeRemoteHostSyntax('/var/run/mysqld/mysqld.sock'));
        $this->assertFalse(IpAddressPolicy::isSafeRemoteHostSyntax('127.0.0.1'));
        $this->assertFalse(IpAddressPolicy::isSafeRemoteHostSyntax('169.254.169.254'));
        $this->assertFalse(IpAddressPolicy::isSafeRemoteHostSyntax('db.example.com;charset=utf8'));
        $this->assertTrue(IpAddressPolicy::isSafeRemoteHostSyntax('collector-01.example.com'));
        $this->assertTrue(IpAddressPolicy::isSafeRemoteHostSyntax('10.20.30.40'));

        $root = dirname(__DIR__, 3);
        $result = $this->runPhp(
            <<<'PHP'
            require_once $argv[1] . '/lib/functions.php';
            echo json_encode([
                'v4' => is_ipaddress('10.20.30.40'),
                'v6_zone' => is_ipaddress('fe80::1%eth0'),
                'v4_sqli_zone' => is_ipaddress("127.0.0.1%') OR 1=1--"),
                'v6_sqli_zone' => is_ipaddress("fe80::1%eth0' OR 1=1"),
                'v4_zone_rejected' => is_ipaddress('192.168.1.1%eth0'),
            ], JSON_THROW_ON_ERROR);
            PHP,
            [$root],
        );

        $this->assertTrue($result['v4']);
        $this->assertTrue($result['v6_zone']);
        $this->assertFalse($result['v4_sqli_zone']);
        $this->assertFalse($result['v6_sqli_zone']);
        $this->assertFalse($result['v4_zone_rejected']);
    }

    public function testBoundedRegexPatternEnforcesDelimitersAndPcreCeilings(): void
    {
        $compiled = BoundedRegexPattern::compile('admin|login');
        $this->assertNotNull($compiled);
        $this->assertTrue(BoundedRegexPattern::matches($compiled, 'User ADMIN logged in'));
        $this->assertFalse(BoundedRegexPattern::matches($compiled, 'Routine poll'));

        $slashCompiled = BoundedRegexPattern::compile('foo/bar');
        $this->assertNotNull($slashCompiled);
        $this->assertTrue(BoundedRegexPattern::matches($slashCompiled, 'prefix foo/bar suffix'));

        $this->assertNull(BoundedRegexPattern::compile(''));
        $this->assertNull(BoundedRegexPattern::compile(str_repeat('a', BoundedRegexPattern::MAX_LENGTH + 1)));
        $this->assertNull(BoundedRegexPattern::compile("abc\0def"));
        $this->assertNull(BoundedRegexPattern::compile('(*LIMIT_MATCH=999999)(a+)+b'));
        $this->assertNull(BoundedRegexPattern::compile('(?C1)foo'));
        $this->assertNull(BoundedRegexPattern::compile('(unclosed'));

        $redos = BoundedRegexPattern::compile('(a+)+b');
        $this->assertNotNull($redos);
        $started = microtime(true);
        $this->assertFalse(BoundedRegexPattern::matches($redos, str_repeat('a', 40) . 'c'));
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function testDbConnectRealRejectsDsnInjectionBadTlsAndArbitrarySockets(): void
    {
        $root = dirname(__DIR__, 3);
        $result = $this->runPhp(
            <<<'PHP'
            $config = [];
            require_once $argv[1] . '/lib/database.php';
            echo json_encode([
                'semicolon_host' => db_connect_real('127.0.0.1;unix_socket=/tmp/x', 'u', 'p', 'cacti'),
                'nul_db' => db_connect_real('127.0.0.1', 'u', 'p', "cacti\0evil"),
                'bad_type' => db_connect_real('127.0.0.1', 'u', 'p', 'cacti', 'sqlite'),
                'bad_port_high' => db_connect_real('127.0.0.1', 'u', 'p', 'cacti', 'mysql', 70000),
                'bad_port_str' => db_connect_real('127.0.0.1', 'u', 'p', 'cacti', 'mysql', '3306;foo=1'),
                'ssl_missing_ca' => db_connect_real('127.0.0.1', 'u', 'p', 'cacti', 'mysql', 3306, 0, true, '', '', ''),
                'ssl_nonexistent_ca' => db_connect_real('127.0.0.1', 'u', 'p', 'cacti', 'mysql', 3306, 0, true, '', '', '/nonexistent/ca.pem'),
                'arbitrary_socket' => db_connect_real('/etc/passwd', 'u', 'p', 'cacti', 'mysql', 3306, 0, false, '', '', '', false, false),
            ], JSON_THROW_ON_ERROR);
            PHP,
            [$root],
        );

        foreach ($result as $case => $value) {
            $this->assertFalse($value, 'Expected db_connect_real to fail closed for ' . $case);
        }
    }

    public function testDbDumpDataRequiresExplicitOutputPathAndRejectsSymlinks(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir() . '/kadupul-dump-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $target = $dir . '/real.txt';
        file_put_contents($target, 'untouched');
        $link = $dir . '/link.sql';
        symlink($target, $link);
        $fresh = $dir . '/fresh.sql';

        try {
            $result = $this->runPhp(
                <<<'PHP'
                $database_default = 'cacti';
                $database_username = 'u';
                $database_password = 'p';
                $GLOBALS['logs'] = [];
                function cacti_log($msg, ...$args) { $GLOBALS['logs'][] = $msg; }
                function cacti_escapeshellarg($s) { return escapeshellarg((string) $s); }
                require_once $argv[1] . '/lib/database.php';
                $noDefault = db_dump_data('cacti', '', [], false);
                $symlinkRes = db_dump_data('cacti', '', [], $argv[2]);
                $fh = db_dump_open_output_file($argv[3]);
                $mode = is_resource($fh) ? (fileperms($argv[3]) & 0777) : -1;
                if (is_resource($fh)) {
                    fclose($fh);
                }
                echo json_encode([
                    'no_default' => $noDefault,
                    'symlink' => $symlinkRes,
                    'mode' => $mode,
                ], JSON_THROW_ON_ERROR);
                PHP,
                [$root, $link, $fresh],
            );

            $this->assertFalse($result['no_default']);
            $this->assertFalse($result['symlink']);
            $this->assertSame(0600, $result['mode']);
            $this->assertSame('untouched', file_get_contents($target));
        } finally {
            @unlink($link);
            @unlink($target);
            @unlink($fresh);
            @rmdir($dir);
        }
    }

    public function testTransitionAuthenticationSessionBlocksAdminGuestTransitions(): void
    {
        $accounts = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts->method('find')->willReturn(['enabled' => 'on', 'locked' => '', 'password' => 'hash']);
        $accounts->method('guestId')->willReturn(1);
        $session = $this->createMock(AuthenticationTransitionSession::class);
        $session->method('takeCookieCredential')->willReturn(null);
        $session->expects($this->once())->method('logBlocked')->with(1, 'guest');
        $session->expects($this->never())->method('bindCredential');

        $this->assertFalse((new TransitionAuthenticationSession($accounts, $session))(1, 'guest'));

        $accounts2 = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts2->method('find')->willReturn(['enabled' => 'on', 'locked' => '', 'password' => 'hash', 'is_admin' => true]);
        $accounts2->method('guestId')->willReturn(5);
        $session2 = $this->createMock(AuthenticationTransitionSession::class);
        $session2->method('takeCookieCredential')->willReturn(null);
        $session2->expects($this->once())->method('logBlocked')->with(5, 'guest');
        $session2->expects($this->never())->method('bindCredential');

        $this->assertFalse((new TransitionAuthenticationSession($accounts2, $session2))(5, 'guest'));
    }

    public function testSettingsValidationHardeningForPathsSecretsGuestUserAndRegex(): void
    {
        $root = dirname(__DIR__, 3);
        $globalSettingsSource = (string) file_get_contents($root . '/include/global_settings.php');
        $phpServersPos = strpos($globalSettingsSource, "'php_servers' => array(");
        $this->assertNotFalse($phpServersPos);
        $phpServersBlock = substr($globalSettingsSource, $phpServersPos, 600);
        $this->assertStringContainsString('FILTER_VALIDATE_INT', $phpServersBlock);
        $this->assertStringContainsString("'min_range' => 1", $phpServersBlock);
        $this->assertStringContainsString("'max_range' => 15", $phpServersBlock);

        $settingsSource = (string) file_get_contents($root . '/settings.php');
        $this->assertStringNotContainsString("\$_SESSION['sess_field_values'][\$field_name] = get_nfilter_request_var(\$field_name);\n                    \$errors[4] = 4;", $settingsSource);
        $this->assertStringContainsString("(\$field_array['method'] ?? '') === 'textbox_password'", $settingsSource);
        $this->assertStringContainsString("get_request_var('tab') == 'authentication' && \$validated_user_template !== null", $settingsSource);

        $dir = sys_get_temp_dir() . '/kadupul-settings-helpers-' . bin2hex(random_bytes(8));
        mkdir($dir . '/cache/realtime', 0700, true);
        $fakeBin = $dir . '/evilbin';
        file_put_contents($fakeBin, "#!/bin/sh\necho hi\n");
        chmod($fakeBin, 0700);
        $symlinkCache = $dir . '/cache_link';
        symlink($dir . '/cache/realtime', $symlinkCache);

        try {
            $result = $this->runPhp(
                <<<'PHP'
                $config = ['base_path' => $argv[2], 'rra_path' => $argv[2] . '/rra'];
                require_once $argv[1] . '/lib/functions.php';
                echo json_encode([
                    'fake_bin_rejected' => cacti_is_allowed_binary_path($argv[3], 'path_rrdtool'),
                    'relative_bin_rejected' => cacti_is_allowed_binary_path('usr/bin/env', 'path_rrdtool'),
                    'php_runtime_allowed' => cacti_is_allowed_binary_path(PHP_BINARY, 'path_php_binary'),
                    'tmp_cache_rejected' => cacti_is_allowed_realtime_cache_path('/tmp'),
                    'root_cache_rejected' => cacti_is_allowed_realtime_cache_path('/'),
                    'symlink_cache_rejected' => cacti_is_allowed_realtime_cache_path($argv[4]),
                    'owned_cache_allowed' => cacti_is_allowed_realtime_cache_path($argv[2] . '/cache/realtime'),
                ], JSON_THROW_ON_ERROR);
                PHP,
                [$root, $dir, $fakeBin, $symlinkCache],
            );

            $this->assertFalse($result['fake_bin_rejected']);
            $this->assertFalse($result['relative_bin_rejected']);
            $this->assertTrue($result['php_runtime_allowed']);
            $this->assertFalse($result['tmp_cache_rejected']);
            $this->assertFalse($result['root_cache_rejected']);
            $this->assertFalse($result['symlink_cache_rejected']);
            $this->assertTrue($result['owned_cache_allowed']);
        } finally {
            @unlink($symlinkCache);
            @unlink($fakeBin);
            @rmdir($dir . '/cache/realtime');
            @rmdir($dir . '/cache');
            @rmdir($dir);
        }
    }

    public function testInstallerRejectsNonExistentOptionalBinaryAndUnallowlistedBinary(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir() . '/kadupul-inst-bin-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $untrustedBin = $dir . '/fake_spine';
        file_put_contents($untrustedBin, "#!/bin/sh\necho bad\n");
        chmod($untrustedBin, 0700);

        try {
            $result = $this->runPhp(
                <<<'PHP'
                $config = ['base_path' => $argv[1], 'cacti_server_os' => 'unix'];
                $GLOBALS['saved'] = [];
                function log_install_debug(...$args) {}
                function log_install_high(...$args) {}
                function log_install_medium(...$args) {}
                function __($v) { return $v; }
                function set_install_config_option($name, $value) { $GLOBALS['saved'][$name] = $value; }
                require_once $argv[1] . '/lib/functions.php';
                require_once $argv[1] . '/lib/installer.php';
                $class = new ReflectionClass('Installer');
                $installer = $class->newInstanceWithoutConstructor();
                $pathsProp = $class->getProperty('paths');
                $pathsProp->setAccessible(true);
                $pathsProp->setValue($installer, [
                    'path_spine' => ['install_check' => 'file_exists', 'install_optional' => true, 'file_type' => 'binary'],
                    'path_rrdtool' => ['install_check' => 'file_exists', 'install_optional' => false, 'file_type' => 'binary'],
                ]);
                $setPaths = $class->getMethod('setPaths');
                $setPaths->setAccessible(true);
                $setPaths->invoke($installer, [
                    'path_spine' => '/nonexistent/spine --injected',
                    'path_rrdtool' => $argv[2],
                ]);
                $errorsProp = $class->getProperty('errors');
                $errorsProp->setAccessible(true);
                echo json_encode([
                    'saved' => $GLOBALS['saved'],
                    'error_keys' => array_keys($errorsProp->getValue($installer)['Paths'] ?? []),
                ], JSON_THROW_ON_ERROR);
                PHP,
                [$root, $untrustedBin],
            );

            $this->assertSame([], $result['saved']);
            $this->assertContains('path_spine', $result['error_keys']);
            $this->assertContains('path_rrdtool', $result['error_keys']);
        } finally {
            @unlink($untrustedBin);
            @rmdir($dir);
        }
    }

    public function testPollersRealm15ProtectionNoSecretDefaultsAndParameterizedDuplicateCheck(): void
    {
        $root = dirname(__DIR__, 3);
        $globalArrays = (string) file_get_contents($root . '/include/global_arrays.php');
        $this->assertStringContainsString("'pollers.php' => 15,", $globalArrays);

        $pollersSource = (string) file_get_contents($root . '/pollers.php');
        $this->assertStringContainsString("cacti_require_post_actions(array('actions', 'ping'));", $pollersSource);
        $this->assertStringNotContainsString("'default' => \$database_password", $pollersSource);
        $this->assertStringNotContainsString("'default' => \$database_username", $pollersSource);
        $this->assertStringNotContainsString("'default' => \$database_default", $pollersSource);
        $this->assertStringContainsString("\$poller['dbpass'] = '';", $pollersSource);

        $result = $this->runPhp(
            <<<'PHP'
            require_once $argv[1] . '/tests/Helpers/PhpSource.php';
            require_once $argv[1] . '/src/Platform/Domain/IpAddressPolicy.php';
            require_once $argv[1] . '/lib/functions.php';
            $source = file_get_contents($argv[1] . '/pollers.php');
            eval(test_php_function_source($source, 'poller_validate_remote_host'));
            eval(test_php_function_source($source, 'poller_check_duplicate_poller_id'));
            $GLOBALS['captured'] = [];
            function db_fetch_cell_prepared($sql, $params = []) {
                $GLOBALS['captured'][] = ['sql' => $sql, 'params' => $params];
                return 0;
            }
            $badCol = poller_check_duplicate_poller_id(2, '10.20.30.40', 'id; DROP TABLE poller');
            $goodCol = poller_check_duplicate_poller_id(2, '10.20.30.40', 'dbhost');
            echo json_encode([
                'localhost_rejected' => poller_validate_remote_host('localhost'),
                'loopback_rejected' => poller_validate_remote_host('127.0.0.1'),
                'linklocal_rejected' => poller_validate_remote_host('169.254.169.254'),
                'socket_rejected' => poller_validate_remote_host('/var/run/mysqld/mysqld.sock'),
                'valid_ip_allowed' => poller_validate_remote_host('10.20.30.40'),
                'bad_col' => $badCol,
                'good_col' => $goodCol,
                'captured' => $GLOBALS['captured'],
            ], JSON_THROW_ON_ERROR);
            PHP,
            [$root],
        );

        $this->assertFalse($result['localhost_rejected']);
        $this->assertFalse($result['loopback_rejected']);
        $this->assertFalse($result['linklocal_rejected']);
        $this->assertFalse($result['socket_rejected']);
        $this->assertTrue($result['valid_ip_allowed']);
        $this->assertFalse($result['bad_col']);
        $this->assertFalse($result['good_col']);
        $this->assertCount(1, $result['captured']);
        $this->assertStringContainsString('`dbhost` IN (?)', $result['captured'][0]['sql']);
        $this->assertContains('10.20.30.40', $result['captured'][0]['params']);
    }

    public function testTailFileExcludesBeforeFilterAndCountAndClogCallbacksEnforceVisibility(): void
    {
        $root = dirname(__DIR__, 3);
        $logFile = sys_get_temp_dir() . '/kadupul-clog-test-' . bin2hex(random_bytes(8)) . '.log';
        file_put_contents($logFile, "INFO Public line alpha\nINFO SECRET_TOKEN=xyz123\nINFO Another public line\n");

        try {
            $result = $this->runPhp(
                <<<'PHP'
                $config = ['url_path' => '/kadupul/'];
                $_SESSION = ['sess_user_id' => 10];
                function set_request_var($k, $v) {}
                function force_session_data() {}
                function api_plugin_is_enabled($p) { return false; }
                function __($t) { return $t; }
                function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }
                function cacti_log($m, ...$a) {}
                function clog_admin() { return false; }
                function is_device_allowed($id) { return $id === 1; }
                function is_graph_allowed($id) { return $id === 100; }
                function is_realm_allowed($r) { return false; }
                function db_fetch_cell_prepared($sql, $params = []) {
                    if (str_contains($sql, 'FROM host')) { return 'Host-' . $params[0]; }
                    if (str_contains($sql, 'FROM graph_templates_graph')) { return 'Graph-' . $params[0]; }
                    if (str_contains($sql, 'FROM user_auth')) { return 'User-' . $params[0]; }
                    return 'SecretObject';
                }
                function db_fetch_assoc($sql) { return [['id' => 1, 'name' => 'SecretPoller']]; }
                require_once $argv[1] . '/src/Platform/Domain/BoundedRegexPattern.php';
                require_once $argv[1] . '/tests/Helpers/PhpSource.php';
                $htmlUtilSource = file_get_contents($argv[1] . '/lib/html_utility.php');
                eval(test_php_function_source($htmlUtilSource, 'validate_is_regex'));
                $fnSource = file_get_contents($argv[1] . '/lib/functions.php');
                eval(test_php_function_source($fnSource, 'tail_file'));
                eval(test_php_function_source($fnSource, 'determine_display_log_entry'));
                require_once $argv[1] . '/lib/clog_webapi.php';

                $page = 1;
                $totalOracle = 0;
                $oracleLines = tail_file($argv[2], 50, -1, 'SECRET_TOKEN', $page, $totalOracle, true, 'SECRET_TOKEN');

                $totalInvalid = 99;
                $invalidLines = tail_file($argv[2], 50, -1, '', $page, $totalInvalid, true, '(invalid');

                $totalPublic = 0;
                $publicLines = tail_file($argv[2], 50, -1, 'Public', $page, $totalPublic, true, 'SECRET_TOKEN');

                $devDenied = clog_regex_device(['Device[2]', 'Device[', '2', ']']);
                $devAllowed = clog_regex_device(['Device[1]', 'Device[', '1', ']']);
                $graphDenied = clog_regex_graphs(['Graph[200]', 'Graph[', '200', ']']);
                $userDenied = clog_regex_users(['User[1]', 'User[', '1', ']']);
                $userSelf = clog_regex_users(['User[10]', 'User[', '10', ']']);
                $pollerDenied = clog_regex_poller(['Poller[1]', 'Poller[', '1', ']']);

                echo json_encode([
                    'oracle_total' => $totalOracle,
                    'oracle_lines' => $oracleLines,
                    'invalid_total' => $totalInvalid,
                    'invalid_lines' => $invalidLines,
                    'public_total' => $totalPublic,
                    'dev_denied' => $devDenied,
                    'dev_allowed' => $devAllowed,
                    'graph_denied' => $graphDenied,
                    'user_denied' => $userDenied,
                    'user_self' => $userSelf,
                    'poller_denied' => $pollerDenied,
                ], JSON_THROW_ON_ERROR);
                PHP,
                [$root, $logFile],
            );

            $this->assertSame(0, $result['oracle_total']);
            $this->assertSame([], $result['oracle_lines']);
            $this->assertSame(0, $result['invalid_total']);
            $this->assertSame([], $result['invalid_lines']);
            $this->assertSame(2, $result['public_total']);
            $this->assertSame('Device[2]', $result['dev_denied']);
            $this->assertStringContainsString('Host-1', $result['dev_allowed']);
            $this->assertSame('Graph[200]', $result['graph_denied']);
            $this->assertSame('User[1]', $result['user_denied']);
            $this->assertStringContainsString('User-10', $result['user_self']);
            $this->assertSame('Poller[1]', $result['poller_denied']);
        } finally {
            @unlink($logFile);
        }
    }

    public function testUtilitiesAndEmailTestRedactionAndEscaping(): void
    {
        $root = dirname(__DIR__, 3);
        $utilitiesSource = (string) file_get_contents($root . '/utilities.php');
        $this->assertStringContainsString('SUM((processes * threads) + (processes * ?))', $utilitiesSource);
        $this->assertStringNotContainsString('shell_exec(cacti_escapeshellcmd(', $utilitiesSource);
        $this->assertStringNotContainsString('utilities_php_modules()', $utilitiesSource);
        $this->assertStringContainsString("\$allowed_db_settings = array(", $utilitiesSource);
        $this->assertStringContainsString("__('Community:') . ' ' . __('Redacted')", $utilitiesSource);
        $this->assertStringContainsString("__('Script:') . ' ' . __('Redacted')", $utilitiesSource);
        $this->assertStringContainsString("html_escape((string) \$cache_directory)", $utilitiesSource);

        $clogSource = (string) file_get_contents($root . '/lib/clog_webapi.php');
        $this->assertStringContainsString('json_encode(basename($logfile), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)', $clogSource);

        $emailResult = $this->runPhp(
            <<<'PHP'
            $config = [];
            function __($t) { return $t; }
            function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
            function read_config_option($k) {
                return match ($k) {
                    'settings_how' => 2,
                    'settings_smtp_host' => '<script>alert(1)</script>',
                    'settings_smtp_port' => '25',
                    'settings_smtp_username' => '<b>user</b>',
                    'settings_smtp_password' => 'secret',
                    'settings_smtp_secure' => 'tls',
                    'settings_smtp_timeout' => 5,
                    'settings_ping_mail' => 0,
                    default => '',
                };
            }
            function ping_mail_server(...$args) { return '<img src=x onerror=alert(1)>'; }
            require_once $argv[1] . '/tests/Helpers/PhpSource.php';
            eval(test_php_function_source(file_get_contents($argv[1] . '/lib/functions.php'), 'email_test'));
            ob_start();
            email_test();
            $out = ob_get_clean();
            echo json_encode(['html' => $out], JSON_THROW_ON_ERROR);
            PHP,
            [$root],
        );

        $this->assertStringNotContainsString('<script>alert(1)</script>', $emailResult['html']);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $emailResult['html']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $emailResult['html']);
    }

    public function testPollerMaintenanceSymlinkPathContainmentAndReportDisablement(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir() . '/kadupul-maint-sec-' . bin2hex(random_bytes(8));
        mkdir($dir . '/cache', 0700, true);
        mkdir($dir . '/log', 0700, true);
        mkdir($dir . '/rra', 0700, true);
        mkdir($dir . '/archive', 0700, true);

        $outsideVictim = $dir . '/victim.png';
        file_put_contents($outsideVictim, 'keep-me');
        touch($outsideVictim, time() - 10800);
        symlink($outsideVictim, $dir . '/cache/evil.png');

        $agedRegular = $dir . '/cache/old.png';
        file_put_contents($agedRegular, 'delete-me');
        touch($agedRegular, time() - 10800);

        $symlinkLog = $dir . '/log/link.log';
        $logTarget = $dir . '/log_target.log';
        file_put_contents($logTarget, 'do-not-rotate');
        symlink($logTarget, $symlinkLog);

        $realLog = $dir . '/log/cacti.log';
        file_put_contents($realLog, 'log-line');

        $nonRrd = $dir . '/rra/not_an_rrd.txt';
        file_put_contents($nonRrd, 'keep-txt');

        try {
            $result = $this->runPhp(
                <<<'PHP'
                $config = ['base_path' => $argv[2], 'rra_path' => $argv[2] . '/rra', 'cacti_server_os' => 'unix'];
                $debug = false;
                $archived = 0;
                $purged = 0;
                $GLOBALS['executed'] = [];
                function read_config_option($k, ...$a) {
                    return match ($k) {
                        'realtime_cache_path' => $GLOBALS['argv'][2] . '/cache',
                        'rrd_archive' => $GLOBALS['argv'][2] . '/archive',
                        'storage_location' => 0,
                        'secpass_expireaccount' => 30,
                        'secpass_expirepass' => 0,
                        default => '',
                    };
                }
                function db_execute($sql) { $GLOBALS['executed'][] = [$sql, []]; }
                function db_execute_prepared($sql, $params = []) { $GLOBALS['executed'][] = [$sql, $params]; }
                function db_fetch_assoc_prepared($sql, $params = []) { return [['id' => 42]]; }
                function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }
                function cacti_log($m, ...$a) {}
                function maint_debug($m) {}
                function rrdclean_create_path($p) { if (!is_dir($p)) { mkdir($p, 0700, true); } }
                require_once $argv[1] . '/lib/rrd_maintenance.php';
                require_once $argv[1] . '/src/Platform/Domain/BoundedRegexPattern.php';
                require_once $argv[1] . '/tests/Helpers/PhpSource.php';
                $maintSource = file_get_contents($argv[1] . '/poller_maintenance.php');
                foreach (['realtime_purge_cache', 'logrotate_file_rotate', 'secpass_check_expired', 'rrdcleaner_is_safe_relative_path', 'rrdcleaner_resolve_contained_path', 'rrdcleaner_archive_path_is_safe', 'remove_files'] as $fn) {
                    eval(test_php_function_source($maintSource, $fn));
                }

                realtime_purge_cache();
                $symlinkRotated = logrotate_file_rotate('Test', $argv[2] . '/log/link.log', new DateTime('2026-11-25'));
                $realRotated = logrotate_file_rotate('Test', $argv[2] . '/log/cacti.log', new DateTime('2026-11-25'));
                secpass_check_expired();

                $retained = 0;
                remove_files([
                    ['id' => 1, 'name' => '<path_cacti>/victim.png', 'local_data_id' => 0, 'action' => '3'],
                    ['id' => 2, 'name' => 'not_an_rrd.txt', 'local_data_id' => 0, 'action' => '3'],
                ], $retained);

                echo json_encode([
                    'victim_exists' => file_exists($argv[2] . '/victim.png'),
                    'old_deleted' => !file_exists($argv[2] . '/cache/old.png'),
                    'symlink_rotated' => $symlinkRotated,
                    'real_rotated' => $realRotated,
                    'rotated_exists' => file_exists($argv[2] . '/log/cacti.log-20261125'),
                    'new_log_exists' => is_file($argv[2] . '/log/cacti.log') && !is_link($argv[2] . '/log/cacti.log'),
                    'non_rrd_exists' => file_exists($argv[2] . '/rra/not_an_rrd.txt'),
                    'non_rrd_archived' => file_exists($argv[2] . '/archive/not_an_rrd.txt'),
                    'retained' => $retained,
                    'executed' => $GLOBALS['executed'],
                ], JSON_THROW_ON_ERROR);
                PHP,
                [$root, $dir],
            );

            $this->assertTrue($result['victim_exists']);
            $this->assertTrue($result['old_deleted']);
            $this->assertSame(0, $result['symlink_rotated']);
            $this->assertSame(1, $result['real_rotated']);
            $this->assertTrue($result['rotated_exists']);
            $this->assertTrue($result['new_log_exists']);
            $this->assertTrue($result['non_rrd_exists']);
            $this->assertFalse($result['non_rrd_archived']);
            $this->assertGreaterThanOrEqual(1, $result['retained']);

            $disabledReports = array_filter(
                $result['executed'],
                static fn(array $call): bool => str_contains($call[0], 'UPDATE reports') && $call[1] === [42],
            );
            $this->assertNotEmpty($disabledReports);

            $maintSource = (string) file_get_contents($root . '/poller_maintenance.php');
            $this->assertStringContainsString("array('-q', \$config['base_path'] . '/cli/poller_reindex_hosts.php', '--id=all', '--qid=all')", $maintSource);
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if (!$entry->isLink() && $entry->isDir()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function runPhp(string $code, array $args = []): array
    {
        $process = new Process(array_merge([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, '--'], $args));
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . "\n" . $process->getOutput());

        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
