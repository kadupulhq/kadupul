<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\FormOutputEncoding;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/*
 * Form actions, confirmation inputs, inline script values and flash messages
 * print stored or posted data. Each case below runs the shipped function with
 * hostile data and parses what it printed. Upstream Cacti #8437, #8439, #8449,
 * #7252 and GHSA-9737-rqh3-h7fh (#8144).
 */

$root = dirname(__DIR__, 3);

require_once $root . '/tests/Helpers/PhpSource.php';

foreach (array('CACTI_ESC_ELEMENT' => 'element', 'CACTI_ESC_ATTR' => 'attr', 'CACTI_ESC_JS_STRING' => 'js_string', 'CACTI_ESC_URL' => 'url', 'CACTI_ESC_CSS' => 'css') as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

foreach (array('MESSAGE_LEVEL_INFO' => 1, 'MESSAGE_LEVEL_WARN' => 2, 'MESSAGE_LEVEL_ERROR' => 3) as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

function loadProduction(string $root, string $file, array $functions): void
{
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        throw new RuntimeException('Unreadable ' . $file);
    }
    foreach ($functions as $function) {
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, $function));
    }
}

loadProduction($root, 'lib/html.php', array('html_escape_charset', 'html_escape'));
loadProduction($root, 'lib/functions.php', array('cacti_html_context_escape'));
loadProduction($root, 'lib/html_form.php', array('draw_edit_form'));
loadProduction($root, 'managers.php', array('form_actions'));
loadProduction($root, 'data_queries.php', array('data_query_item_edit'));
loadProduction($root, 'lib/data_query.php', array('api_data_query_errors'));
loadProduction($root, 'lib/reports.php', array('reports_add_devices_locked'));

/** Mirrors the output escaping of the shipped __esc() wrapper; the translation layer is not loaded. */
function __($text, ...$args)
{
    return vsprintf($text, $args);
}

function __esc($text, ...$args)
{
    return htmlspecialchars(__($text, ...$args), ENT_QUOTES);
}

function __n($single, $plural, $count)
{
    return $count == 1 ? $single : $plural;
}

function cacti_sizeof($array)
{
    return is_array($array) ? count($array) : 0;
}

function get_current_page()
{
    return 'current.php';
}

function form_hidden_box($name, $value, $default, $return = false)
{
    print '<input type="hidden" name="' . html_escape($name) . '" value="' . html_escape($value) . '">';
}

function get_nfilter_request_var($name, $default = '')
{
    return $_REQUEST[$name] ?? $default;
}

function get_request_var($name, $default = '')
{
    return $_REQUEST[$name] ?? $default;
}

function get_filter_request_var($name)
{
    return $_REQUEST[$name] ?? '';
}

function isset_request_var($name)
{
    return isset($_REQUEST[$name]);
}

function isempty_request_var($name)
{
    return empty($_REQUEST[$name]);
}

function escape_page_action($actions, $selected)
{
    return html_escape($actions[$selected] ?? '');
}

function top_header() {}

function bottom_footer() {}

function form_start($action, $id = '') {}

function form_end() {}

function html_start_box() {}

function html_end_box() {}

function html_header() {}

function form_alternate_row() {}

function form_end_row() {}

function form_save_button() {}

function inject_form_variables($fields, $values, $request)
{
    return array();
}

function input_validate_input_number($value) {}

function db_fetch_cell_prepared($sql, $params = array())
{
    foreach ($GLOBALS['stubCells'] as $pattern => $value) {
        if (preg_match($pattern, $sql)) {
            return $value;
        }
    }

    return false;
}

function db_fetch_row_prepared($sql, $params = array())
{
    return preg_match('/FROM (\w+)/', $sql, $match) ? ($GLOBALS['stubRows'][$match[1]] ?? array()) : array();
}

function db_fetch_assoc_prepared($sql, $params = array())
{
    return $GLOBALS['stubAssoc'] ?? array();
}

function db_execute_prepared($sql, $params = array()) {}

function cacti_authorize_resource($user, $resource, $type)
{
    return true;
}

function raise_message($id, $message = '', $level = 0)
{
    $GLOBALS['raised'][$id] = $message;
}

// The nonce helper is a class in the shipped code; only its attribute output matters here.
eval('namespace ' . __NAMESPACE__ . '; class CactiSecureHeaders { public static function getNonceAttribute() { return ""; } }');

function render(callable $callback): string
{
    ob_start();
    try {
        $callback();
    } finally {
        $html = ob_get_clean();
    }

    return $html;
}

function parse(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

function attributeNames(\DOMElement $element): array
{
    $names = array();
    foreach ($element->attributes as $attribute) {
        $names[] = $attribute->name;
    }
    sort($names);

    return $names;
}

beforeEach(function () {
    $_REQUEST = $_POST = array();
    $_SESSION = array('sess_user_id' => '1');
    $GLOBALS['raised'] = $GLOBALS['stubRows'] = $GLOBALS['stubCells'] = $GLOBALS['stubAssoc'] = array();
    $GLOBALS['manager_actions'] = array(1 => 'Delete', 2 => 'Enable');
    $GLOBALS['manager_notification_actions'] = array(1 => 'Disable', 2 => 'Enable');
});

test('the edit form keeps a configured action, name and enctype inside their attributes', function () {
    $hostile = "x.php' onfocus='alert(1)";
    $html = render(fn() => draw_edit_form(array(
        'config' => array('post_to' => $hostile, 'form_name' => "n' onmouseover='alert(2)", 'enctype' => "e' onclick='alert(3)"),
        'fields' => array('a' => array('method' => 'hidden', 'value' => '1')),
    )));

    $form = parse($html)->query('//form')->item(0);

    expect(attributeNames($form))->toBe(array('action', 'autocomplete', 'class', 'enctype', 'method', 'name'))
        ->and($form->getAttribute('action'))->toBe($hostile)
        ->and($form->getAttribute('name'))->toBe("n' onmouseover='alert(2)")
        ->and($form->getAttribute('enctype'))->toBe("e' onclick='alert(3)");
});

test('the edit form keeps an escaped ampersand in its action and falls back to the current page', function () {
    $html = render(fn() => draw_edit_form(array(
        'config' => array('post_to' => 'p.php?a=1&amp;b=2'),
        'fields' => array('a' => array('method' => 'hidden', 'value' => '1')),
    )));
    expect(parse($html)->query('//form')->item(0)->getAttribute('action'))->toBe('p.php?a=1&b=2');

    $html = render(fn() => draw_edit_form(array('fields' => array('a' => array('method' => 'hidden', 'value' => '1')))));
    $form = parse($html)->query('//form')->item(0);
    expect($form->getAttribute('action'))->toBe('current.php')
        ->and($form->hasAttribute('name'))->toBeFalse();
});

test('the notification confirmation posts the selection back without leaving its attribute', function () {
    $mib = "IF-MIB'><img src=x onerror=alert(1)>";
    $name = "link'Down\" onfocus=\"alert(2)";
    $_POST = array('chk_' . $mib . '__' . $name => 'on', 'chk_plain__coldStart' => 'on');
    $_REQUEST = array('id' => "7' onfocus='alert(3)", 'drp_action' => '2');

    $xpath = parse(render(fn() => form_actions()));

    expect($xpath->query('//img'))->toHaveCount(0);

    $selected = $xpath->query('//input[@name="selected_items"]');
    expect($selected)->toHaveCount(1)
        ->and(attributeNames($selected->item(0)))->toBe(array('name', 'type', 'value'))
        ->and(unserialize($selected->item(0)->getAttribute('value')))->toBe(array($mib => array($name => 1), 'plain' => array('coldStart' => 1)));

    $id = $xpath->query('//input[@name="id"]');
    expect(attributeNames($id->item(0)))->toBe(array('name', 'type', 'value'))
        ->and($id->item(0)->getAttribute('value'))->toBe("7' onfocus='alert(3)");
});

test('the receiver confirmation posts the selected ids back unchanged', function () {
    $_POST = array('chk_12' => 'on', 'chk_30' => 'on');
    $_REQUEST = array('drp_action' => '1', 'action_receivers' => '1');
    $GLOBALS['stubCells'] = array('/snmpagent_managers/' => 'Receiver <b>one</b>');

    $xpath = parse(render(fn() => form_actions()));

    $selected = $xpath->query('//input[@name="selected_items"]');
    expect($selected)->toHaveCount(1)
        ->and(unserialize($selected->item(0)->getAttribute('value')))->toBe(array('12', '30'))
        ->and($xpath->query('//b'))->toHaveCount(0);
});

test('the data query item script prints the stored graph template id as a number', function () {
    $_REQUEST = array('id' => '5', 'snmp_query_id' => '2');
    $GLOBALS['stubRows'] = array(
        'snmp_query_graph' => array('id' => 5, 'graph_template_id' => '3;window.pwned=1'),
        'snmp_query' => array('name' => 'Interfaces', 'xml_path' => 'x.xml'),
    );

    $html = render(fn() => data_query_item_edit());

    expect($html)->toContain('var graph_template_id_prev=3;')
        ->and($html)->not->toContain('pwned');
});

test('the data query mapping error names the data source as text', function () {
    $GLOBALS['stubCells'] = array('/SELECT gt\.id/' => 3);
    $GLOBALS['stubAssoc'] = array(array('id' => 1, 'data_source_name' => '<img src=x onerror=alert(1)>', 'data_template_id' => 9));

    expect(api_data_query_errors(4, array()))->toBeTrue();

    $message = $GLOBALS['raised']['mapping_error'];
    expect($message)->not->toContain('<img')
        ->and(parse('<p>' . $message . '</p>')->query('//img'))->toHaveCount(0)
        ->and(html_entity_decode($message, ENT_QUOTES))->toContain('<img src=x onerror=alert(1)>');
});

test('the report device messages name the device as text', function (int $existing, string $key) {
    $GLOBALS['stubCells'] = array(
        '/SELECT MAX/' => 0,
        '/FROM reports_items/' => $existing,
        '/SELECT id\s+FROM host/' => 10,
        '/SELECT description/' => '<img src=x onerror=alert(1)>',
        '/SELECT host_template_id/' => 1,
        '/SELECT site_id/' => 0,
    );

    reports_add_devices_locked(1, array(10), 0, 0);

    $message = $GLOBALS['raised'][$key];
    expect($message)->not->toContain('<img')
        ->and(parse('<p>' . $message . '</p>')->query('//img'))->toHaveCount(0)
        ->and(html_entity_decode($message, ENT_QUOTES))->toContain('<img src=x onerror=alert(1)>');
})->with(array(
    'added' => array(0, 'reports_add_device_10'),
    'already present' => array(5, 'reports_no_add_device_10'),
));

/*
 * Flash messages are rendered as HTML. A translated message that takes
 * variables must go through __esc(), or the variables through html_escape().
 * Poller ids come from an integer column and are the only exception.
 */
test('flash messages do not format variables into markup', function () {
    $root = dirname(__DIR__, 3);
    $found = array();

    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        if ($file->getExtension() !== 'php' || preg_match('#^(include/vendor|vendor|tests|locales|node_modules|plugins)/#', $path)) {
            continue;
        }
        foreach (file($file->getPathname()) as $number => $line) {
            if (!preg_match('/raise_message\(.*?,\s*__\(\s*(["\'])(?:\\\\.|(?!\1).)*\1\s*,(?<args>.*)\)\s*,\s*MESSAGE_LEVEL/', $line, $match)) {
                continue;
            }
            $args = preg_replace('/html_escape\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', '', $match['args']);
            $args = preg_replace('/\$(p|poller_id|previous_poller)\b/', '', $args);
            if (str_contains($args, '$')) {
                $found[] = $path . ':' . ($number + 1);
            }
        }
    }

    expect($found)->toBe(array());
});
