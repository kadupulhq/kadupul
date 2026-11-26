<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security;

use PHPUnit\Framework\TestCase;

final class ReportsDsstatsImportHardeningTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @param list<string> $args
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runPhp(string $code, array $args = []): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-r', $code, $this->root], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    public function testS401SingleIdWhitelistUpdateDoesNotWhitelistUnapprovedRows(): void
    {
        $dir = $this->makeTempDir('s4-01-whitelist');
        $whitelistPath = $dir . '/whitelist.json';
        $approvedHash = str_repeat('a', 32);
        $unapprovedHash = str_repeat('b', 32);
        $existingHash = str_repeat('c', 32);

        $code = <<<'PHP'
require $argv[1] . '/lib/input_whitelist.php';
$path = $argv[2];
$approved = $argv[3];
$unapproved = $argv[4];
$existing = $argv[5];

$records = [
    ['id' => 1, 'name' => 'Approved', 'hash' => $approved, 'input_string' => 'php /good.php'],
    ['id' => 2, 'name' => 'Unapproved', 'hash' => $unapproved, 'input_string' => 'sh -c id'],
    ['id' => 3, 'name' => 'Existing', 'hash' => $existing, 'input_string' => 'php /updated-not-approved.php'],
];

// Case 1: absent whitelist file + targeted single-ID update must only add target ID.
data_input_whitelist_update($path, static fn(): array => [$records, $approved], false, hrtime(true) / 1e9 + 5);
$afterAbsent = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

// Case 2: existing whitelist file + targeted single-ID update preserves already-whitelisted entries
// and still excludes unapproved database rows.
file_put_contents($path, json_encode([$existing => 'php /old-existing.php'], JSON_THROW_ON_ERROR));
data_input_whitelist_update($path, static fn(): array => [$records, $approved], false, hrtime(true) / 1e9 + 5);
$afterExisting = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

// Case 3: full update ($idHash === false) publishes all records.
data_input_whitelist_update($path, static fn(): array => [$records, false], false, hrtime(true) / 1e9 + 5);
$afterFull = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

echo json_encode(['absent' => $afterAbsent, 'existing' => $afterExisting, 'full' => $afterFull], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code, [$whitelistPath, $approvedHash, $unapprovedHash, $existingHash]);
        self::assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        self::assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$approvedHash => 'php /good.php'], $result['absent']);
        self::assertSame(
            [$approvedHash => 'php /good.php', $existingHash => 'php /old-existing.php'],
            $result['existing'],
        );
        self::assertSame(
            [
                $approvedHash => 'php /good.php',
                $unapprovedHash => 'sh -c id',
                $existingHash => 'php /updated-not-approved.php',
            ],
            $result['full'],
        );
    }

    public function testS901AndS403DsstatsHardening(): void
    {
        $dir = $this->makeTempDir('s9-01-dsstats');
        $rrdFile = $dir . "/x ds[evil','1','2');--].rrd";
        file_put_contents($rrdFile, 'stub');
        mkdir($dir . '/lib', 0700);
        file_put_contents($dir . '/lib/poller.php', '<?php');

        $code = <<<'PHP'
require $argv[1] . '/tests/Helpers/PhpSource.php';
$dir = $argv[2];
$rrdFile = $argv[3];

$config = ['base_path' => $dir, 'library_path' => $dir . '/lib', 'rra_path' => $dir, 'cacti_server_os' => 'unix'];
$debug = false;
$options = [
    'storage_location' => '0',
    'dsstats_enable' => 'on',
    'dsstats_parallel' => '1',
    'path_php_binary' => '/usr/bin/php',
    'path_dsstats_log' => '/tmp/ds.log',
];
$prepared = [];
$rawExecs = [];
$bgCalls = [];
$logs = [];

define('POLLER_VERBOSITY_MEDIUM', 3);
define('CACTI_ESCAPE_CHARACTER', "'");

function read_config_option($name) { return $GLOBALS['options'][$name] ?? ''; }
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function cacti_log($msg, ...$rest) { $GLOBALS['logs'][] = $msg; }
function rrd_check_path($path, ...$rest) { return true; }
function db_execute($sql) { $GLOBALS['rawExecs'][] = $sql; return true; }
function db_execute_prepared($sql, $params = []) { $GLOBALS['prepared'][] = [$sql, $params]; return true; }
function db_fetch_assoc($sql) {
    if (str_contains($sql, 'FROM data_template_rrd')) {
        return [['data_source_name' => 'traffic_in', 'data_source_type_id' => 1, 'rrd_step' => 300, 'rrd_maximum' => 'U']];
    }
    return [];
}
function array_rekey($rows, $key, $cols) {
    $out = [];
    foreach ($rows as $row) {
        if (is_array($cols)) {
            $entry = [];
            foreach ($cols as $col) { $entry[$col] = $row[$col]; }
            $out[$row[$key]] = $entry;
        } else {
            $out[$row[$key]] = $row[$cols];
        }
    }
    return $out;
}
function exec_background($cmd, $args = '') { $GLOBALS['bgCalls'][] = [$cmd, $args]; }
function rrdtool_pipe_command($argv, $facility = '') { return implode(' ', $argv); }

$fnSrc = file_get_contents($argv[1] . '/lib/functions.php');
eval(test_php_function_source($fnSrc, 'cacti_escapeshellarg'));

require $argv[1] . '/lib/dsstats.php';

// 1) Crafted filename with ds[...] must NOT be parsed as a DS name; nan/non-finite coerced to NULL.
$infoOutput = "filename = \"" . $rrdFile . "\"\n"
    . "rrd_version = \"0003\"\n"
    . "step = 300\n"
    . "ds[traffic_in].index = 0\n"
    . "ds[traffic_in].type = \"GAUGE\"\n"
    . "ds[bad'name].type = \"GAUGE\"\n"
    . "rra[0].cf = \"AVERAGE\"\n"
    . "rra[1].cf = \"MAX\"\n"
    . "OK u:0.00 s:0.00 r:0.00\n";
$graphOutput = "0x0\n12.500000\nnan\nOK u:0.01 s:0.02 r:0.03\n";

$in = fopen('php://temp', 'r+');
$out = fopen('php://temp', 'r+');
fwrite($out, $infoOutput . $graphOutput);
rewind($out);
$pipes = [$in, $out];
$avgpeak = dsstats_obtain_data_source_avgpeak_values(7, $rrdFile, 'daily', $pipes);

// 2) ERROR: response from rrdtool graph must abort and return null.
$in2 = fopen('php://temp', 'r+');
$out2 = fopen('php://temp', 'r+');
fwrite($out2, $infoOutput . "ERROR: opening file failed\n");
rewind($out2);
$pipes2 = [$in2, $out2];
$errored = dsstats_obtain_data_source_avgpeak_values(7, $rrdFile, 'daily', $pipes2);

// 3) dsstats_write_buffer must parameterize and filter invalid DS names & invalid intervals.
$stats = [
    7 => [
        'traffic_in' => ['AVG' => '12.500000', 'MAX' => 'NULL'],
        "evil','1','2');--" => ['AVG' => '1', 'MAX' => '2'],
    ],
];
dsstats_write_buffer($stats, 'daily');
dsstats_write_buffer($stats, 'daily; DROP TABLE user_auth;--');

// 4) dsstats_poller_output must parameterize and reject invalid DS names.
$rrdUpdate = [
    [
        'local_data_id' => 9,
        'times' => [
            1700000000 => [
                'traffic_in' => '42.5',
                "bad'ds" => '99',
            ],
        ],
    ],
];
dsstats_poller_output($rrdUpdate);

// 5) S4-03 callers: dsstats_poller_bottom escapes base_path; dsstats_launch_children passes argv array.
$config['base_path'] = '/opt/my cacti;id';
mkdir($dir . '/base-space', 0700);
$config['base_path'] = $dir . '/base-space';
dsstats_poller_bottom();
dsstats_launch_children('master');

echo json_encode([
    'avgpeak' => $avgpeak,
    'errored' => $errored,
    'prepared' => $prepared,
    'rawExecs' => $rawExecs,
    'bgCalls' => $bgCalls,
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code, [$dir, $rrdFile]);
        self::assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        self::assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['traffic_in' => ['AVG' => '12.500000', 'MAX' => 'NULL']], $result['avgpeak']);
        self::assertNull($result['errored']);
        self::assertSame([], $result['rawExecs']);

        // 1 write_buffer (daily only) + 2 poller_output (cache + last) = 3 prepared statements
        self::assertCount(3, $result['prepared']);
        self::assertStringContainsString('INSERT INTO data_source_stats_daily', $result['prepared'][0][0]);
        self::assertStringContainsString('VALUES (?, ?, ?, ?)', $result['prepared'][0][0]);
        self::assertSame([7, 'traffic_in', '12.500000', null], $result['prepared'][0][1]);

        self::assertStringContainsString('data_source_stats_hourly_cache', $result['prepared'][1][0]);
        self::assertSame(9, $result['prepared'][1][1][0]);
        self::assertSame('traffic_in', $result['prepared'][1][1][1]);
        self::assertSame('42.5', $result['prepared'][1][1][3]);

        self::assertStringContainsString('data_source_stats_hourly_last', $result['prepared'][2][0]);
        self::assertSame([9, 'traffic_in', '42.5', '42.5'], $result['prepared'][2][1]);

        // S4-03 checks
        self::assertStringContainsString("'" . $dir . "/base-space/poller_dsstats.php'", $result['bgCalls'][0][1]);
        self::assertIsArray($result['bgCalls'][1][1]);
        self::assertSame([$dir . '/base-space/poller_dsstats.php', '--type=child', '--child=1'], $result['bgCalls'][1][1]);
    }

    public function testS307S902S903AndS403ReportsAuthorizationHardening(): void
    {
        $dir = $this->makeTempDir('s9-02-reports');
        mkdir($dir . '/include', 0700);
        mkdir($dir . '/lib', 0700);
        file_put_contents($dir . '/include/global_arrays.php', '<?php');
        foreach (['poller.php', 'data_query.php', 'html_tree.php', 'html_utility.php'] as $lib) {
            file_put_contents($dir . '/lib/' . $lib, '<?php');
        }

        $code = <<<'PHP'
namespace ReportsHardeningHarness;

require $argv[1] . '/tests/Helpers/PhpSource.php';
require $argv[1] . '/include/global_constants.php';

$dir = $argv[2];
$GLOBALS['config'] = ['base_path' => $dir, 'include_path' => $dir . '/include', 'library_path' => $dir . '/lib', 'url_path' => '/'];
$GLOBALS['alignment'] = [1 => 'left'];
$GLOBALS['graph_timespans'] = [1 => 'Last Hour'];
$GLOBALS['item_types'] = [
    REPORTS_ITEM_GRAPH => 'Graph',
    REPORTS_ITEM_TEXT => 'Text',
    REPORTS_ITEM_TREE => 'Tree',
    REPORTS_ITEM_HOST => 'Device',
];
$GLOBALS['reports_actions'] = [REPORTS_DELETE => 'Delete'];
$_SESSION = ['sess_user_id' => 5];

$GLOBALS['queries'] = [];
$GLOBALS['messages'] = [];
$GLOBALS['saved'] = [];
$GLOBALS['bgCalls'] = [];
$GLOBALS['request'] = [];
$GLOBALS['allowed'] = [
    'graphs' => [10],
    'devices' => [20],
    'trees' => [30],
    'templates' => [40],
    'reports' => [7],
];

function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function __($t, ...$a) { return $a ? vsprintf($t, $a) : $t; }
function __esc($t, ...$a) { return htmlspecialchars(__($t, ...$a), ENT_QUOTES); }
function html_escape($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function raise_message($id, ...$a) { $GLOBALS['messages'][] = $id; }
function is_error_message() { return !empty($_SESSION['sess_error_fields']); }
function cacti_authorize_resource($u, $id, $type) {
    if ($type === 'report_item') {
        return (int) $id === 50;
    }
    return in_array((int) $id, $GLOBALS['allowed']['reports'], true);
}
function is_graph_allowed($id, $u = 0) { return in_array((int) $id, $GLOBALS['allowed']['graphs'], true); }
function is_device_allowed($id, $u = 0) { return in_array((int) $id, $GLOBALS['allowed']['devices'], true); }
function is_tree_allowed($id, $u = 0, $e = false) { return in_array((int) $id, $GLOBALS['allowed']['trees'], true); }
function is_graph_template_allowed($id, $u = 0) { return in_array((int) $id, $GLOBALS['allowed']['templates'], true); }
function get_graph_title($id) { return 'SECRET-GRAPH-' . $id; }
function isset_request_var($n) { return array_key_exists($n, $GLOBALS['request']); }
function isempty_request_var($n) { return empty($GLOBALS['request'][$n]); }
function get_request_var($n, $d = '') { return $GLOBALS['request'][$n] ?? $d; }
function get_nfilter_request_var($n, $d = '') { return $GLOBALS['request'][$n] ?? $d; }
function get_filter_request_var($n, $f = FILTER_VALIDATE_INT) { return $GLOBALS['request'][$n] ?? ''; }
function set_request_var($n, $v) { $GLOBALS['request'][$n] = $v; }
function validate_store_request_vars($filters, $prefix) {
    foreach ($filters as $k => $f) {
        if (isset($GLOBALS['request'][$k])) {
            $_SESSION[$prefix . '_' . $k] = $GLOBALS['request'][$k];
        }
    }
}
function form_input_validate($v, ...$rest) { return $v; }
function input_validate_input_number($v) {}
function read_config_option($n) { return $n === 'path_php_binary' ? '/usr/bin/php' : ''; }
function read_user_setting($n, ...$rest) { return 0; }
function get_timespan(&$ts, $t, $span, $fw) { $ts = ['begin_now' => $t - 3600, 'end_now' => $t]; }
function db_qstr_rlike($p) { return "REGEXP '" . addslashes($p) . "'"; }
function exec_background($cmd, $args = '') { $GLOBALS['bgCalls'][] = [$cmd, $args]; }
function sql_save($save, $table) { $GLOBALS['saved'][] = [$table, $save]; return 1; }
function reports_item_resequence($id) {}
function get_reports_page() { return 'reports_user.php'; }
function is_reports_admin() { return false; }
function header($h) {}
function html_header(...$a) {}
function html_start_box(...$a) {}
function html_end_box(...$a) {}
function form_start(...$a) {}
function form_end(...$a) {}
function form_alternate_row(...$a) {}
function general_header() {}
function bottom_footer() {}
function escape_page_action($a, $k) { return $a[$k] ?? ''; }
function get_allowed_trees() { return [['id' => 30, 'name' => 'Allowed Tree']]; }
function get_allowed_branches($w = '') { return []; }
function array_rekey($r, $k, $v) { return array_column($r, $v, $k); }
function draw_edit_form($a) { $GLOBALS['edit_form'] = $a; }
function inject_form_variables($fields, ...$args) { return $fields; }
function reports_tabs($id) {}
function get_current_page() { return 'reports_user.php'; }
function form_hidden_box(...$a) {}
function form_save_button(...$a) {}
function reports_graph_area($graphs, ...$rest) {
    $ids = array_column($graphs, 'local_graph_id');
    return '<GRAPHS:' . implode(',', $ids) . '>';
}

function db_fetch_cell_prepared($sql, $params = []) {
    $GLOBALS['queries'][] = [$sql, $params];
    if (str_contains($sql, 'SELECT id FROM reports WHERE id = ?')) { return (int) $params[0] === 7 ? 7 : false; }
    if (str_contains($sql, 'SELECT report_id FROM reports_items')) { return 7; }
    if (str_contains($sql, 'SELECT cformat FROM reports')) { return ''; }
    if (str_contains($sql, 'SELECT name FROM reports WHERE id = ?')) { return 'SECRET-REPORT-' . $params[0]; }
    if (str_contains($sql, 'SELECT description FROM host WHERE id = ?')) { return 'SECRET-HOST-' . $params[0]; }
    if (str_contains($sql, 'SELECT name FROM graph_templates WHERE id = ?')) { return 'SECRET-TPL-' . $params[0]; }
    if (str_contains($sql, 'SELECT name FROM graph_tree WHERE id = ?')) { return 'SECRET-TREE-' . $params[0]; }
    if (str_contains($sql, 'SELECT title_cache')) { return 'SECRET-GRAPH-TITLE-' . $params[0]; }
    if (str_contains($sql, 'SELECT host_id') && str_contains($sql, 'graph_tree_items')) { return 0; }
    if (str_contains($sql, 'SELECT count(*)')) { return 0; }
    if (str_contains($sql, 'SELECT MAX(sequence)')) { return 1; }
    return 1;
}
function db_fetch_row_prepared($sql, $params = []) {
    $GLOBALS['queries'][] = [$sql, $params];
    if (str_contains($sql, 'FROM graph_tree_items')) {
        return ['id' => (int) $params[0], 'graph_tree_id' => 999, 'host_id' => 999, 'local_graph_id' => 0, 'title' => 'SECRET-BRANCH'];
    }
    if (str_contains($sql, 'FROM reports_items WHERE id = ?')) {
        return ['id' => 50, 'report_id' => 7, 'item_type' => REPORTS_ITEM_GRAPH, 'graph_template_id' => 999, 'host_id' => 999, 'local_graph_id' => 999, 'tree_id' => 999, 'branch_id' => 0];
    }
    if (str_contains($sql, 'FROM reports')) {
        return ['id' => 7, 'name' => 'My Report', 'alignment' => 1, 'font_size' => 10, 'cformat' => '', 'graph_width' => 400, 'graph_height' => 100, 'thumbnails' => ''];
    }
    return [];
}
function db_fetch_assoc_prepared($sql, $params = []) {
    $GLOBALS['queries'][] = [$sql, $params];
    if (str_contains($sql, 'FROM reports_items')) {
        return [
            ['id' => 1, 'sequence' => 1, 'item_type' => REPORTS_ITEM_GRAPH, 'local_graph_id' => 999, 'align' => 1, 'timespan' => 1, 'font_size' => 10],
            ['id' => 2, 'sequence' => 2, 'item_type' => REPORTS_ITEM_HOST, 'host_id' => 999, 'graph_template_id' => 999, 'graph_name_regexp' => '', 'align' => 1, 'timespan' => 1, 'font_size' => 10],
            ['id' => 3, 'sequence' => 3, 'item_type' => REPORTS_ITEM_TREE, 'tree_id' => 999, 'branch_id' => 888, 'tree_cascade' => '', 'graph_name_regexp' => '', 'align' => 1, 'timespan' => 1, 'font_size' => 10],
        ];
    }
    if (str_contains($sql, 'FROM graph_tree_items')) {
        return [
            ['id' => 101, 'graph_tree_id' => 30, 'parent' => 0, 'host_id' => 0, 'local_graph_id' => 999, 'title' => ''],
            ['id' => 102, 'graph_tree_id' => 30, 'parent' => 0, 'host_id' => 0, 'local_graph_id' => 10, 'title' => ''],
        ];
    }
    return [];
}
function db_execute_prepared($sql, $params = []) { return true; }
class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }

$reportsSrc = file_get_contents($argv[1] . '/lib/reports.php');
$htmlReportsSrc = file_get_contents($argv[1] . '/lib/html_reports.php');

// Extract functions replacing exit; with return; for in-process testing.
foreach (['reports_add_graphs', 'reports_expand_tree', 'reports_poller_bottom'] as $fn) {
    eval('namespace ReportsHardeningHarness; ' . \test_php_function_source($reportsSrc, $fn));
}
foreach (['reports_form_save', 'reports_form_actions', 'reports_item_validate', 'reports_item_edit', 'display_reports_items'] as $fn) {
    $src = \test_php_function_source($htmlReportsSrc, $fn);
    $src = str_replace('function reports_form_save(): never', 'function reports_form_save()', $src);
    $src = str_replace('exit;', 'return;', $src);
    eval('namespace ReportsHardeningHarness; ' . $src);
}

// 1) S3-07: reports_add_graphs blocks unauthorized graph BEFORE querying graph_local.
$GLOBALS['queries'] = [];
$GLOBALS['messages'] = [];
$addDenied = reports_add_graphs(7, 999, 1, 1);
$addDeniedQueries = $GLOBALS['queries'];
$addDeniedMessages = $GLOBALS['messages'];

// 2) S9-02: reports_form_save blocks unauthorized graph, host, tree, template.
$GLOBALS['saved'] = [];
$GLOBALS['messages'] = [];
$GLOBALS['request'] = [
    'save_component_report_item' => '1',
    'report_id' => '7',
    'id' => '0',
    'item_type' => (string) REPORTS_ITEM_GRAPH,
    'local_graph_id' => '999',
    'host_id' => '999',
    'tree_id' => '999',
    'branch_id' => '888',
    'graph_template_id' => '999',
];
reports_form_save();
$saveDeniedCount = count($GLOBALS['saved']);
$saveErrorFields = array_keys($_SESSION['sess_error_fields'] ?? []);

// 3) S3-07 + S9-02: display_reports_items masks unauthorized metadata.
ob_start();
display_reports_items(7);
$displayHtml = ob_get_clean();

// 4) S9-02: reports_item_validate resets unauthorized objects without querying graph_local/host.
$GLOBALS['queries'] = [];
$_SESSION['sess_report_item_host_id'] = -1;
$_SESSION['sess_report_item_local_graph_id'] = 999;
$_SESSION['sess_report_item_site_id'] = 5;
$_SESSION['sess_report_item_host_template_id'] = 3;
$_SESSION['sess_report_item_graph_template_id'] = 999;
$GLOBALS['request'] = [
    'tree_id' => 0,
    'branch_id' => 0,
    'host_id' => 999,
    'local_graph_id' => 999,
    'site_id' => 5,
    'host_template_id' => 3,
    'graph_template_id' => 999,
];
$validateJson = json_decode(reports_item_validate(), true, 512, JSON_THROW_ON_ERROR);
$validateQueries = $GLOBALS['queries'];

// 5) S9-02: reports_expand_tree skips unauthorized graph leaf and does not call reports_graph_area when count == 0.
$report = ['id' => 7, 'user_id' => 5, 'graph_columns' => 1];
$item = ['tree_id' => 30, 'branch_id' => 0, 'tree_cascade' => '', 'timespan' => 1, 'align' => 1, 'font_size' => 10, 'graph_name_regexp' => '^OnlyMatch$'];
$expandHtml = reports_expand_tree($report, $item, 0, REPORTS_OUTPUT_STDOUT, false, 'classic', false);

// 6) S9-03: reports_form_actions skips unauthorized chk_<id> without querying reports.name.
$GLOBALS['queries'] = [];
$_POST = ['chk_7' => 'on', 'chk_999' => 'on'];
$GLOBALS['request'] = ['drp_action' => REPORTS_DELETE];
ob_start();
reports_form_actions();
$actionsHtml = ob_get_clean();
$actionsQueries = $GLOBALS['queries'];

// 7) S9-02: reports_item_edit masks unauthorized graph_template_id, host_id, local_graph_id.
$GLOBALS['request'] = ['id' => '7', 'item_id' => '50'];
ob_start();
reports_item_edit();
ob_end_clean();
$editFormFields = $GLOBALS['edit_form']['fields'] ?? [];
$editMetadata = [
    'host' => $editFormFields['host_id']['value'] ?? '',
    'template' => $editFormFields['graph_template_id']['value'] ?? '',
    'graph' => $editFormFields['local_graph_id']['value'] ?? '',
];

// 8) S4-03: reports_poller_bottom passes argv array.
reports_poller_bottom();

echo json_encode([
    'addDenied' => $addDenied,
    'addDeniedQueries' => $addDeniedQueries,
    'addDeniedMessages' => $addDeniedMessages,
    'saveDeniedCount' => $saveDeniedCount,
    'saveErrorFields' => $saveErrorFields,
    'displayHtml' => $displayHtml,
    'validateJson' => $validateJson,
    'validateQueries' => $validateQueries,
    'expandHtml' => $expandHtml,
    'actionsHtml' => $actionsHtml,
    'actionsQueries' => $actionsQueries,
    'editMetadata' => $editMetadata,
    'bgCalls' => $GLOBALS['bgCalls'],
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code, [$dir]);
        self::assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        self::assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['addDenied']);
        self::assertSame([], $result['addDeniedQueries']);
        self::assertSame(['reports_graph_not_found'], $result['addDeniedMessages']);

        self::assertSame(0, $result['saveDeniedCount']);
        self::assertContains('local_graph_id', $result['saveErrorFields']);
        self::assertContains('host_id', $result['saveErrorFields']);
        self::assertContains('tree_id', $result['saveErrorFields']);
        self::assertContains('graph_template_id', $result['saveErrorFields']);

        self::assertStringNotContainsString('SECRET-GRAPH-999', $result['displayHtml']);
        self::assertStringNotContainsString('SECRET-HOST-999', $result['displayHtml']);
        self::assertStringNotContainsString('SECRET-TPL-999', $result['displayHtml']);
        self::assertStringNotContainsString('SECRET-TREE-999', $result['displayHtml']);
        self::assertStringNotContainsString('SECRET-BRANCH', $result['displayHtml']);
        self::assertStringContainsString('Restricted', $result['displayHtml']);

        self::assertSame([], $result['validateQueries']);
        self::assertTrue($result['validateJson']['local_graph_id']);
        self::assertTrue($result['validateJson']['host_template_id']);
        self::assertTrue($result['validateJson']['graph_template_id']);
        self::assertTrue($result['validateJson']['site_id']);

        self::assertStringNotContainsString('SECRET-GRAPH-TITLE-999', $result['expandHtml']);
        self::assertStringNotContainsString('<GRAPHS:', $result['expandHtml']);

        self::assertStringContainsString('SECRET-REPORT-7', $result['actionsHtml']);
        self::assertStringNotContainsString('SECRET-REPORT-999', $result['actionsHtml']);
        self::assertCount(1, $result['actionsQueries']);

        self::assertSame(['host' => 'None', 'template' => 'None', 'graph' => 'None'], $result['editMetadata']);

        self::assertSame(['-q', $dir . '/poller_reports.php'], $result['bgCalls'][0][1]);
    }

    public function testS1108DisabledAndLockedAccountsAreSkippedByReportsPoller(): void
    {
        $dir = $this->makeTempDir('s11-08-poller-reports');
        mkdir($dir . '/include', 0700);
        mkdir($dir . '/lib', 0700);
        copy($this->root . '/poller_reports.php', $dir . '/poller_reports.php');
        foreach (['poller', 'rrd', 'reports', 'time', 'html_reports'] as $lib) {
            file_put_contents($dir . '/lib/' . $lib . '.php', '<?php');
        }

        $cliCheck = <<<'PHP'
<?php
$root = getenv('S11_ROOT');
$outFile = getenv('S11_OUT');
require_once $root . '/tests/Helpers/PhpSource.php';
require_once $root . '/include/global_constants.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT NOT NULL DEFAULT 'on', locked TEXT NOT NULL DEFAULT '')");
$db->exec("INSERT INTO user_auth VALUES (1, 'active', 'on', ''), (2, 'disabled', '', ''), (3, 'locked', 'on', 'on')");

$config = ['base_path' => dirname(__DIR__)];
$generated = [];
$logs = [];

$htmlReportsSrc = file_get_contents($root . '/lib/html_reports.php');
eval(test_php_function_source($htmlReportsSrc, 'reports_html_account_exists'));

function register_process_start(...$a) { return true; }
function unregister_process(...$a) {}
function db_table_exists($t) { return true; }
function read_config_option($n) { return 300; }
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function reports_log($m, ...$a) { $GLOBALS['logs'][] = $m; }
function db_fetch_assoc_prepared($sql, $p = []) {
    return [
        ['id' => 11, 'name' => 'Active Report', 'user_id' => 1, 'email' => 'a@example.invalid'],
        ['id' => 12, 'name' => 'Disabled Report', 'user_id' => 2, 'email' => 'd@example.invalid'],
        ['id' => 13, 'name' => 'Locked Report', 'user_id' => 3, 'email' => 'l@example.invalid'],
    ];
}
function db_fetch_row_prepared($sql, $p = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($p);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_fetch_cell_prepared($sql, $p = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($p);
    return $q->fetchColumn();
}
function db_execute_prepared($sql, $p = []) { return true; }
function generate_report($report, $force = false) {
    $GLOBALS['generated'][] = $report['id'];
    return true;
}

register_shutdown_function(static function () use ($outFile): void {
    file_put_contents($outFile, json_encode([
        'generated' => $GLOBALS['generated'],
        'account_exists' => [
            1 => (bool) reports_html_account_exists(1),
            2 => (bool) reports_html_account_exists(2),
            3 => (bool) reports_html_account_exists(3),
        ],
        'logs' => $GLOBALS['logs'],
    ], JSON_THROW_ON_ERROR));
});
PHP;
        file_put_contents($dir . '/include/cli_check.php', $cliCheck);

        $outFile = $dir . '/result.json';
        $env = getenv();
        $env['S11_ROOT'] = $this->root;
        $env['S11_OUT'] = $outFile;

        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', $dir . '/poller_reports.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr . (string) $stdout);
        self::assertSame('', $stderr);

        $result = json_decode((string) file_get_contents($outFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([11], $result['generated']);
        self::assertSame([1 => true, 2 => false, 3 => false], $result['account_exists']);
    }

    public function testS603ExportEncodesSnmpFieldNameAndSandboxesRawXmlOutput(): void
    {
        $code = <<<'PHP'
require $argv[1] . '/tests/Helpers/PhpSource.php';

$exportSrc = file_get_contents($argv[1] . '/lib/export.php');
eval(test_php_function_source($exportSrc, 'xml_character_encode'));
eval(test_php_function_source($exportSrc, 'data_query_to_xml'));

$fields_data_query_edit = [];
$fields_data_query_item_edit = [];
function html_escape($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function get_hash_version($t) { return '0100'; }
function get_hash_data_query($id, $t = '') { return str_repeat('1', 32); }
function get_hash_data_template($id, $t = '') { return str_repeat('2', 32); }
function db_fetch_row_prepared($sql, $p = []) { return ['id' => 1, 'name' => 'DQ']; }
function db_fetch_assoc_prepared($sql, $p = []) {
    if (str_contains($sql, 'snmp_query_graph_rrd_sv') || str_contains($sql, 'snmp_query_graph_sv')) {
        return [];
    }
    if (str_contains($sql, 'snmp_query_graph_rrd')) {
        return [['snmp_field_name' => 'ifIn<script xmlns="http://www.w3.org/1999/xhtml">', 'data_template_id' => 2, 'data_template_rrd_id' => 3]];
    }
    if (str_contains($sql, 'snmp_query_graph')) {
        return [['id' => 10, 'name' => 'G']];
    }
    return [];
}

$xml = data_query_to_xml(1);
echo $xml;
PHP;

        $run = $this->runPhp($code);
        self::assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        self::assertSame('', $run['stderr']);
        self::assertStringContainsString(
            '<snmp_field_name>ifIn&lt;script xmlns=&quot;http://www.w3.org/1999/xhtml&quot;&gt;</snmp_field_name>',
            $run['stdout'],
        );
        self::assertStringNotContainsString('<script', $run['stdout']);

        $templatesExport = (string) file_get_contents($this->root . '/templates_export.php');
        self::assertStringContainsString("header('Content-Type: text/plain; charset=utf-8');", $templatesExport);
        self::assertStringContainsString("header('X-Content-Type-Options: nosniff');", $templatesExport);
        self::assertStringContainsString("header(\"Content-Security-Policy: default-src 'none'; sandbox\");", $templatesExport);
    }

    public function testS606PackageImportHardening(): void
    {
        $dir = $this->makeTempDir('s6-06-package');
        mkdir($dir . '/include', 0700);
        mkdir($dir . '/scripts', 0700);
        file_put_contents($dir . '/include/config.php', '<?php $secret = 1;');
        file_put_contents($dir . '/scripts/existing.php', '<?php echo 1;');
        $uploadFile = $dir . '/unsigned.xml.gz';
        file_put_contents($uploadFile, 'unsigned-bytes');

        $code = <<<'PHP'
namespace PackageImportHardeningHarness;

require $argv[1] . '/tests/Helpers/PhpSource.php';
$dir = $argv[2];
$uploadFile = $argv[3];

$GLOBALS['config'] = ['base_path' => $dir];
$GLOBALS['image_types'] = [];
$GLOBALS['detailsCalled'] = false;
$GLOBALS['displayCalled'] = false;
$GLOBALS['messages'] = [];
$GLOBALS['headers'] = [];
$GLOBALS['request'] = [
    'save_component_import' => '1',
    'preview_only' => 'on',
    'data_source_profile' => '1',
];
$_SESSION = [];
$_POST = [];
$_FILES = ['import_file' => ['tmp_name' => $uploadFile]];

define('MESSAGE_LEVEL_ERROR', 3);
define('MESSAGE_LEVEL_INFO', 1);

function __($t, ...$a) { return $a ? vsprintf($t, $a) : $t; }
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function isset_request_var($n) { return array_key_exists($n, $GLOBALS['request']); }
function get_request_var($n, $d = '') { return $GLOBALS['request'][$n] ?? $d; }
function get_nfilter_request_var($n, $d = '') { return $GLOBALS['request'][$n] ?? $d; }
function get_filter_request_var($n) { return $GLOBALS['request'][$n] ?? '0'; }
function validate_store_request_vars($f, $p) { $GLOBALS['stored_filters'] = array_keys($f); }
function read_config_option($n) { return ''; }
function raise_message($id, $m = '', $l = 0) { $GLOBALS['messages'][] = [$id, $m]; }
function header($h) { $GLOBALS['headers'][] = $h; }
function html_escape($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function get_default_profile() { return 1; }
function import_read_package_data($f, &$pk) { return false; }
function import_package_get_name($f) { $GLOBALS['detailsCalled'] = true; return 'Evil'; }
function import_package_get_details($f) { $GLOBALS['detailsCalled'] = true; return ['class' => '']; }
function import_display_package_data(...$a) { $GLOBALS['displayCalled'] = true; }
function package_prepare_import_array(...$a) {}
function import_package(...$a) { return false; }
function package_public_key_is_trusted($k) { return false; }

$fnSrc = file_get_contents($argv[1] . '/lib/functions.php');
eval('namespace PackageImportHardeningHarness; ' . \test_php_function_source($fnSrc, 'validate_relative_path_within'));
eval('namespace PackageImportHardeningHarness; ' . \test_php_function_source($fnSrc, 'cacti_path_is_within'));

$pkgSrc = file_get_contents($argv[1] . '/package_import.php');
foreach (['package_import_write_session_file', 'package_import_normalize_selected_file', 'package_import_file_name_matches', 'package_file_get_contents', 'package_diff_file', 'validate_request_vars', 'get_import_form', 'form_save'] as $fn) {
    $src = \test_php_function_source($pkgSrc, $fn);
    $src = str_replace('exit;', 'return;', $src);
    eval('namespace PackageImportHardeningHarness; ' . $src);
}

// 1) Preview of unsigned upload must fail closed, never save to session, never call get_details/display.
ob_start();
form_save();
$previewOutput = ob_get_clean();
$sessionAfterUnsigned = isset($_SESSION['sess_import_package']);

// 2) Session retry of unsigned package must unlink temporary cacti_pkg_* file.
$_FILES = [];
$_SESSION['sess_import_package'] = 'tampered';
$GLOBALS['request']['preview_only'] = '';
$beforeTmp = glob(sys_get_temp_dir() . '/cacti_pkg_*') ?: [];
form_save();
$afterTmp = glob(sys_get_temp_dir() . '/cacti_pkg_*') ?: [];
$leakedTmp = array_values(array_diff($afterTmp, $beforeTmp));
$sessionAfterTamperedRetry = isset($_SESSION['sess_import_package']);

// 3) package_diff_file rejects include/config.php and produces identical response for existing vs missing scripts/ without signed session package.
$_SESSION = [];
$GLOBALS['request']['filename'] = 'include/config.php';
ob_start();
package_diff_file();
$diffConfig = ob_get_clean();

$GLOBALS['request']['filename'] = 'scripts/existing.php';
ob_start();
package_diff_file();
$diffExisting = ob_get_clean();

$GLOBALS['request']['filename'] = 'scripts/nonexistent.php';
ob_start();
package_diff_file();
$diffMissing = ob_get_clean();

// 4) trust_signer removed from form and filters.
$formFields = array_keys(get_import_form(1));

echo json_encode([
    'previewOutput' => $previewOutput,
    'detailsCalled' => $GLOBALS['detailsCalled'],
    'displayCalled' => $GLOBALS['displayCalled'],
    'sessionAfterUnsigned' => $sessionAfterUnsigned,
    'sessionAfterTamperedRetry' => $sessionAfterTamperedRetry,
    'leakedTmp' => $leakedTmp,
    'diffConfig' => $diffConfig,
    'diffExisting' => $diffExisting,
    'diffMissing' => $diffMissing,
    'formFields' => $formFields,
    'storedFilters' => $GLOBALS['stored_filters'] ?? [],
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code, [$dir, $uploadFile]);
        self::assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        self::assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('signature verification failed', $result['previewOutput']);
        self::assertFalse($result['detailsCalled']);
        self::assertFalse($result['displayCalled']);
        self::assertFalse($result['sessionAfterUnsigned']);
        self::assertFalse($result['sessionAfterTamperedRetry']);
        self::assertSame([], $result['leakedTmp']);
        self::assertStringContainsString('Invalid file path', $result['diffConfig']);
        self::assertSame('New file does not exist', $result['diffExisting']);
        self::assertSame('New file does not exist', $result['diffMissing']);
        self::assertNotContains('trust_signer', $result['formFields']);
        self::assertNotContains('trust_signer', $result['storedFilters']);
    }
}
