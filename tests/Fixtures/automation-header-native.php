<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Production matching-tree renderer and SQL execute on an isolated SQLite
// schema. Authentication and unrelated form/session/message adapters remain
// explicit fixture boundaries; no installed web admission is claimed.
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/api_automation.php';
// These form choices contain no host placeholders; substitution is outside
// this fixture's header-label contract and refuses unexpected placeholders.
function null_out_substitutions($value) {
    if (str_contains($value, '|')) { throw new RuntimeException('Unexpected choice substitution'); }
    return $value;
}
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE automation_tree_rule_items (id INTEGER PRIMARY KEY,rule_id INTEGER,sequence INTEGER,field VARCHAR(255),search_pattern VARCHAR(255),replace_pattern VARCHAR(255));
    CREATE TABLE automation_tree_rules (id INTEGER PRIMARY KEY,leaf_type INTEGER);
    CREATE TABLE automation_match_rule_items (rule_id INTEGER,rule_type INTEGER,sequence INTEGER);
    CREATE TABLE host (id INTEGER,hostname TEXT,description TEXT,disabled TEXT,status INTEGER,host_template_id INTEGER,deleted TEXT);
    CREATE TABLE host_template (id INTEGER,name TEXT);');
$query = $db->prepare('INSERT INTO automation_tree_rule_items VALUES (1,1,1,?, ?, ?)');
$query->execute(array($a['label'], '', ''));
$query = $db->prepare('INSERT INTO automation_tree_rules VALUES (1,?)');
$query->execute(array(TREE_ITEM_TYPE_HOST));
function cacti_log(...$arguments) {}
function raise_message(...$arguments) { $GLOBALS['automation_messages'][] = $arguments[0]; }
function form_hidden_box($name, $value, $default) { print '<input type="hidden" name="' . html_escape($name) . '" value="' . html_escape($value) . '">'; }
function validate_store_request_vars($filters, $prefix) {
    foreach ($filters as $name => $definition) {
        $GLOBALS['a']['request'][$name] ??= $definition['default'];
    }
}
function set_request_var($name, $value) { $GLOBALS['a']['request'][$name] = $value; }
function db_fetch_assoc($sql, ...$arguments) { return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function db_fetch_assoc_prepared($sql, $parameters) {
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($parameters);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell($sql) { return $GLOBALS['db']->query($sql)->fetchColumn(); }
function db_column_exists($table, $column) {
    if (!in_array($table, array('host', 'host_template', 'graph_local', 'graph_templates_graph', 'graph_templates'), true)) {
        throw new RuntimeException('Unexpected production column catalog');
    }
    return in_array($column, array_column(db_fetch_assoc('PRAGMA table_info(' . $table . ')'), 'name'), true);
}
function cacti_validate_sort_column($column, $columns, $default) { return in_array($column, $columns, true) ? $column : $default; }
$item_rows = array(10 => '10');
$GLOBALS['a']['request']['rows'] = 10;
$item = $db->query('SELECT * FROM automation_tree_rule_items WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if ($item === false || $item['field'] !== $a['label']) {
    throw new RuntimeException('Stored automation field was not read faithfully');
}
display_matching_trees(1, AUTOMATION_RULE_TYPE_TREE_ACTION, $item, 'automation_tree_rules.php?action=item_edit&id=1&item_id=1&rule_type=' . AUTOMATION_RULE_TYPE_TREE_ACTION);
$GLOBALS['nativeChildCoverageMarkers'][] = 'automation-stored-label-rendered';
