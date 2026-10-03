<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

final class LdapInstallerNativeContractTest extends TestCase
{
    /** @dataProvider domainCases */
    public function testDomainSearchPreservesConfigurationAndRetries(array $scenario, array $hosts, array $expectedProperties, $result): void
    {
        $state = $this->runNative(['mode' => 'domain'] + $scenario);
        self::assertSame($result, $state['result']);
        self::assertCount(1, $state['queries']);
        self::assertSame([($scenario['realm'] ?? 1007) - 1000], $state['queries'][0][1]);
        self::assertSame($hosts, array_column(array_column($state['calls'], 'properties'), 'host'));
        foreach ($state['calls'] as $call) {
            self::assertSame($scenario['operation'] === 'dn' ? 'Search' : 'Getcn', $call['operation']);
            foreach ($expectedProperties as $property => $value) {
                self::assertSame($value, $call['properties'][$property], $property);
            }
        }
        self::assertCount(($scenario['missing'] ?? false) ? 0 : 1, $state['rows']);
        if (!($scenario['missing'] ?? false)) {
            self::assertSame($scenario['row']['server'] ?? 'first second', $state['rows'][0]['server']);
            self::assertSame($scenario['row']['specific_password'] ?? 'fixture-password', $state['rows'][0]['specific_password']);
        }
    }

    public static function domainCases(): array
    {
        $full = [
            'username' => 'alice', 'dn' => 'uid=<username>', 'port' => 1389, 'port_ssl' => 1636,
            'version' => 2, 'encryption' => 2, 'referrals' => 1, 'mode' => 2,
            'search_base' => 'dc=example', 'search_filter' => '(uid=<username>)',
            'specific_dn' => 'cn=bind', 'specific_password' => 'fixture-password',
            'group_require' => true, 'group_dn' => 'cn=operators', 'group_attrib' => 'uniqueMember',
            'group_member_type' => 2,
        ];
        $empty = [
            'username' => 'default-user', 'dn' => 'default-dn', 'port' => 389, 'port_ssl' => 636,
            'version' => 3, 'encryption' => 0, 'referrals' => 0, 'mode' => 0,
            'search_base' => 'default-base', 'search_filter' => 'default-filter',
            'specific_dn' => 'default-specific-dn', 'specific_password' => 'default-specific-password',
            'group_require' => false, 'group_dn' => 'default-group-dn', 'group_attrib' => 'default-group-attrib',
            'group_member_type' => 1,
        ];
        $emptyRow = [
            'server' => '', 'dn' => '', 'port' => 0, 'port_ssl' => 0, 'proto_version' => 0,
            'encryption' => 0, 'referrals' => 0, 'mode' => 0, 'search_base' => '',
            'search_filter' => '', 'specific_dn' => '', 'specific_password' => '',
            'group_require' => '', 'group_dn' => '', 'group_attrib' => '', 'group_member_type' => 0,
        ];
        $cases = [];
        foreach (['dn', 'cn'] as $operation) {
            $cn = ['cn' => $operation === 'cn' ? ['cn', 'mail'] : []];
            $base = ['operation' => $operation];
            $cases[$operation . ' fallback succeeds'] = [$base, ['first', 'second'], $full + $cn, ['error_num' => 0, 'result' => 'found']];
            $cases[$operation . ' first server stops retries'] = [$base + ['responses' => [['error_num' => 0, 'result' => 'first']]], ['first'], $full + $cn, ['error_num' => 0, 'result' => 'first']];
            $cases[$operation . ' all servers fail'] = [$base + ['responses' => [['error_num' => 81], ['error_num' => 49]]], ['first', 'second'], $full + $cn, ['error_num' => 49]];
            $cases[$operation . ' missing scoped domain'] = [$base + ['missing' => true], [], [], false];
            $cases[$operation . ' other realm is not reused'] = [$base + ['realm' => 1008], [], [], false];
            $cases[$operation . ' omitted values retain adapter defaults'] = [$base + ['username' => '', 'row' => $emptyRow, 'responses' => [['error_num' => 0]]], ['default-host'], $empty + $cn, ['error_num' => 0]];
            $cases[$operation . ' group off with whitespace server list'] = [$base + ['row' => ['server' => 'first\tsecond', 'group_require' => 'ON']], ['first', 'second'], array_replace($full + $cn, ['group_require' => false]), ['error_num' => 0, 'result' => 'found']];
        }
        // Use an actual tab rather than a backslash sequence in the server value.
        foreach ($cases as &$case) {
            if (isset($case[0]['row']['server'])) {
                $case[0]['row']['server'] = str_replace('\\t', "\t", $case[0]['row']['server']);
            }
        }
        return $cases;
    }

    /** @dataProvider installerCases */
    public function testInstallerToolOrderDefaultsAndOverrides(string $os, string $variant): void
    {
        $names = ['php_binary', 'rrdtool', 'snmpwalk', 'snmpget', 'snmpbulkwalk', 'snmpgetnext', 'snmptrap', 'settings_sendmail_path', 'spine'];
        $defaults = $os === 'unix'
            ? ['/bin/php', '/usr/bin/rrdtool', '/usr/bin/snmpwalk', '/usr/bin/snmpget', '/usr/bin/snmpbulkwalk', '/usr/bin/snmpgetnext', '/usr/bin/snmptrap', '/usr/sbin/sendmail', '/usr/local/spine/bin/spine']
            : ['c:/php/php.exe', 'c:/rrdtool/rrdtool.exe', 'c:/usr/bin/snmpwalk.exe', 'c:/usr/bin/snmpget.exe', 'c:/usr/bin/snmpbulkwalk.exe', 'c:/usr/bin/snmpgetnext.exe', 'c:/usr/bin/snmptrap.exe', null, 'c:/spine/bin/spine.exe'];
        $options = [];
        $discovered = [];
        $events = [];
        foreach ($names as $index => $name) {
            if ($defaults[$index] === null) {
                continue;
            }
            if ($variant === 'configured') {
                $options['path_' . $name] = ' /fixture/' . $name . ' ';
                $events[] = ['config', 'path_' . $name, true];
            } else {
                if ($variant === 'blank') {
                    $options['path_' . $name] = '';
                    $events[] = ['config', 'path_' . $name, true];
                }
                $events[] = ['discover', basename($defaults[$index])];
                if ($variant === 'discovered') {
                    $discovered[basename($defaults[$index])] = '/fixture-found/' . $name;
                }
            }
        }
        $state = $this->runNative(['mode' => 'installer', 'os' => $os, 'options' => $options, 'discovered' => $discovered, 'basenames' => array_map('basename', array_filter($defaults))]);
        $input = $state['input'];
        $keys = ['path_php_binary', 'path_rrdtool', 'path_snmpwalk', 'path_snmpget', 'path_snmpbulkwalk', 'path_snmpgetnext', 'path_snmptrap'];
        if ($os === 'unix') {
            $keys[] = 'settings_sendmail_path';
        }
        array_push($keys, 'path_spine', 'path_spine_config', 'path_cactilog', 'path_stderrlog');
        self::assertSame($keys, array_slice(array_keys($input), 0, count($keys)));
        self::assertSame($events, $state['events']);
        foreach ($names as $index => $name) {
            $key = $name === 'settings_sendmail_path' ? $name : 'path_' . $name;
            if ($defaults[$index] === null) {
                self::assertArrayNotHasKey($key, $input);
                continue;
            }
            $expected = $variant === 'configured' ? $options['path_' . $name]
                : ($state['discovery'][basename($defaults[$index])] ?: $defaults[$index]);
            if ($name === 'spine' && $os === 'unix') {
                foreach (['/usr/local/spine/bin/spine', '/usr/local/bin/spine'] as $candidate) {
                    if (file_exists($candidate)) {
                        $expected = $candidate;
                        break;
                    }
                }
            }
            self::assertSame($expected, $input[$key]['default'], $name);
            $paths = $name === 'settings_sendmail_path' ? ['unix' => '/usr/sbin/sendmail'] : ['unix' => [
                'php_binary' => '/bin/php', 'rrdtool' => '/usr/bin/rrdtool', 'snmpwalk' => '/usr/bin/snmpwalk',
                'snmpget' => '/usr/bin/snmpget', 'snmpbulkwalk' => '/usr/bin/snmpbulkwalk',
                'snmpgetnext' => '/usr/bin/snmpgetnext', 'snmptrap' => '/usr/bin/snmptrap', 'spine' => '/usr/local/spine/bin/spine',
            ][$name], 'win32' => [
                'php_binary' => 'c:/php/php.exe', 'rrdtool' => 'c:/rrdtool/rrdtool.exe', 'snmpwalk' => 'c:/usr/bin/snmpwalk.exe',
                'snmpget' => 'c:/usr/bin/snmpget.exe', 'snmpbulkwalk' => 'c:/usr/bin/snmpbulkwalk.exe',
                'snmpgetnext' => 'c:/usr/bin/snmpgetnext.exe', 'snmptrap' => 'c:/usr/bin/snmptrap.exe', 'spine' => 'c:/spine/bin/spine.exe',
            ][$name]];
            self::assertContains($name . ': Locations (' . $os . '), Paths: ' . var_export($paths, true), $state['locations']);
        }
        self::assertSame('SNMP get', $input['path_snmpget']['friendly_name']);
        self::assertTrue($input['path_snmpget']['install_optional']);
        if ($os === 'unix') {
            self::assertSame('Mail', $input['settings_sendmail_path']['friendly_name']);
            self::assertTrue($input['settings_sendmail_path']['install_optional']);
        }
        self::assertStringEndsWith('/log/cacti.log', $input['path_cactilog']['default']);
        self::assertStringEndsWith('/log/cacti.stderr.log', $input['path_stderrlog']['default']);
    }

    public static function installerCases(): array
    {
        $cases = [];
        foreach (['unix', 'win32'] as $os) {
            foreach (['default', 'configured', 'blank', 'discovered'] as $variant) {
                $cases[$os . ' ' . $variant] = [$os, $variant];
            }
        }
        return $cases;
    }

    public function testActualLdapLegacyCallbacksAndCoverageEvidence(): void
    {
        self::assertSame([0, 'Authentication Success', ENT_COMPAT | ENT_HTML401, ['close', 'start'], ['Cacti callback']], $this->runNative(['mode' => 'legacy']));
    }

    private function runNative(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/ldap-installer-contract-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/ldap-installer-native.php', $json, $directory];
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $result = test_php_run($command);
            self::assertSame(0, $result['status'], $result['err']);
            self::assertSame('', $result['err']);
            $state = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($state);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                // Parent registration is independent of the child registration.
                $sources = ['lib/auth.php', 'lib/ldap.php', 'install/functions.php', 'cacti.sql', 'composer.lock', 'tests/composer.lock', 'tests/Helpers/NativeChildCoverageEvidence.php', 'include/global_constants.php', 'tests/Unit/Security/Auth/LdapInstallerNativeContractTest.php'];
                $markers = [match ($scenario['mode']) {
                    'domain' => 'domain-production-returned',
                    'installer' => 'installer-production-returned',
                    'legacy' => 'legacy-callbacks-returned',
                }, 'result-json-encoded'];
                $hits = [match ($scenario['mode']) {
                    'domain' => 'lib/auth.php',
                    'installer' => 'install/functions.php',
                    'legacy' => 'lib/ldap.php',
                }];
                $native = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/ldap-installer-native.php', $json, $sources, $markers, $hits);
                if ($scenario['mode'] === 'legacy') {
                    self::assertSame(21, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/ldap-installer-native.php', $json, $sources, $markers, $hits, 'tests/Helpers/NativeChildCoverageEvidence.php'));
                    $originalReport = file_get_contents($reports[0]);
                    file_put_contents($reports[0], '');
                    try {
                        NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/ldap-installer-native.php', $json, $sources, $markers, $hits);
                        self::fail('Empty native report accepted.');
                    } catch (RuntimeException $error) {
                        self::assertStringContainsString('report or evidence is missing', $error->getMessage());
                    } finally {
                        file_put_contents($reports[0], $originalReport);
                    }
                    $sidecar = $reports[0] . '.json';
                    $original = file_get_contents($sidecar);
                    $changed = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
                    $changed['sources']['lib/ldap.php'] = str_repeat('0', 64);
                    file_put_contents($sidecar, json_encode($changed, JSON_THROW_ON_ERROR));
                    try {
                        NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/ldap-installer-native.php', $json, $sources, $markers, $hits);
                        self::fail('Changed source hash accepted.');
                    } catch (RuntimeException $error) {
                        self::assertStringContainsString('source is missing or stale', $error->getMessage());
                    } finally {
                        file_put_contents($sidecar, $original);
                    }
                }
                $coverage->merge($native);
            }
            return $state;
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
