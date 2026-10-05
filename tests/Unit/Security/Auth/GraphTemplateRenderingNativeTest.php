<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\GraphTemplateRenderingNative;

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

function renderTemplates(string $mode): array
{
    $root = dirname(__DIR__, 4);
    $program = <<<'CHILD'
        require $argv[1] . '/include/global_constants.php';
        require $argv[1] . '/lib/functions.php';
        require $argv[1] . '/lib/html_utility.php';
        require $argv[1] . '/lib/html.php';
        require $argv[1] . '/lib/html_form.php';
        require $argv[1] . '/lib/html_validate.php';
        require $argv[1] . '/lib/html_graph.php';
        $db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $db->exec("CREATE TABLE graph_templates (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE graph_templates_graph (id INTEGER, graph_template_id INTEGER, local_graph_id INTEGER);
            CREATE TABLE graph_local (id INTEGER, graph_template_id INTEGER);
            CREATE TABLE data_template (id INTEGER, name TEXT);
            CREATE TABLE data_template_rrd (id INTEGER, data_template_id INTEGER, data_source_name TEXT, local_data_id INTEGER);
            CREATE TABLE data_template_data (id INTEGER, data_template_id INTEGER, local_data_id INTEGER);
            CREATE TABLE graph_templates_item (id INTEGER, task_item_id INTEGER, local_graph_id INTEGER, graph_template_id INTEGER);
            CREATE TABLE snmp_query (id INTEGER, name TEXT);
            CREATE TABLE snmp_query_graph (id INTEGER, graph_template_id INTEGER);
            INSERT INTO graph_templates VALUES (7, 'Graph <template> &'), (8, 'Unused template');
            INSERT INTO graph_templates_graph VALUES (70, 7, 0), (71, 7, 99);
            INSERT INTO graph_local VALUES (99, 7);
            INSERT INTO data_template VALUES (3, 'Data <template>');
            INSERT INTO data_template_rrd VALUES (30, 3, 'traffic', 0), (31, 3, 'local', 99);
            INSERT INTO data_template_data VALUES (300, 3, 0), (301, 3, 99);
            INSERT INTO graph_templates_item VALUES (1, 30, 0, 7), (2, 31, 99, 7);
            INSERT INTO snmp_query VALUES (4, 'Query <template>');
            INSERT INTO snmp_query_graph VALUES (5, 7);");
        function nativeQuery($sql, $params = array()) {
            $GLOBALS['queries'][] = array($sql, $params);
            $statement = $GLOBALS['db']->prepare($sql);
            $statement->execute($params);
            return $statement;
        }
        function db_fetch_cell_prepared($sql, $params) { return nativeQuery($sql, $params)->fetchColumn(); }
        function db_fetch_row_prepared($sql, $params) { return nativeQuery($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: array(); }
        function db_fetch_assoc_prepared($sql, $params) { return nativeQuery($sql, $params)->fetchAll(PDO::FETCH_ASSOC); }
        function db_fetch_cell($sql) { return nativeQuery($sql)->fetchColumn(); }
        function db_fetch_assoc($sql) { return nativeQuery($sql)->fetchAll(PDO::FETCH_ASSOC); }
        function db_table_exists($table) { return false; }
        function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
        function __esc($text, ...$args) { return html_escape(__($text, ...$args)); }
        function is_realm_allowed($realm) { return false; }
        function is_view_allowed($view) { return $view === 'graph_settings'; }
        function get_allowed_devices($where = '') { return array(); }
        function get_allowed_graph_templates(...$args) { return nativeQuery('SELECT id, name FROM graph_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
        function graph_template_has_override($id) { return $id === 7; }
        function api_plugin_hook($name, ...$args) {}
        function api_plugin_hook_function($name, $value) { return $value; }
        function draw_nontemplated_fields_graph(...$args) { $GLOBALS['drawn']['graph'] = $args; return 1; }
        function draw_nontemplated_fields_graph_item(...$args) { $GLOBALS['drawn']['graph_item'] = $args; return 1; }
        function draw_nontemplated_fields_data_source(...$args) { $GLOBALS['drawn']['data'] = $args; return 1; }
        function draw_nontemplated_fields_data_source_item(...$args) { $GLOBALS['drawn']['data_item'] = $args; return 1; }
        function draw_nontemplated_fields_custom_data(...$args) { $GLOBALS['drawn']['custom'] = $args; return 1; }
        final class CactiSecureHeaders { public static function getNonceAttribute() { return 'nonce="fixture"'; } }
        $themes = array('classic' => 'Classic');
        $config = array('base_path' => $argv[1], 'url_path' => '/kadupul/', 'poller_id' => 1, 'is_web' => false,
            'config_options_array' => array('selected_theme' => 'classic', 'autocomplete_enabled' => '', 'allow_graph_dates_in_future' => '', 'log_validation' => '', 'hide_form_description' => 'off'));
        $_SERVER['SCRIPT_NAME'] = '/graphs_new.php';
        $_SERVER['REQUEST_URI'] = '/graphs_new.php?action=create';
        $_REQUEST = array('host_id' => '-1', 'graph_template_id' => '7', 'graphs' => '10', 'columns' => '2', 'thumbnails' => 'true', 'rfilter' => 'filter');
        $_CACTI_REQUEST = array();
        $_SESSION = array('sess_user_id' => 42, 'selected_theme' => 'classic', 'sess_user_config_array' => array('page_refresh' => '15'),
            'sess_current_timespan' => 1, 'sess_current_timeshift' => 1, 'sess_realtime_window' => 300, 'sess_realtime_dsstep' => 5,
            'sess_current_timespan_begin_now' => -3600, 'sess_current_timespan_end_now' => 0);
        $graphs_per_page = array(10 => 'Ten', 20 => 'Twenty');
        $graph_timespans = array(1 => 'Day <preset>');
        $graph_timeshifts = array(1 => 'Hour <shift>');
        $realtime_window = array(300 => '5 minutes');
        $realtime_refresh = array(5 => '5 seconds');
        $GLOBALS['queries'] = $GLOBALS['drawn'] = array();
        ob_start();
        if ($argv[2] === 'preview') {
            print '<table>';
            html_graph_preview_filter('graph_view.php', 'preview');
            print '</table>';
            $counts = array();
        } else {
            $counts = html_graph_custom_data(42, 2, 0, $argv[2] === 'cg' ? 'cg' : 'sg', $argv[2] === 'cg' ? 7 : 4,
                $argv[2] === 'cg' ? array() : array(5 => array('eth0', 'eth1')));
        }
        $html = ob_get_clean();
        if ($html === '' || ($argv[2] !== 'preview' && count($GLOBALS['drawn']) !== 5)) {
            throw new RuntimeException('Native graph rendering or data handoff did not complete.');
        }
        $GLOBALS['nativeChildCoverageMarkers'] = array('native-graph-template-rendered', 'native-graph-template-records-read');
        print json_encode(array('html' => $html, 'drawn' => $GLOBALS['drawn'], 'counts' => $counts, 'queries' => $GLOBALS['queries']), JSON_THROW_ON_ERROR);
        CHILD;
    $registration = \child_coverage_registration(
        __FILE__,
        'graph-template-' . $mode,
        array($mode),
        array('native-graph-template-rendered', 'native-graph-template-records-read'),
        array('lib/html_graph.php'),
        array('lib/html_graph.php', 'lib/html.php', 'lib/html_form.php', 'lib/html_validate.php', 'lib/html_utility.php', 'lib/path_helpers.php', 'include/global_constants.php')
    );
    $registration['collectorPrelude'] = 'define("GRAPH_TEMPLATE_RENDER_NATIVE_TEST_COVERAGE", true);';
    $command = \child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $mode), $directory, $registration);
    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch native graph template rendering.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        expect($stderr)->toBe('')->and($status)->toBe(0);
        \child_coverage_collect($directory);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($directory !== null && is_dir($directory)) {
            foreach (glob($directory . '/*.coverage*') ?: array() as $ownedReport) {
                unlink($ownedReport);
            }
            rmdir($directory);
            unset($GLOBALS['child_coverage_registrations'][$directory]);
        }
    }
}

function graphDocument(string $html): \DOMXPath
{
    $document = new \DOMDocument();
    $prior = libxml_use_internal_errors(true);
    try {
        expect($document->loadHTML($html, LIBXML_NONET))->toBeTrue();
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
    }
    return new \DOMXPath($document);
}

test('native graph preview retains selected escaped template and time choices', function () {
    $state = renderTemplates('preview');
    $xpath = graphDocument($state['html']);
    expect($xpath->evaluate('string(//select[@id="graph_template_id"]/option[@selected]/@value)'))->toBe('7')
        ->and($xpath->evaluate('string(//select[@id="graph_template_id"]/option[@value="7"])'))->toBe('Graph <template> &')
        ->and($xpath->query('//select[@id="graph_template_id"]/option[@value="8"]'))->toHaveCount(0)
        ->and($xpath->evaluate('string(//select[@id="graphs"]/option[@selected]/@value)'))->toBe('10')
        ->and($xpath->evaluate('string(//select[@id="predefined_timespan"]/option[@selected])'))->toBe('Day <preset>')
        ->and($xpath->evaluate('string(//select[@id="predefined_timeshift"]/option[@selected])'))->toBe('Hour <shift>')
        ->and($xpath->query('//template|//preset|//shift'))->toHaveCount(0)
        ->and($state['html'])->toContain('refreshMSeconds = 15000;');
});

test('native graph template handoff retains template records and excludes local instances', function (string $mode, string $label) {
    $state = renderTemplates($mode);
    $xpath = graphDocument($state['html']);
    expect($xpath->evaluate('string(//div[@class="cactiTableTitle"]/span)'))->toBe($label)
        ->and($state['counts'])->toBe(array(1, 1, 1, 1, 1))
        ->and($state['drawn']['graph'][1]['id'])->toBe(70)
        ->and($state['drawn']['data'][2]['id'])->toBe(300)
        ->and(array_column($state['drawn']['data_item'][1], 'id'))->toBe(array(30))
        ->and($state['drawn']['custom'][0])->toBe(300)
        ->and($state['drawn']['custom'][1])->toBe($mode === 'cg' ? 'c_0_7_3_|id|' : 'c_4_7_3_|id|');
})->with(array('custom graph' => array('cg', 'Create Graph from Graph <template> &'),
    'two SNMP graph instances' => array('sg', 'Create 2 Graphs from Query <template>')));
