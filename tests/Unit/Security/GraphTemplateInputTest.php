<?php

function runGraphInputProbe($program, array $arguments = array(), $coverage = null)
{
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/graph-input-' . bin2hex(random_bytes(8));
    mkdir($directory . '/include', 0700, true);
    file_put_contents($directory . '/include/auth.php', '<?php');
    symlink($root . '/lib', $directory . '/lib');
    if ($coverage !== null) {
        $program = 'define("GRAPH_INPUT_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($directory, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(array_merge(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root), $arguments), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stderr)->toBe('');
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
        return $stdout;
    } finally {
        unlink($directory . '/lib');
        unlink($directory . '/include/auth.php');
        rmdir($directory . '/include');
        foreach (glob($directory . '/*.coverage') as $report) {
            unlink($report);
        }
        rmdir($directory);
    }
}

test('graph input mutations require scalar actions and body CSRF tokens', function ($action, $method, $shape, $token, $status) {
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'graph-input-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/html_utility.php';
$config = array('url_path' => '/');
session_id('graph-input-test-session');
$_SESSION = array('sess_user_id' => 42);
function read_config_option($key) { return '0'; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function is_error_message() { return false; }
function get_hash_graph_template(...$args) { return 'hash'; }
function form_input_validate($value, ...$args) { return $value; }
function sql_save(...$args) { echo 'MUTATION'; exit; }
function db_execute_prepared(...$args) { echo 'MUTATION'; exit; }
$_SERVER['REQUEST_METHOD'] = $argv[3];
$_REQUEST = array('action' => $argv[4] === 'array' ? array($argv[2]) : $argv[2], 'save_component_input' => '1', 'id' => '1', 'graph_template_input_id' => '1', 'graph_template_id' => '2', 'column_name' => 'text_format', 'name' => 'test', 'description' => 'test');
if (isset($argv[6])) $_REQUEST['column_name'] = json_decode($argv[6], true);
$_POST = $argv[3] === 'POST' ? $_REQUEST : array();
$_GET = $argv[3] === 'GET' ? $_REQUEST : array();
if ($argv[5] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[5] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
if ($argv[5] === 'array') $_POST['__csrf_magic'] = array('forged');
if ($argv[5] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
require $argv[1] . '/graph_templates_inputs.php';
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    expect(runGraphInputProbe($program, array($action, $method, $shape, $token), $coverage))
        ->toBe(($status === 200 ? 'MUTATION' : '') . 'STATUS:' . $status);
    if ($action === 'save' && $status === 200) {
        foreach (array('text_format, unapproved_expression()', 'local_graph_id', array('text_format')) as $column) {
            expect(runGraphInputProbe($program, array($action, $method, $shape, $token, json_encode($column)), $coverage))->toBe('STATUS:400');
        }
    }
})->with(array('save', 'input_remove'))->with(array(
    array('GET', 'scalar', 'missing', 405),
    array('GET', 'scalar', 'valid', 405),
    array('HEAD', 'scalar', 'missing', 405),
    array('GET', 'array', 'missing', 400),
    array('POST', 'array', 'valid', 400),
    array('POST', 'scalar', 'missing', 403),
    array('POST', 'scalar', 'query', 403),
    array('POST', 'scalar', 'forged', 403),
    array('POST', 'scalar', 'array', 403),
    array('POST', 'scalar', 'valid', 200),
));

test('graph input SQL rejects untrusted stored columns before further queries', function ($column) {
    $program = <<<'PHP'
require $argv[1] . '/lib/template.php';
function db_fetch_row_prepared(...$args) { return array('column_name' => json_decode($GLOBALS['argv'][2], true), 'graph_template_id' => 2); }
function db_fetch_assoc_prepared(...$args) { throw new Exception('Untrusted identifier reached SQL'); }
if (push_out_graph_input(1, 2, array()) !== false) throw new Exception('Expected rejection');
echo 'REJECTED';
PHP;
    expect(runGraphInputProbe($program, array(json_encode($column)), $this->getTestResultObject()->getCodeCoverage()))->toBe('REJECTED');
})->with(array(
    'expression' => array('text_format, unapproved_expression()'),
    'assignment' => array('text_format = 1 WHERE 1=1 --'),
    'quoted identifier' => array('`text_format`'),
    'wrong field' => array('local_graph_id'),
    'case mismatch' => array('TEXT_FORMAT'),
    'empty' => array(''),
    'null' => array(null),
    'array' => array(array('text_format')),
));

test('graph input propagation uses approved identifiers and bound values', function ($members) {
    $program = <<<'PHP'
require $argv[1] . '/lib/template.php';
function db_fetch_row_prepared(...$args) { return array('column_name' => 'text_format', 'graph_template_id' => 2); }
function cacti_sizeof($value) { return count($value); }
function array_to_sql_or($values, $column) { return $column . ' IN (' . implode(',', array_map('intval', $values)) . ')'; }
function db_fetch_assoc_prepared($sql, $parameters) {
    if (strpos($sql, 'graph_template_input_defs') !== false) return array(array('graph_template_item_id' => 3));
    if (strpos($sql, 'SELECT local_graph_id,`text_format`') === false || $parameters !== array(2)) throw new Exception('Unsafe SELECT');
    return array(array('local_graph_id' => 4, 'text_format' => "a' OR 1=1 --"));
}
function db_execute_prepared($sql, $parameters) {
    if (strpos($sql, 'SET `text_format` = ?') === false || $parameters !== array("a' OR 1=1 --", 4, 5)) throw new Exception('Unsafe UPDATE');
    echo 'BOUND';
}
push_out_graph_input(1, 5, json_decode($argv[2], true));
PHP;
    expect(runGraphInputProbe($program, array(json_encode($members)), $this->getTestResultObject()->getCodeCoverage()))->toBe('BOUND');
})->with(array('no new members' => array(array()), 'new members' => array(array(6))));
