<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[2])) {
    define('COLOR_DROPDOWN_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/headers_secure.php';
require $root . '/lib/html_form.php';
function __($message, ...$arguments)
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}
function __esc($message, ...$arguments)
{
    return html_escape(__($message, ...$arguments));
}
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE colors(id TEXT, hex TEXT, name TEXT)');
$insert = $db->prepare('INSERT INTO colors VALUES(?,?,?)');
foreach ($scenario['colors'] as $color) {
    $insert->execute($color);
}
function db_fetch_cell_prepared($sql, $params)
{
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}
function db_fetch_assoc($sql)
{
    return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
$_SESSION = array();
$config = array('is_web' => false, 'config_options_array' => array('hide_form_description' => 'off'));
ob_start();
if (!empty($scenario['handoff'])) {
    draw_edit_form(array('config' => array('no_form_tag' => true), 'fields' => array($scenario['name'] => array('method' => 'drop_color', 'friendly_name' => 'Colour', 'value' => $scenario['previous'], 'default' => $scenario['default'], 'class' => $scenario['class'], 'on_change' => 'setColour()'))));
} else {
    form_color_dropdown($scenario['name'], $scenario['previous'], $scenario['none'], $scenario['default'], $scenario['class'], 'setColour()');
}
echo json_encode(array('html' => ob_get_clean(), 'session' => $_SESSION), JSON_THROW_ON_ERROR);
