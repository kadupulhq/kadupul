<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace LineWidthEditorProbe;

require dirname(__DIR__) . '/Helpers/PhpSource.php';
$root = dirname(__DIR__, 2);
require $root . '/include/global_constants.php';
$request = ['save_component_item' => 1, 'sequence' => 1, 'graph_type_id' => $argv[2],
    'graph_template_item_id' => $argv[4] === 'existing' ? 7 : 0, 'graph_template_id' => 1,
    'local_graph_id' => 2, 'local_graph_template_item_id' => 0, 'task_item_id' => 0,
    '_task_item_id' => 0, 'line_width' => $argv[3], 'alpha' => 255,
    'color_id' => 0, 'cdef_id' => 0, 'vdef_id' => 0, 'consolidation_function_id' => 1,
    'gprint_id' => 0, 'textalign' => '', 'text_format' => '', 'value' => '', 'hard_return' => ''];
$graph_item_types = [4 => 'LINE1', 5 => 'LINE2', 6 => 'LINE3', 20 => 'LINE:STACK'];
$_SESSION = [];
$messages = [];
$saves = [];
$db = new \PDO('sqlite::memory:');
// The production graph_templates_item.line_width column is DECIMAL(4,2) DEFAULT 0.
$db->exec('CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, graph_type_id INTEGER, line_width DECIMAL(4,2) DEFAULT 0)');
$db->exec('INSERT INTO graph_templates_item VALUES (7, 20, 2.50)');
function get_nfilter_request_var($name) { global $request; return $request[$name] ?? ''; }
function get_filter_request_var($name) { return get_nfilter_request_var($name); }
function get_request_var($name) { return get_nfilter_request_var($name); }
function isset_request_var($name) { global $request; return isset($request[$name]); }
function set_request_var($name, $value) { global $request; $request[$name] = $value; }
function read_config_option($name) { return ''; }
function raise_message($message) { global $messages; $messages[] = $message; }
function is_error_message() { return !empty($_SESSION['sess_error_fields']); }
function get_hash_graph_template(...$arguments) { return 'existing-template-hash'; }
function db_fetch_cell_prepared(...$arguments) { return 0; }
function db_fetch_assoc_prepared(...$arguments) { return []; }
function array_rekey(...$arguments) { return []; }
function push_out_graph_item(...$arguments) {}
function header($value) { $GLOBALS['redirect'] = $value; }
function sql_save($save, $table) {
    global $db, $saves;
    $saves[] = $save;
    // Persistence adapter applies precisely the fields passed to sql_save().
    $fields = array_intersect_key($save, array_flip(['graph_type_id', 'line_width']));
    if ($save['id']) {
        $query = 'UPDATE graph_templates_item SET ' . implode(', ', array_map(static fn($key) => $key.' = ?', array_keys($fields))) . ' WHERE id = ?';
        $db->prepare($query)->execute(array_merge(array_values($fields), [$save['id']]));
        return $save['id'];
    }
    $db->prepare('INSERT INTO graph_templates_item ('.implode(',', array_keys($fields)).') VALUES ('.implode(',', array_fill(0, count($fields), '?')).')')->execute(array_values($fields));
    return (int) $db->lastInsertId();
}
register_shutdown_function(static function () use ($db, &$saves, &$messages) {
    echo json_encode(['saves' => $saves, 'messages' => $messages,
        'errors' => $_SESSION['sess_error_fields'] ?? [],
        'rows' => $db->query('SELECT * FROM graph_templates_item ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        'redirect' => $GLOBALS['redirect'] ?? null], JSON_THROW_ON_ERROR);
});
foreach ([[$root.'/lib/functions.php', 'form_input_validate'], [$root.'/'.$argv[1], 'form_save']] as [$path, $name]) {
    $source = file_get_contents($path);
    if ($source === false) { throw new \RuntimeException('Unable to read production source'); }
    eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($source, $name));
}
form_save();
