<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Automation;

use Kadupul\Automation\Domain\DiscoveryNetworkChange;
use Kadupul\Automation\Domain\DiscoveryNetworkRejected;
use Kadupul\Automation\Domain\DiscoveryRange;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

final class DiscoveryAndAutomationSecurityTest extends TestCase
{
    private const array VALID_NETWORK_FIELDS = [
        'name' => 'Branch LAN',
        'poller_id' => 1,
        'site_id' => 0,
        'subnet_range' => '192.168.10.0/24',
        'dns_servers' => '',
        'enabled' => true,
        'enable_netbios' => false,
        'add_to_cacti' => true,
        'same_sysname' => false,
        'rerun_data_queries' => false,
        'notification_enabled' => false,
        'notification_email' => '',
        'notification_fromname' => '',
        'notification_fromemail' => '',
        'snmp_id' => 1,
        'ping_method' => 2,
        'ping_port' => 23,
        'ping_timeout' => 400,
        'ping_retries' => 1,
        'sched_type' => 1,
        'threads' => 1,
        'run_limit' => 1200,
        'start_at' => '2026-11-09 10:00',
        'recur_every' => 1,
        'day_of_week' => [],
        'month' => [],
        'day_of_month' => [],
        'monthly_week' => [],
        'monthly_day' => [],
    ];

    public function testDiscoveryRangeRejectsReservedLinkLocalLoopbackMulticastAndOversizedRanges(): void
    {
        self::assertSame(131072, DiscoveryRange::TOTAL_MAX);

        // Valid /32, /24, /22, /16 ranges stay allowed.
        self::assertSame(1, DiscoveryRange::addresses('192.168.1.10'));
        self::assertSame(254, DiscoveryRange::addresses('192.168.1.0/24'));
        self::assertSame(1022, DiscoveryRange::addresses('10.20.0.0/22'));
        self::assertSame(65534, DiscoveryRange::addresses('172.16.0.0/16'));
        self::assertSame([65534 + 254, null], DiscoveryRange::total('10.1.0.0/16,10.2.0.0/24'));

        // Reserved, metadata, loopback, unspecified, multicast, broadcast, and oversized ranges are rejected.
        $rejectedRanges = [
            '169.254.169.254',
            '169.254.169.254/32',
            '169.254.0.0/16',
            '169.254.*.*',
            '169.252.0.0/14',
            '127.0.0.1',
            '127.0.0.0/8',
            '127.0.0.0/24',
            '126.0.0.0/7',
            '0.0.0.0',
            '0.0.0.0/8',
            '0.0.0.0/1',
            '224.0.0.1',
            '224.0.0.0/24',
            '240.0.0.1',
            '255.255.255.255',
            '10.0.0.0/8',
            '10.0.0.0/14',
            '10.*.*.*',
        ];
        foreach ($rejectedRanges as $range) {
            self::assertNull(DiscoveryRange::addresses($range), "Expected '$range' to be rejected");
        }

        // Combined total exceeding TOTAL_MAX is rejected.
        self::assertSame([null, '10.3.0.0/16'], DiscoveryRange::total('10.1.0.0/16,10.2.0.0/16,10.3.0.0/16'));

        foreach (['169.254.169.254/32', '127.0.0.1', '10.0.0.0/8', '10.1.0.0/16,10.2.0.0/16,10.3.0.0/16'] as $badRange) {
            try {
                new DiscoveryNetworkChange(['subnet_range' => $badRange] + self::VALID_NETWORK_FIELDS);
                self::fail("Expected DiscoveryNetworkChange to reject subnet_range '$badRange'");
            } catch (DiscoveryNetworkRejected $error) {
                self::assertSame('subnet_range', $error->field);
            }
        }
    }

    public function testDiscoveryNetworkChangeValidatesDnsServersStrictly(): void
    {
        foreach (['', '192.0.2.53', '10.0.0.53 192.168.1.53', 'ns1.example.net', '2606:4700:4700::1111', 'fd00::53'] as $validDns) {
            $change = new DiscoveryNetworkChange(['dns_servers' => $validDns] + self::VALID_NETWORK_FIELDS);
            self::assertSame($validDns, $change->settings['dns_servers']);
        }

        $rejectedDns = [
            '192.0.2.53:5353',
            'ns1.example.net:8080',
            '[2606:4700:4700::1111]',
            '[::1]:53',
            '127.0.0.1',
            '127.0.0.53',
            '169.254.169.254',
            '0.0.0.0',
            '224.0.0.1',
            '240.0.0.1',
            '::1',
            '::',
            'fe80::1',
            '::ffff:169.254.169.254',
            '::ffff:127.0.0.1',
            '64:ff9b::a9fe:a9fe',
            '2002:a9fe:a9fe::1',
            'ff02::1',
            'localhost',
            'sub.localhost',
            '127.1',
            '2130706433',
            '0x7f000001',
        ];
        foreach ($rejectedDns as $badDns) {
            try {
                new DiscoveryNetworkChange(['dns_servers' => $badDns] + self::VALID_NETWORK_FIELDS);
                self::fail("Expected DiscoveryNetworkChange to reject dns_servers '$badDns'");
            } catch (DiscoveryNetworkRejected $error) {
                self::assertSame('dns_servers', $error->field, $badDns);
            }
        }
    }

    public function testAutomationPrimeAndDnsHelpersEnforceCapAndAddressPolicy(): void
    {
        $root = dirname(__DIR__, 3);
        $program = <<<'PHP'
$root = $argv[1];
require $root . '/include/global_constants.php';
$GLOBALS['inserted'] = [];
$GLOBALS['logs'] = [];
$GLOBALS['subnets'] = [
    1 => '10.0.0.0/30',
    2 => '169.254.169.254/32',
    3 => '127.0.0.0/24',
    4 => '10.0.0.0/8',
    5 => '10.1.0.0/16,10.2.0.0/16,10.3.0.0/16',
];
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function cacti_count($v) { return is_countable($v) ? count($v) : 0; }
function cacti_log($msg, ...$args) { $GLOBALS['logs'][] = $msg; }
function db_qstr($v) { return "'" . str_replace("'", "\\'", (string) $v) . "'"; }
function db_fetch_cell_prepared($sql, $params = []) { return $GLOBALS['subnets'][$params[0]] ?? ''; }
function db_execute($sql) { $GLOBALS['inserted'][] = $sql; }
function __($t, ...$a) { return $t; }
function raise_message(...$a) {}
$config = ['is_web' => true];
$debug = false;
require $root . '/lib/api_automation.php';

$primeResults = [];
foreach ([1, 2, 3, 4, 5] as $netId) {
    $before = count($GLOBALS['inserted']);
    $res = automation_primeIPAddressTable($netId);
    $primeResults[$netId] = ['result' => $res, 'inserts' => count($GLOBALS['inserted']) - $before];
}

$dnsResults = [
    'port_suffix' => automation_get_dns_from_ip('10.0.0.1', '192.0.2.53:5353', 100),
    'loopback_dns' => automation_get_dns_from_ip('10.0.0.1', '127.0.0.1', 100),
    'metadata_dns' => automation_get_dns_from_ip('10.0.0.1', '169.254.169.254', 100),
    'ipv6_loopback_dns' => automation_get_dns_from_ip('10.0.0.1', '::1', 100),
    'localhost_dns' => automation_get_dns_from_ip('10.0.0.1', 'localhost', 100),
    'disallowed_target_ip' => automation_get_dns_from_ip('169.254.169.254', '192.0.2.53', 100),
];

echo json_encode(['prime' => $primeResults, 'dns' => $dnsResults], JSON_THROW_ON_ERROR);
PHP;
        $data = $this->runPhpJson($program, [$root]);

        self::assertSame(['result' => 2, 'inserts' => 1], $data['prime']['1']);
        foreach (['2', '3', '4', '5'] as $blockedId) {
            self::assertFalse($data['prime'][$blockedId]['result'], "Network $blockedId should be refused");
            self::assertSame(0, $data['prime'][$blockedId]['inserts'], "Network $blockedId must insert zero rows");
        }

        self::assertSame('10.0.0.1', $data['dns']['port_suffix']);
        self::assertSame('10.0.0.1', $data['dns']['loopback_dns']);
        self::assertSame('10.0.0.1', $data['dns']['metadata_dns']);
        self::assertSame('10.0.0.1', $data['dns']['ipv6_loopback_dns']);
        self::assertSame('10.0.0.1', $data['dns']['localhost_dns']);
        self::assertSame('ERROR', $data['dns']['disallowed_target_ip']);
    }

    public function testAutomationFieldAllowlistBlocksSnmpCredentialsAndEnforcesPreviewVisibility(): void
    {
        $root = dirname(__DIR__, 3);
        $program = <<<'PHP'
$root = $argv[1];
require $root . '/include/global_constants.php';
require $root . '/tests/Helpers/PhpSource.php';
$fnSource = file_get_contents($root . '/lib/functions.php');
foreach (['sanitize_sql_column', 'array_rekey'] as $fn) {
    eval(test_php_function_source($fnSource, $fn));
}
$GLOBALS['queries'] = [];
$GLOBALS['logs'] = [];
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function cacti_log($msg, ...$args) { $GLOBALS['logs'][] = $msg; }
function __($t, ...$a) { return $a ? vsprintf($t, $a) : $t; }
function __esc($t, ...$a) { return htmlspecialchars(__($t, ...$a), ENT_QUOTES, 'UTF-8'); }
function html_escape($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function db_qstr($v) { return "'" . str_replace("'", "\\'", (string) $v) . "'"; }
function db_column_exists($table, $column) { return true; }
function db_fetch_assoc($sql, ...$args) {
    $GLOBALS['queries'][] = $sql;
    if ($sql === 'SHOW COLUMNS FROM host') {
        return [
            ['Field' => 'id', 'Type' => 'int'],
            ['Field' => 'host_template_id', 'Type' => 'int'],
            ['Field' => 'hostname', 'Type' => 'varchar(100)'],
            ['Field' => 'description', 'Type' => 'varchar(150)'],
            ['Field' => 'snmp_community', 'Type' => 'varchar(100)'],
            ['Field' => 'snmp_username', 'Type' => 'varchar(50)'],
            ['Field' => 'snmp_password', 'Type' => 'varchar(50)'],
            ['Field' => 'snmp_priv_passphrase', 'Type' => 'varchar(200)'],
            ['Field' => 'snmp_auth_protocol', 'Type' => 'char(6)'],
            ['Field' => 'snmp_priv_protocol', 'Type' => 'char(7)'],
            ['Field' => 'snmp_context', 'Type' => 'varchar(64)'],
            ['Field' => 'snmp_engine_id', 'Type' => 'varchar(64)'],
            ['Field' => 'snmp_sysName', 'Type' => 'varchar(300)'],
        ];
    }
    return [];
}
function db_fetch_assoc_prepared($sql, $params = []) {
    $GLOBALS['queries'][] = $sql;
    if (str_contains($sql, 'FROM automation_tree_rule_items')) {
        return [[
            'id' => 1,
            'field' => 'h.snmp_community',
            'search_pattern' => '.*',
            'replace_pattern' => '',
            'propagate_changes' => '',
            'sort_type' => 1,
        ]];
    }
    return [];
}
function db_fetch_cell_prepared($sql, $params = [], ...$rest) {
    $GLOBALS['queries'][] = $sql;
    return 'SHOULD_NOT_BE_QUERIED';
}
function db_fetch_cell($sql, ...$rest) {
    $GLOBALS['queries'][] = $sql;
    if (str_contains($sql, 'leaf_type')) {
        return TREE_ITEM_TYPE_HOST;
    }
    return 0;
}
function get_allowed_management_device_ids_sql($user_id = 0): string { return 'SELECT 42 AS id'; }
function get_allowed_management_graph_ids_sql($user_id = 0): string { return 'SELECT 99 AS id'; }
function get_allowed_devices(...$args) { return []; }
function get_allowed_graph_templates(...$args) { return []; }
function validate_store_request_vars($filters, $prefix) {}
function get_request_var($name) {
    return ['rowsd' => 10, 'paged' => 1, 'host_status' => -1, 'host_template_id' => -1, 'filterd' => '',
        'sort_column' => 'description', 'sort_direction' => 'ASC', 'rows' => 10, 'page' => 1,
        'host_id' => -1, 'template_id' => -1, 'filter' => '', 'id' => 1][$name] ?? '';
}
function isset_request_var($name) { return false; }
function isempty_request_var($name) { return true; }
function set_request_var($name, $value) {}
function read_config_option($name) { return 10; }
function cacti_validate_sort_column($col, $allowed, $default) { return $default; }
function get_order_string() { return 'ORDER BY title_cache ASC'; }
function html_start_box(...$a) {}
function html_end_box(...$a) {}
function html_nav_bar(...$a) { return ''; }
function html_header_sort(...$a) {}
function html_create_list(...$a) {}
function form_hidden_box(...$a) {}
function form_end() {}
function raise_message(...$a) {}
function html_escape_request_var($name) { return ''; }
final class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
$automation_oper = [AUTOMATION_OPER_NULL => '', AUTOMATION_OPER_AND => 'AND', AUTOMATION_OPER_OR => 'OR', AUTOMATION_OPER_LEFT_BRACKET => '(', AUTOMATION_OPER_RIGHT_BRACKET => ')'];
$automation_op_array = ['op' => [1 => 'LIKE', 7 => '='], 'binary' => [1 => true, 7 => true], 'pre' => [1 => '%', 7 => ''], 'post' => [1 => '%', 7 => '']];
$automation_tree_header_types = [AUTOMATION_TREE_ITEM_TYPE_STRING => 'Fixed String'];
$item_rows = [10 => '10'];
$device_actions = [];
$graph_actions = [];
$config = [];

require $root . '/lib/api_automation.php';

$hostFields = get_query_fields('host', ['id', 'host_template_id']);
$sensitiveChecks = [];
foreach (api_automation_sensitive_host_columns() as $col) {
    $sensitiveChecks[$col] = [
        'in_query_fields' => array_key_exists('h.' . $col, $hostFields),
        'column_exists' => api_automation_column_exists('h.' . $col, ['host', 'host_template']),
        'match_filter' => build_rule_item_filter([['operation' => AUTOMATION_OPER_NULL, 'field' => 'h.' . $col, 'operator' => 1, 'pattern' => 'secret']]),
        'prefixed_filter' => build_rule_item_filter([['operation' => AUTOMATION_OPER_NULL, 'field' => $col, 'operator' => 1, 'pattern' => 'secret']], ' h.'),
    ];
}

$GLOBALS['queries'] = [];
create_all_header_nodes(42, ['id' => 1, 'tree_id' => 1, 'tree_item_id' => 0, 'leaf_type' => TREE_ITEM_TYPE_HOST, 'host_grouping_type' => 1]);
$headerQueries = $GLOBALS['queries'];

$GLOBALS['queries'] = [];
ob_start();
display_matching_hosts(['id' => 1], AUTOMATION_RULE_TYPE_GRAPH_MATCH, 'automation_graph_rules.php');
ob_end_clean();
$hostPreviewQueries = $GLOBALS['queries'];

$GLOBALS['queries'] = [];
ob_start();
display_matching_graphs(['id' => 1], AUTOMATION_RULE_TYPE_TREE_MATCH, 'automation_tree_rules.php');
ob_end_clean();
$graphPreviewQueries = $GLOBALS['queries'];

$GLOBALS['queries'] = [];
ob_start();
display_matching_trees(1, AUTOMATION_RULE_TYPE_TREE_ACTION, ['field' => 'h.snmp_community', 'search_pattern' => '.*', 'replace_pattern' => ''], 'automation_tree_rules.php');
ob_end_clean();
$treePreviewQueries = $GLOBALS['queries'];

echo json_encode([
    'host_fields' => array_keys($hostFields),
    'sensitive' => $sensitiveChecks,
    'valid_exists' => api_automation_column_exists('h.hostname', ['host', 'host_template']),
    'bad_alias' => api_automation_column_exists('ht.hostname', ['host', 'host_template']),
    'injected_field' => api_automation_column_exists("h.hostname' OR 1=1", ['host', 'host_template']),
    'header_queries' => $headerQueries,
    'host_preview_queries' => $hostPreviewQueries,
    'graph_preview_queries' => $graphPreviewQueries,
    'tree_preview_queries' => $treePreviewQueries,
], JSON_THROW_ON_ERROR);
PHP;
        $data = $this->runPhpJson($program, [$root]);

        self::assertSame(['h.hostname', 'h.description', 'h.snmp_sysName'], $data['host_fields']);
        self::assertTrue($data['valid_exists']);
        self::assertFalse($data['bad_alias']);
        self::assertFalse($data['injected_field']);

        foreach ($data['sensitive'] as $col => $check) {
            self::assertFalse($check['in_query_fields'], "$col must not be in get_query_fields()");
            self::assertFalse($check['column_exists'], "$col must be rejected by api_automation_column_exists()");
            self::assertStringContainsString('1 = 0', $check['match_filter'], "$col must fail closed in match filter");
            self::assertStringNotContainsString($col, $check['match_filter']);
            self::assertStringContainsString('1 = 0', $check['prefixed_filter'], "$col must fail closed in prefixed filter");
            self::assertStringNotContainsString($col, $check['prefixed_filter']);
        }

        self::assertStringNotContainsString('snmp_community', implode("\n", $data['header_queries']));
        self::assertStringContainsString('h.id IN (SELECT 42 AS id)', implode("\n", $data['host_preview_queries']));
        self::assertStringContainsString('gl.id IN (SELECT 99 AS id)', implode("\n", $data['graph_preview_queries']));
        self::assertStringContainsString('gl.host_id IN (SELECT 42 AS id)', implode("\n", $data['graph_preview_queries']));
        self::assertStringContainsString('h.id IN (SELECT 42 AS id)', implode("\n", $data['tree_preview_queries']));
        self::assertStringNotContainsString('h.snmp_community', implode("\n", $data['tree_preview_queries']));
    }

    public function testDiscoveryCsvExportNeutralizesFormulasCommasAndNewlines(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/automation_devices.php');
        $functionsSource = (string) file_get_contents($root . '/lib/functions.php');
        $program = 'set_error_handler(static function ($level, $msg) { throw new RuntimeException($msg); });'
            . "\n" . test_php_function_source($functionsSource, 'cacti_csv_safe')
            . "\n" . test_php_function_source($source, 'export_csv_field')
            . "\n" . test_php_function_source($source, 'export_data')
            . "\n" . test_php_function_source($source, 'export_discovery_results')
            . <<<'PHP'
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function __($t) { return $t; }
function get_discovery_results(&$total, $rows, $export) {
    return [[
        'hostname' => '=CMD("calc")',
        'ip' => '+10.0.0.1',
        'sysName' => "router, =HYPERLINK(\"http://evil\")",
        'sysLocation' => "Rack 1\r\n=1+1",
        'sysContact' => "\t@SUM(A1:A2)",
        'sysDescr' => "-2+3\n=BAD()",
        'os' => 'Linux',
        'sysUptime' => 0,
        'snmp' => 1,
        'up' => 1,
    ]];
}
export_discovery_results();
PHP;
        $output = $this->runPhpRaw($program, []);

        $stream = new \SplTempFileObject();
        $stream->fwrite($output);
        $stream->rewind();

        $headers = $stream->fgetcsv(',', '"', '');
        $row = $stream->fgetcsv(',', '"', '');
        $eof = $stream->fgetcsv(',', '"', '');

        self::assertSame(['Host', 'IP', 'System Name', 'System Location', 'System Contact', 'System Description', 'OS', 'Uptime', 'SNMP', 'Status'], $headers);
        self::assertIsArray($row);
        self::assertCount(10, $row);
        self::assertSame("'=CMD(\"calc\")", $row[0]);
        self::assertSame("'+10.0.0.1", $row[1]);
        self::assertSame('router, =HYPERLINK("http://evil")', $row[2]);
        self::assertSame('Rack 1 =1+1', $row[3]);
        self::assertSame("'\t@SUM(A1:A2)", $row[4]);
        self::assertSame("'-2+3 =BAD()", $row[5]);
        self::assertFalse($eof);
    }

    public function testAutomationDevicesPurgeRequiresPostCsrfAndAlignsNetworkParam(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/automation_devices.php');

        self::assertStringContainsString("cacti_require_post_actions(array('actions', 'purge'))", $source);
        self::assertStringContainsString("loadPageUsingPost('automation_devices.php'", $source);
        self::assertStringContainsString('__csrf_magic: csrfMagicToken', $source);
        self::assertStringContainsString("network: \$('#network').val()", $source);

        // Verify purge_discovery_results deletes only the selected network for both `network` and `network_id` POST params.
        $program = test_php_function_source($source, 'purge_discovery_results') . <<<'PHP'
$GLOBALS['queries'] = [];
$_POST = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
function get_filter_request_var($name) { return (int) ($_POST[$name] ?? -1); }
function db_execute_prepared($sql, $params = []) { $GLOBALS['queries'][] = [$sql, $params]; }
function db_execute($sql) { $GLOBALS['queries'][] = [$sql, []]; }
register_shutdown_function(static function () {
    echo json_encode($GLOBALS['queries'], JSON_THROW_ON_ERROR);
});
purge_discovery_results();
PHP;
        $byNetwork = $this->runPhpJson($program, [json_encode(['network' => '7'], JSON_THROW_ON_ERROR)]);
        self::assertSame([['DELETE FROM automation_devices WHERE network_id = ?', [7]]], $byNetwork);

        $byNetworkId = $this->runPhpJson($program, [json_encode(['network_id' => '9'], JSON_THROW_ON_ERROR)]);
        self::assertSame([['DELETE FROM automation_devices WHERE network_id = ?', [9]]], $byNetworkId);

        $truncateAll = $this->runPhpJson($program, [json_encode(['network' => '-1'], JSON_THROW_ON_ERROR)]);
        self::assertSame([['TRUNCATE TABLE automation_devices', []]], $truncateAll);
    }

    public function testLegacyRuleSaveHandlersRejectUnvalidatedAndSensitiveFields(): void
    {
        $root = dirname(__DIR__, 3);
        $graphSource = (string) file_get_contents($root . '/automation_graph_rules.php');
        $treeSource = (string) file_get_contents($root . '/automation_tree_rules.php');

        // Confirm broken db_qstr comparison and strip-then-discard-result patterns are gone.
        self::assertStringNotContainsString("\"'\$field_name'\" != db_qstr", $graphSource);
        self::assertStringNotContainsString("preg_replace('/[^a-zA-Z0-9_]/'", $graphSource);
        self::assertStringNotContainsString("preg_replace('/[^a-zA-Z0-9_]/'", $treeSource);

        $runGraphSave = function (array $request) use ($root, $graphSource): array {
            $program = test_php_function_source($graphSource, 'save') . <<<'PHP'
$root = $argv[1];
$GLOBALS['req'] = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['saved'] = [];
$GLOBALS['messages'] = [];
require $root . '/include/global_constants.php';
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function cacti_log(...$a) {}
function __($t, ...$a) { return $t; }
function get_client_addr() { return '127.0.0.1'; }
function isset_request_var($k) { return array_key_exists($k, $GLOBALS['req']); }
function get_request_var($k) { return $GLOBALS['req'][$k] ?? ''; }
function get_filter_request_var($k) { return (int) ($GLOBALS['req'][$k] ?? 0); }
function get_nfilter_request_var($k) { return $GLOBALS['req'][$k] ?? ''; }
function form_input_validate($v, ...$a) { return $v; }
function raise_message($k, ...$a) { $GLOBALS['messages'][] = $k; }
function is_error_message() { return in_array('sql_injection', $GLOBALS['messages'], true); }
function db_column_exists($table, $column) { return true; }
function get_data_query_array($sqId) {
    return ['fields' => ['ifDescr' => ['direction' => 'input', 'name' => 'Description']]];
}
function db_fetch_cell_prepared($sql, $params = []) {
    if (str_contains($sql, 'FROM automation_graph_rules')) {
        return 5;
    }
    return '';
}
function sql_save($data, $table) { $GLOBALS['saved'][] = [$table, $data]; return 1; }
require $root . '/lib/api_automation.php';
register_shutdown_function(static function () {
    echo json_encode(['saved' => $GLOBALS['saved'], 'messages' => $GLOBALS['messages']], JSON_THROW_ON_ERROR);
});
save();
PHP;
            return $this->runPhpJson($program, [$root, json_encode($request, JSON_THROW_ON_ERROR)]);
        };

        // Graph rule item: arbitrary unquoted field and sensitive field must be rejected; valid DQ input field saved.
        $baseGraphItem = ['save_component_automation_graph_rule_item' => '1', 'id' => '1', 'item_id' => '0', 'sequence' => '1', 'operation' => '0', 'operator' => '1', 'pattern' => 'eth'];
        self::assertSame([], $runGraphSave(['field' => 'bogus_unquoted_field'] + $baseGraphItem)['saved']);
        self::assertSame([], $runGraphSave(['field' => 'h.snmp_community'] + $baseGraphItem)['saved']);
        self::assertCount(1, $runGraphSave(['field' => 'ifDescr'] + $baseGraphItem)['saved']);

        // Graph match item: dirty payload around valid column name and sensitive columns must be rejected; exact allowlisted saved.
        $baseGraphMatch = ['save_component_automation_match_item' => '1', 'id' => '1', 'item_id' => '0', 'sequence' => '1', 'operation' => '0', 'operator' => '1', 'pattern' => 'x'];
        self::assertSame([], $runGraphSave(['field' => "h.hostname' OR 1=1"] + $baseGraphMatch)['saved']);
        self::assertSame([], $runGraphSave(['field' => 'h.snmp_password'] + $baseGraphMatch)['saved']);
        self::assertCount(1, $runGraphSave(['field' => 'h.hostname'] + $baseGraphMatch)['saved']);

        $runTreeSave = function (array $request) use ($root, $treeSource): array {
            $program = test_php_function_source($treeSource, 'automation_tree_rules_form_save') . <<<'PHP'
$root = $argv[1];
$GLOBALS['req'] = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['saved'] = [];
$GLOBALS['messages'] = [];
require $root . '/include/global_constants.php';
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function cacti_log(...$a) {}
function __($t, ...$a) { return $t; }
function get_client_addr() { return '127.0.0.1'; }
function isset_request_var($k) { return array_key_exists($k, $GLOBALS['req']); }
function get_request_var($k) { return $GLOBALS['req'][$k] ?? ''; }
function get_filter_request_var($k) { return (int) ($GLOBALS['req'][$k] ?? 0); }
function get_nfilter_request_var($k) { return $GLOBALS['req'][$k] ?? ''; }
function form_input_validate($v, ...$a) { return $v; }
function raise_message($k, ...$a) { $GLOBALS['messages'][] = $k; }
function is_error_message() { return count(array_intersect(['sql_injection', 'field_invalid'], $GLOBALS['messages'])) > 0; }
function db_column_exists($table, $column) { return true; }
function db_fetch_cell_prepared($sql, $params = []) { return TREE_ITEM_TYPE_HOST; }
function sql_save($data, $table) { $GLOBALS['saved'][] = [$table, $data]; return 1; }
require $root . '/lib/api_automation.php';
register_shutdown_function(static function () {
    echo json_encode(['saved' => $GLOBALS['saved'], 'messages' => $GLOBALS['messages']], JSON_THROW_ON_ERROR);
});
automation_tree_rules_form_save();
PHP;
            return $this->runPhpJson($program, [$root, json_encode($request, JSON_THROW_ON_ERROR)]);
        };

        $baseTreeItem = ['save_component_automation_tree_rule_item' => '1', 'id' => '1', 'item_id' => '0', 'sequence' => '1', 'sort_type' => '1', 'search_pattern' => '.*', 'replace_pattern' => ''];
        self::assertSame([], $runTreeSave(['field' => 'h.snmp_community'] + $baseTreeItem)['saved']);
        self::assertSame([], $runTreeSave(['field' => 'h.hostname; DROP TABLE host'] + $baseTreeItem)['saved']);
        self::assertCount(1, $runTreeSave(['field' => 'h.description'] + $baseTreeItem)['saved']);
        self::assertCount(1, $runTreeSave(['field' => '0'] + $baseTreeItem)['saved']);
    }

    public function testRrdCleanerEnforcesPostCsrfActionAllowlistAndUnusedOnlyInvariant(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/rrdcleaner.php');

        self::assertStringContainsString("cacti_require_post_actions(array('actions', 'restart', 'remall', 'arcall'))", $source);
        self::assertStringContainsString('cacti_require_post_request();', $source);
        self::assertStringNotContainsString('$.get(\'rrdcleaner.php', $source);
        self::assertStringContainsString('window.confirm(', $source);
        self::assertStringContainsString('__csrf_magic: csrfMagicToken', $source);

        // Execute do_rrd() and remove_all_rrds() against an in-memory SQLite table containing active (in_cacti=1) and unused (in_cacti=0) rows.
        $program = test_php_function_source($source, 'do_rrd')
            . "\n" . test_php_function_source($source, 'remove_all_rrds')
            . <<<'PHP'
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE data_source_purge_temp (id INTEGER PRIMARY KEY, name TEXT, local_data_id INTEGER, in_cacti INTEGER)');
$db->exec('CREATE TABLE data_source_purge_action (name TEXT PRIMARY KEY, local_data_id INTEGER, action TEXT)');
$db->exec("INSERT INTO data_source_purge_temp VALUES (1, 'active.rrd', 10, 1), (2, 'unused.rrd', 0, 0), (3, 'unused2.rrd', 0, 0)");

function rrd_maintenance_cleanup_supported() { return true; }
function raise_message(...$a) {}
function __($t) { return $t; }
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function rrdclean_error_handler(...$a) {}
function get_nfilter_request_var($k) { return $GLOBALS['req'][$k] ?? ''; }
function db_fetch_row_prepared($sql, $params = []) {
    $stmt = $GLOBALS['db']->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_execute_prepared($sql, $params = []) {
    $sql = preg_replace('/ON DUPLICATE KEY UPDATE.*/s', '', $sql);
    $sql = preg_replace('/^INSERT INTO/', 'INSERT OR REPLACE INTO', $sql);
    $stmt = $GLOBALS['db']->prepare($sql);
    $stmt->execute($params);
}
function db_execute($sql) { $GLOBALS['db']->exec($sql); }

// 1. Disallowed drp_action is rejected before touching any rows.
$GLOBALS['req'] = ['drp_action' => '99'];
$_POST = ['chk_2' => 'on'];
$badDrp = do_rrd();

// 2. Valid drp_action=1 with active id (1), non-int id (2abc), and unused id (2): only id 2 is queued and deleted.
$GLOBALS['req'] = ['drp_action' => '1'];
$_POST = ['chk_1' => 'on', 'chk_2abc' => 'on', 'chk_2' => 'on'];
do_rrd();
$afterDoRrd = [
    'actions' => $db->query('SELECT name, action FROM data_source_purge_action ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
    'remaining' => $db->query('SELECT id, name, in_cacti FROM data_source_purge_temp ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
];

// 3. Disallowed raction is rejected; valid raction=3 queues remaining unused (id=3) and preserves active (id=1).
$GLOBALS['req'] = ['raction' => '2'];
$badRaction = remove_all_rrds();
$GLOBALS['req'] = ['raction' => '3'];
remove_all_rrds();
$afterRemoveAll = [
    'actions' => $db->query('SELECT name, action FROM data_source_purge_action ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
    'remaining' => $db->query('SELECT id, name, in_cacti FROM data_source_purge_temp ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
];

echo json_encode([
    'bad_drp' => $badDrp,
    'after_do_rrd' => $afterDoRrd,
    'bad_raction' => $badRaction,
    'after_remove_all' => $afterRemoveAll,
], JSON_THROW_ON_ERROR);
PHP;
        $data = $this->runPhpJson($program, []);

        self::assertFalse($data['bad_drp']);
        self::assertSame([['name' => 'unused.rrd', 'action' => '1']], $data['after_do_rrd']['actions']);
        self::assertSame([
            ['id' => 1, 'name' => 'active.rrd', 'in_cacti' => 1],
            ['id' => 3, 'name' => 'unused2.rrd', 'in_cacti' => 0],
        ], $data['after_do_rrd']['remaining']);

        self::assertFalse($data['bad_raction']);
        self::assertSame([
            ['name' => 'unused.rrd', 'action' => '1'],
            ['name' => 'unused2.rrd', 'action' => '3'],
        ], $data['after_remove_all']['actions']);
        self::assertSame([
            ['id' => 1, 'name' => 'active.rrd', 'in_cacti' => 1],
        ], $data['after_remove_all']['remaining']);
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function runPhpJson(string $program, array $args): array
    {
        $output = $this->runPhpRaw($program, $args);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @param list<string> $args */
    private function runPhpRaw(string $program, array $args): string
    {
        $command = array_merge([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, '--'], $args);
        $result = test_php_run($command);
        self::assertSame(0, $result['status'], $result['err'] . $result['out']);
        self::assertSame('', $result['err']);
        return $result['out'];
    }
}
