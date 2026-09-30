<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[2])) {
    define('FORM_RENDERER_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/headers_secure.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
function __($message, ...$arguments)
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}
function __esc($message)
{
    return cacti_html_context_escape($message, CACTI_ESC_ELEMENT);
}
require $root . '/lib/html_form.php';
function db_fetch_cell_prepared($sql, $params)
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
function db_fetch_assoc($sql)
{
    return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$_SESSION = $scenario['session'] ?? array();
ob_start();
if (!empty($scenario['controls'])) {
    $db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $db->exec("CREATE TABLE colors(id INTEGER PRIMARY KEY, hex TEXT, name TEXT); INSERT INTO colors VALUES(5,'FFFFFF','White')");
    $config = array('is_web' => false, 'config_options_array' => array('hide_form_description' => 'off'));
    $fields = array();
    foreach (array('filepath', 'font', 'file', 'drop_color') as $method) {
        $fields[$method] = array('method' => $method, 'friendly_name' => ucfirst($method), 'value' => $method === 'drop_color' ? '5' : 'saved', 'default' => '', 'form_id' => 1, 'max_length' => 64, 'accept' => '.xml');
    }
    $fields['font']['sub_checkbox'] = array('name' => 'enabled', 'value' => 'on');
    draw_edit_form(array('config' => array('no_form_tag' => true), 'fields' => $fields));
} elseif (isset($scenario['input'])) {
    $function = $scenario['input'] === 'directory' ? 'form_dirpath_box' : 'form_font_box';
    $function('fixture', $scenario['value'] ?? '', 'default', 64, 30, 'text', $scenario['current_id'] ?? 0);
} else {
    if (empty($scenario['orphan'])) {
        form_start($scenario['action'], $scenario['id'], $scenario['multipart'] ?? false);
        print '<input name="fixture" value="original">';
    }
    form_end($scenario['ajax'] ?? true);
}
$html = ob_get_clean();
$result = array('html' => $html, 'session' => $_SESSION);
if (!empty($scenario['browser'])) {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML($html, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $scripts = $document->getElementsByTagName('script');
    if ($scripts->length !== 1) {
        throw new RuntimeException('Expected one generated form script');
    }
    $script = $scripts->item(0);
    $result['script'] = $script->textContent;
    $script->parentNode->removeChild($script);
    $result['markup'] = $document->saveHTML();
}
echo json_encode($result, JSON_THROW_ON_ERROR);
