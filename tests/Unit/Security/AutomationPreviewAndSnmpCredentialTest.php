<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * GHSA-f7jw-gfhr-cxwm and GHSA-m57p-vf3v-cpgg: the tree-rule matching preview
 * printed device and data query values as HTML. GHSA-m3fh-gxqj-76hq: SNMP
 * notification receiver credentials kept control characters. Both come from
 * upstream Cacti #8152; the production functions run in child processes.
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function automation_preview_run(string $program): array
{
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, dirname(__DIR__, 3)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $err . $out)->and($err)->toBe('');

    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

function automation_preview_source(string $file, string $name): string
{
    return test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/' . $file), $name) . "\n";
}

test('the tree-rule matching preview shows device values as text', function () {
    $program = automation_preview_source('lib/api_automation.php', 'display_matching_trees')
        . automation_preview_source('lib/html_utility.php', 'form_selectable_cell')
        . automation_preview_source('lib/html_utility.php', 'form_selectable_ecell')
        . automation_preview_source('lib/functions.php', 'filter_value')
        . <<<'PHP'
require $argv[1] . '/include/global_constants.php';
$item_rows = array(10 => '10');
$device_actions = array();
$automation_tree_header_types = array(0 => 'Fixed String');
final class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function __esc($text, ...$args) { return html_escape(__($text, ...$args)); }
function cacti_log(...$args) {}
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function read_config_option($name) { return 30; }
function get_request_var($name) { return array('rows' => 30, 'page' => 1, 'filter' => '', 'sort_column' => 'description', 'sort_direction' => 'ASC', 'id' => 4)[$name] ?? ''; }
function set_request_var($name, $value) {}
function isempty_request_var($name) { return false; }
function validate_store_request_vars($filters, $prefix) {}
function automation_function_with_pid($name) { return $name; }
function automation_device_filter_controls(...$args) {}
function api_automation_column_exists($column, $tables) { return true; }
function cacti_validate_sort_column($column, $allowed, $default) { return $column; }
function build_matching_objects_filter(...$args) { return ''; }
function db_qstr($value) { return "'" . addslashes((string) $value) . "'"; }
function db_fetch_row_prepared(...$args) { return array('id' => 4, 'field' => 'h.description', 'search_pattern' => '.*', 'replace_pattern' => ''); }
function db_fetch_cell($sql) { return str_contains($sql, "leaf_type") ? TREE_ITEM_TYPE_HOST : 1; }
function db_fetch_assoc($sql) {
    return array(array('host_id' => 9, 'hostname' => 'core', 'description' => 'core', 'host_template_name' => 'Linux', 'disabled' => '', 'status' => 3, 'source' => '<img src=x onerror=alert(1)>'));
}
function automation_string_replace($search, $replace, $target) { return array('<b>first</b>', '"><script>second</script>'); }
function html_start_box(...$args) {}
function html_end_box(...$args) {}
function html_nav_bar(...$args) { return ''; }
function html_header_sort(...$args) {}
function html_create_list(...$args) {}
function form_hidden_box(...$args) {}
function form_alternate_row(...$args) { print '<tr>'; }
function form_end_row() { print '</tr>'; }
function get_colored_device_status($disabled, $status) { return 'Up'; }
function raise_message(...$args) {}
ob_start();
display_matching_trees(4, AUTOMATION_TREE_ITEM_TYPE_STRING, array('field' => 'h.description', 'search_pattern' => '.*', 'replace_pattern' => '', 'tree_item_id' => 1), 'automation_tree_rules.php');
$html = ob_get_clean();
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<table>' . $html . '</table>');
// The source and replacement are the last two cells of the matching row.
$cells = array_slice(iterator_to_array($dom->getElementsByTagName('td')), -2);
$elements = array('img' => 0, 'script' => 0, 'b' => 0);
$breaks = 0;
foreach ($cells as $cell) {
    foreach (array_keys($elements) as $tag) {
        $elements[$tag] += $cell->getElementsByTagName($tag)->length;
    }
    $breaks += $cell->getElementsByTagName('br')->length;
}
echo json_encode(array('cells' => array_map(static fn($cell) => $cell->textContent, $cells), 'elements' => $elements, 'breaks' => $breaks));
PHP;
    $state = automation_preview_run($program);

    expect($state['elements'])->toBe(array('img' => 0, 'script' => 0, 'b' => 0))
        ->and($state['breaks'])->toBe(1)
        ->and($state['cells'])->toBe(array('<img src=x onerror=alert(1)>', "<b>first</b>---\u{a0}\"><script>second</script>"));
});

test('notification receiver SNMP credentials are saved without control characters', function () {
    // The namespace lets the extracted page function call a recording header().
    $program = 'namespace Probe;' . automation_preview_source('managers.php', 'form_save')
        . <<<'PHP'
$request = array('id' => 3, 'tab' => 'general', 'description' => 'Receiver', 'hostname' => 'trap.example', 'disabled' => '', 'max_log_size' => 10,
    'snmp_version' => 3, 'snmp_community' => "pub\nlic", 'snmp_username' => "op\x01erator", 'snmp_password' => "pass\rword1", 'snmp_password_confirm' => "pass\rword1",
    'snmp_auth_protocol' => 'SHA', 'snmp_priv_passphrase' => "priv\x7fphrase", 'snmp_priv_protocol' => 'AES', 'snmp_engine_id' => "800\x1b0", 'snmp_port' => 162, 'snmp_message_type' => 1, 'notes' => '');
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function set_request_var($name, $value) { $GLOBALS['request'][$name] = $value; }
function get_filter_request_var($name) { return $GLOBALS['request'][$name]; }
function get_nfilter_request_var($name) { return $GLOBALS['request'][$name] ?? ''; }
function get_request_var($name) { return $GLOBALS['request'][$name] ?? ''; }
function get_request_var_post($name) { return $GLOBALS['request'][$name] ?? ''; }
function form_input_validate($value, ...$args) { return $value; }
function is_error_message() { return false; }
function raise_message(...$args) {}
function die_html_input_error(...$args) {}
function header($value) {}
function sql_save($save, $table) { $GLOBALS['saved'] = $save; return 3; }
form_save();
echo json_encode(array_intersect_key($GLOBALS['saved'], array_flip(array('snmp_community', 'snmp_username', 'snmp_password', 'snmp_priv_passphrase', 'snmp_engine_id'))));
PHP;
    $saved = automation_preview_run($program);

    expect($saved)->toBe(array('snmp_community' => 'public', 'snmp_username' => 'operator', 'snmp_password' => 'password1', 'snmp_priv_passphrase' => 'privphrase', 'snmp_engine_id' => '8000'));
});
