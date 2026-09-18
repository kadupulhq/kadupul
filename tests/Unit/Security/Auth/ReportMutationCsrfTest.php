<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root              = dirname(__DIR__, 4);
$reportsAdmin     = file_get_contents($root . '/reports_admin.php');
$reportsUser      = file_get_contents($root . '/reports_user.php');
$htmlReports      = file_get_contents($root . '/lib/html_reports.php');
$reportsGenerator = file_get_contents($root . '/lib/reports.php');

test('report mutation actions reject non-POST requests in both report controllers', function () use ($root) {
    $program = <<<'PHP'
namespace ReportControllerRuntime;

$controller = $argv[1];
$action     = $argv[2];
$source     = file_get_contents(getcwd() . '/' . $controller);

preg_match('/switch \(get_request_var\(\'action\'\)\) \{(?P<body>.*?)^}$/ms', $source, $match);
if (empty($match['body'])) {
    exit(2);
}

function get_request_var($name) { return $name === 'action' ? $GLOBALS['action']:'1'; }
function get_filter_request_var($name) { return get_request_var($name); }
function get_reports_page() { return 'reports_user.php'; }
function reports_require_post($action) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new \RuntimeException('POST_REQUIRED:' . $action);
    }
}
function reports_form_save() { echo 'HANDLER:save'; }
function reports_send($id) { echo 'HANDLER:send'; }
function reports_item_dnd() { echo 'HANDLER:ajax_dnd'; }
function reports_form_actions() { echo 'HANDLER:actions'; }
function reports_item_movedown() { echo 'HANDLER:item_movedown'; }
function reports_item_moveup() { echo 'HANDLER:item_moveup'; }
function reports_item_remove() { echo 'HANDLER:item_remove'; }
function reports_item_validate() {}
function reports_get_branch_select($tree_id) {}
function get_allowed_ajax_hosts() {}
function get_allowed_ajax_graphs() {}
function get_allowed_ajax_graph_templates() {}
function general_header() {}
function reports_item_edit() {}
function reports_edit() {}
function reports() {}
function bottom_footer() {}
function header($value) {}

$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['action'] = $action;

try {
    eval("namespace ReportControllerRuntime; switch (get_request_var('action')) {" . $match['body'] . '}');
    echo 'accepted';
} catch (\RuntimeException $e) {
    echo $e->getMessage();
}
PHP;

    foreach (array($root . '/reports_admin.php', $root . '/reports_user.php') as $controller) {
        foreach (array('save', 'send', 'ajax_dnd', 'actions', 'item_movedown', 'item_moveup', 'item_remove') as $action) {
            $process = proc_open(
                array(PHP_BINARY, '-r', $program, basename($controller), $action),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
                $pipes,
                $root
            );

            expect(is_resource($process))->toBeTrue();

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exit = proc_close($process);

            expect($exit)->toBe(0, $stderr)
                ->and($stdout)->toBe('POST_REQUIRED:' . $action);
        }
    }
});

test('report item controls post mutations with the csrf token', function () use ($htmlReports) {
    expect($htmlReports)->toContain('function reports_require_post($action)')
        ->and($htmlReports)->toContain('loadPageUsingPost(reportsPage')
        ->and($htmlReports)->toContain('__csrf_magic:csrfMagicToken')
        ->and($htmlReports)->not->toContain('?action=item_movedown&item_id=')
        ->and($htmlReports)->not->toContain('?action=item_moveup&item_id=')
        ->and($htmlReports)->not->toContain('?action=item_remove&item_id=');
});

test('csrf middleware rejects empty-body POST mutations without a token', function () use ($root) {
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('defer', true);
    csrf_conf('rewrite', false);
    csrf_conf('auto-session', false);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array();
require __DIR__ . '/../include/vendor/csrf/csrf-magic.php';
echo csrf_check(false) ? 'accepted' : 'rejected';
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root . '/tests'
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toBe('rejected');
});

test('report controllers redirect mutations through the realm-aware reports page', function () use ($reportsAdmin, $reportsUser) {
    expect($reportsUser)->not->toContain("header('Location: reports_admin.php?action=edit&tab=items&id='")
        ->and($reportsAdmin)->toContain("header('Location: ' . get_reports_page()")
        ->and($reportsUser)->toContain("header('Location: ' . get_reports_page()")
        ->and(substr_count($reportsAdmin, "&header=false'"))->toBe(5)
        ->and(substr_count($reportsUser, "&header=false'"))->toBe(5);
});

test('report data query labels are escaped before generated html output', function () use ($root) {
    $program = <<<'PHP'
namespace ReportTreeRuntime;

define(__NAMESPACE__ . '\HOST_GROUPING_DATA_QUERY_INDEX', 2);
define(__NAMESPACE__ . '\HOST_GROUPING_GRAPH_TEMPLATE', 1);
$tmp = \sys_get_temp_dir() . '/report-tree-' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents($tmp . '/global_arrays.php', '<?php');
file_put_contents($tmp . '/data_query.php', '<?php');
file_put_contents($tmp . '/html_tree.php', '<?php');
file_put_contents($tmp . '/html_utility.php', '<?php');

$GLOBALS['config'] = array('include_path' => $tmp, 'library_path' => $tmp);
$GLOBALS['alignment'] = array('left' => 'left');

function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function read_user_setting($name) { return 1; }
function get_timespan(&$timespan) { $timespan = array('begin_now' => 1, 'end_now' => 2); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_qstr_rlike($value) { return '= ' . var_export($value, true); }
function array_rekey($array) { return $array; }
function is_device_allowed($id, $user) { return true; }
function is_graph_allowed($id, $user) { return true; }
function is_graph_template_allowed($id, $user) { return true; }
function get_formatted_data_query_indexes($hostId, $queryId) { return array('idx1' => 'Index 1'); }
function reports_graph_area() { return '<tr><td>graph</td></tr>'; }
function necturally_sort_graphs($a, $b) { return 0; }
function db_fetch_cell_prepared($sql, $params = array()) {
    if (strpos($sql, 'SELECT host_id') !== false) { return 5; }
    if (strpos($sql, 'FROM graph_tree ') !== false) { return 'Tree'; }
    if (strpos($sql, 'SELECT title') !== false) { return 'Leaf'; }
    if (strpos($sql, 'h.description') !== false) { return 'Host'; }
    return 0;
}
function db_fetch_assoc_prepared($sql, $params = array()) {
    if (strpos($sql, 'FROM graph_tree_items') !== false) {
        return array(array('id' => 9, 'local_graph_id' => 0, 'host_id' => 5, 'host_grouping_type' => HOST_GROUPING_DATA_QUERY_INDEX));
    }

    if (strpos($sql, 'snmp_query AS sq') !== false) {
        return array(array('id' => 7, 'name' => '<script>alert(1)</script>'));
    }

    return array();
}
function db_fetch_assoc($sql) {
    if (strpos($sql, 'gl.snmp_query_id=7') !== false) {
        return array(array('title_cache' => 'Graph', 'local_graph_id' => 77, 'snmp_index' => 'idx1'));
    }

    return array();
}

$source = file_get_contents(getcwd() . '/lib/reports.php');
preg_match('/^function reports_expand_tree\(.*?^}\n\nfunction reports_data_query_label\(.*?^}\n/ms', $source, $match);
if (empty($match)) { exit(3); }

$runtimeSource = str_replace("'necturally_sort_graphs'", "__NAMESPACE__ . '\\\\necturally_sort_graphs'", $match[0]);
eval('namespace ReportTreeRuntime; ' . $runtimeSource);

$report = array('user_id' => 1);
$item = array(
    'tree_id' => 1,
    'branch_id' => 9,
    'timespan' => 1,
    'align' => 'left',
    'font_size' => 10,
    'graph_name_regexp' => '',
    'tree_cascade' => 'on',
);

$formatted = reports_expand_tree($report, $item, 9, 0, true);
$plain     = reports_expand_tree($report, $item, 9, 0, false);

array_map('\unlink', glob($tmp . '/*.php'));
rmdir($tmp);

echo json_encode(array($formatted, $plain));
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    $rendered = json_decode($stdout, true);

    expect($exit)->toBe(0, $stderr)
        ->and($rendered)->toBeArray()
        ->and($rendered[0])->toContain('Data Query: &lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($rendered[1])->toContain('Data Query: &lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($rendered[0])->not->toContain('<script>')
        ->and($rendered[1])->not->toContain('<script>');
});

test('report image conversion uses unpredictable temporary files with cleanup', function () use ($reportsGenerator, $root) {
    expect($reportsGenerator)->toContain("tempnam(sys_get_temp_dir(), 'cacti-report-')")
        ->and($reportsGenerator)->toContain('finally')
        ->and($reportsGenerator)->not->toContain("'/tmp/' . time() . '.png'");

    $program = <<<'PHP'
namespace ReportPngRuntime;
$GLOBALS['tmpdir'] = \sys_get_temp_dir() . '/report-png-' . bin2hex(random_bytes(4));
mkdir($GLOBALS['tmpdir']);
function sys_get_temp_dir() { return $GLOBALS['tmpdir']; }
function imagecreatefrompng($file) { return $GLOBALS['decode_ok'] ? 'image' : false; }
function ImageCreate($width, $height) { return 'fallback'; }
function ImageColorAllocate($image, $red, $green, $blue) { return 'color'; }
function ImageFilledRectangle($image, $x1, $y1, $x2, $y2, $color) {}
function ImageString($image, $font, $x, $y, $string, $color) {}
function imagejpeg($image) { echo 'jpeg'; }
function imagegif($image) { echo 'gif'; }
$source = file_get_contents(getcwd() . '/lib/reports.php');
preg_match('/^function png2jpeg .*?^}\n/ms', $source, $jpeg);
preg_match('/^function png2gif .*?^}\n/ms', $source, $gif);
if (empty($jpeg) || empty($gif)) { exit(2); }
eval('namespace ReportPngRuntime; ' . $jpeg[0] . $gif[0]);
$GLOBALS['decode_ok'] = true;
$jpegData = png2jpeg('png-data');
$GLOBALS['decode_ok'] = false;
$gifData = png2gif('bad-data');
$remaining = glob($GLOBALS['tmpdir'] . '/cacti-report-*');
rmdir($GLOBALS['tmpdir']);
echo json_encode(array($jpegData, $gifData, $remaining));
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    expect($exit)->toBe(0, $stderr)
        ->and(json_decode($stdout, true))->toBe(array('jpeg', 'gif', array()));
});
