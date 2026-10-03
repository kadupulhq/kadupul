<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HostDataSubstitutionTest extends TestCase
{
    private \PDO $database;
    private array $host;

    protected function setUp(): void
    {
        require dirname(__DIR__) . '/Fixtures/host-substitution-adapters.php';
        define('SQL_NO_CACHE', '');
        require dirname(__DIR__, 2) . '/lib/variables.php';
        require dirname(__DIR__, 2) . '/lib/api_aggregate.php';
        $this->database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $this->host = [];
        foreach (['availability', 'avg_time', 'cur_time', 'description', 'external_id', 'hostname', 'id', 'location', 'max_oids', 'notes', 'ping_retries', 'polling_time', 'site_name', 'snmp_auth_protocol', 'snmp_community', 'snmp_context', 'snmp_engine_id', 'snmp_password', 'snmp_port', 'snmp_priv_passphrase', 'snmp_priv_protocol', 'snmp_sysContact', 'snmp_sysDescr', 'snmp_sysLocation', 'snmp_sysName', 'snmp_sysObjectID', 'snmp_sysUpTimeInstance', 'snmp_timeout', 'snmp_username', 'snmp_version'] as $field) $this->host[$field] = 'value:' . $field;
        $this->host['id'] = '7';
        $columns = array_keys($this->host);
        $this->database->exec('CREATE TABLE host (' . implode(' TEXT, ', array_diff($columns, ['site_name'])) . ' TEXT, site_id INTEGER)');
        $this->database->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)');
        $this->database->exec("INSERT INTO sites VALUES (1,'value:site_name')");
        $values = $this->host;
        unset($values['site_name']);
        $this->database->prepare('INSERT INTO host (' . implode(',', array_keys($values)) . ',site_id) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ',1)')->execute(array_values($values));
        $GLOBALS['host_database'] = $this->database;
        $GLOBALS['database_hostname'] = 'host-test';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = 'host-test';
        $GLOBALS['database_sessions'] = ['host-test:0:host-test' => $this->database];
        $GLOBALS['host_hook_calls'] = $GLOBALS['host_uptime_calls'] = $GLOBALS['host_legacy_reads'] = [];
    }

    private function adapters(): array
    {
        return ['substitute_host_data', 'aggregate_graph_substitute_host_data'];
    }

    private function originalTokens(): array
    {
        return [
            ['host_management_ip', 'hostname'],
            ['host_id', 'id'],
            ['host_hostname', 'hostname'],
            ['host_description', 'description'],
            ['host_site', 'site_name'],
            ['host_notes', 'notes'],
            ['host_location', 'location'],
            ['host_polling_time', 'polling_time'],
            ['host_avg_time', 'avg_time'],
            ['host_cur_time', 'cur_time'],
            ['host_availability', 'availability'],
            ['host_uptime', null],
            ['host_snmp_community', 'snmp_community'],
            ['host_snmp_version', 'snmp_version'],
            ['host_snmp_username', 'snmp_username'],
            ['host_snmp_password', 'snmp_password'],
            ['host_snmp_auth_protocol', 'snmp_auth_protocol'],
            ['host_snmp_priv_passphrase', 'snmp_priv_passphrase'],
            ['host_snmp_priv_protocol', 'snmp_priv_protocol'],
            ['host_snmp_context', 'snmp_context'],
            ['host_snmp_engine_id', 'snmp_engine_id'],
            ['host_snmp_port', 'snmp_port'],
            ['host_snmp_timeout', 'snmp_timeout'],
            ['host_snmp_sysDescr', 'snmp_sysDescr'],
            ['host_snmp_sysObjectID', 'snmp_sysObjectID'],
            ['host_snmp_sysContact', 'snmp_sysContact'],
            ['host_snmp_sysLocation', 'snmp_sysLocation'],
            ['host_snmp_sysName', 'snmp_sysName'],
            ['host_snmp_sysUpTimeInstance', 'snmp_sysUpTimeInstance'],
            ['host_ping_retries', 'ping_retries'],
            ['host_max_oids', 'max_oids'],
            ['host_external_id', 'external_id'],
        ];
    }

    public function testEveryOriginalOrderedTokenAndHookPayload(): void
    {
        $tokens = $this->originalTokens();
        self::assertCount(32, $tokens);
        $input = implode(' / ', array_map(static fn($entry) => '[[' . $entry[0] . ']]', $tokens));
        $expected = implode(' / ', array_map(fn($entry) => $entry[1] === null ? 'observed uptime' : $this->host[$entry[1]], $tokens));
        foreach ($this->adapters() as $adapter) {
            self::assertSame($expected, $adapter($input, '[[', ']]', '7'));
            self::assertSame(['substitute_host_data', ['string' => $expected, 'l_escape_string' => '[[', 'r_escape_string' => ']]', 'host_id' => '7']], end($GLOBALS['host_hook_calls']));
        }
        self::assertCount(2, $GLOBALS['host_uptime_calls']);
    }

    public function testOrderedPlanRetainsRawValuesAliasesAndUptimeEvaluationPosition(): void
    {
        $host = $this->host;
        $host['id'] = 17;
        $host['notes'] = null;
        $host['polling_time'] = 1.5;
        $host['availability'] = false;
        [$search, $replace] = \Kadupul\Platform\Infrastructure\Legacy\HostDataSubstitution::replacements('[[', ']]', $host);
        $tokens = $this->originalTokens();
        self::assertSame(array_map(static fn($entry) => '[[' . $entry[0] . ']]', $tokens), $search);
        self::assertSame(array_map(static fn($entry) => $entry[1] === null ? 'observed uptime' : $host[$entry[1]], $tokens), $replace);
        self::assertSame(17, $replace[1]);
        self::assertNull($replace[5]);
        self::assertSame(1.5, $replace[7]);
        self::assertFalse($replace[10]);
        self::assertSame('observed uptime', $replace[11]);

        unset($host['availability'], $host['snmp_community']);
        $GLOBALS['host_evaluation_events'] = [];
        set_error_handler(static function (int $level, string $message): bool {
            self::assertSame(E_WARNING, $level);
            $GLOBALS['host_evaluation_events'][] = $message;
            return true;
        });
        try {
            \Kadupul\Platform\Infrastructure\Legacy\HostDataSubstitution::replacements('|', '|', $host);
        } finally {
            restore_error_handler();
        }
        self::assertSame(['Undefined array key "availability"', 'uptime', 'Undefined array key "snmp_community"'], $GLOBALS['host_evaluation_events']);
    }

    public function testEmptyAndMissingHostKeepInputAndSkipPlugin(): void
    {
        foreach ($this->adapters() as $adapter) {
            foreach ([0, '0', null, false, ''] as $id) self::assertSame(['|host_id|'], $adapter(['|host_id|'], '|', '|', $id));
            self::assertSame('unchanged', $adapter('unchanged', '|', '|', 99));
        }
        self::assertSame([], $GLOBALS['host_hook_calls']);
        self::assertSame([], $GLOBALS['host_uptime_calls']);
        self::assertSame([[99]], $GLOBALS['host_legacy_reads']);
    }

    public function testOrderedCascadeNullableFieldsArrayInputAndMixedPluginReturn(): void
    {
        $this->database->exec("UPDATE host SET hostname='|host_description|',description='cascaded',notes=NULL");
        foreach ($this->adapters() as $adapter) {
            self::assertSame(['cascaded / cascaded / ', '7'], $adapter(['|host_management_ip| / |host_hostname| / |host_notes|', '|host_id|'], '|', '|', 7));
        }
        $GLOBALS['host_hook_result'] = ['plugin preserved mixed return'];
        foreach ($this->adapters() as $adapter) self::assertSame($GLOBALS['host_hook_result'], $adapter('plain', '|', '|', 7));
        self::assertCount(4, $GLOBALS['host_hook_calls']);
    }

    public function testEachAdapterRetainsItsOriginalBuiltinCoercionContext(): void
    {
        foreach ([[null, ''], [0, '0'], [1, '1'], [false, ''], [true, '1'], [1.5, '1.5']] as [$subject, $expected]) {
            $errors = [];
            set_error_handler(static function (int $level, string $message) use (&$errors): bool {
                $errors[] = [$level, $message];
                return true;
            });
            try {
                self::assertSame($expected, \substitute_host_data($subject, '|', '|', 7));
            } finally {
                restore_error_handler();
            }
            self::assertSame($subject === null ? 1 : 0, count($errors));
            if ($subject === null) {
                self::assertSame(E_DEPRECATED, $errors[0][0]);
                self::assertStringContainsString('str_replace()', $errors[0][1]);
            }
            $before = count($GLOBALS['host_hook_calls']);
            try {
                \aggregate_graph_substitute_host_data($subject, '|', '|', 7);
                self::fail('The checked adapter must retain strict subject validation.');
            } catch (\TypeError $error) {
                self::assertStringContainsString('str_replace()', $error->getMessage());
            }
            self::assertCount($before, $GLOBALS['host_hook_calls']);
        }
        $subject = new class implements \Stringable {
            public function __toString(): string
            {
                return '|host_id|';
            }
        };
        self::assertSame('7', \substitute_host_data($subject, '|', '|', 7));
        try {
            \aggregate_graph_substitute_host_data($subject, '|', '|', 7);
            self::fail('Strict Stringable coercion must remain refused.');
        } catch (\TypeError $error) {
            self::assertStringContainsString('str_replace()', $error->getMessage());
        }
        // Delimiter concatenation retains legacy scalar coercion in both paths.
        foreach ($this->adapters() as $adapter) self::assertSame('7', $adapter('1host_id', true, null, 7));
    }

    public function testCheckedReaderRefusesRealSqlFailureBeforeTransformAndHook(): void
    {
        $this->database->exec('DROP TABLE sites');
        try {
            \aggregate_graph_substitute_host_data('|host_id|', '|', '|', 7);
            self::fail('A failed native read must not produce substituted text.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('could not be confirmed', $error->getMessage());
        }
        self::assertSame([], $GLOBALS['host_hook_calls']);
        self::assertSame([], $GLOBALS['host_uptime_calls']);
    }
}
