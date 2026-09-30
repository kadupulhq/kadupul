<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('tree bulk confirmation only looks up names of authorized trees', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/tree-confirm-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    symlink($root . '/lib', $dir . '/lib');
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-tree-confirm-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/global_constants.php';
function __($value) { return $value; }
function __x($context, $value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_authorize_resource($user, $resource, $type) { return $user === 42 && $resource === 7 && $type === 'graph_tree'; }
function db_fetch_cell_prepared($sql, $params = array()) { echo 'LOOKUP:' . implode(',', $params) . ';'; return 'tree'; }
function top_header() { echo 'CONFIRM'; exit; }
function get_current_page() { return 'tree.php'; }
function input_validate_input_number($value) {}
function html_escape($value) { return $value; }
session_id('tree-confirm-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = array('action' => 'actions', 'drp_action' => '1', 'chk_7' => 'on', 'chk_8' => 'on');
$_POST = $_REQUEST;
$_GET = array();
$_POST['__csrf_magic'] = csrf_get_tokens();
require $argv[1] . '/tree.php';
PHP;
    try {
        $process = proc_open(array(PHP_BINARY, '-r', $program, $root), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)
            ->and($stderr)->toBe('')
            ->and($stdout)->toBe('LOOKUP:7;CONFIRM');
    } finally {
        unlink($dir . '/lib');
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        rmdir($dir);
    }
});
