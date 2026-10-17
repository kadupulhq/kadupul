<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * templates_import.php reported "The Template Import Succeeded." for every
 * import that was not a preview, even when import_xml_data() returned false
 * after rolling the import back. A failed import must show the validation
 * error instead, and a successful one keeps the message and redirect.
 * form_save() is extracted and run in this namespace with the request, import
 * and message helpers stubbed.
 */

namespace TemplatesImportResultMessageTest;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

if (!function_exists(__NAMESPACE__ . '\form_save')) {
    $source = file_get_contents(dirname(__DIR__, 2) . '/templates_import.php');

    // test-only eval of source read from this repository, not external input
    eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, 'form_save')); // nosemgrep: php.lang.security.eval-use.eval-use
}

foreach (array('MESSAGE_LEVEL_INFO' => 1, 'MESSAGE_LEVEL_ERROR' => 3) as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

function isset_request_var($name)
{
    return isset($GLOBALS['tir_request'][$name]);
}

function get_request_var($name)
{
    return $GLOBALS['tir_request'][$name] ?? '';
}

function get_filter_request_var($name)
{
    return $GLOBALS['tir_request'][$name] ?? '';
}

function get_nfilter_request_var($name)
{
    return $GLOBALS['tir_request'][$name] ?? '';
}

function db_fetch_cell($sql)
{
    return 1;
}

function import_xml_data(&$xml_data, $import_as_new, $profile_id, $remove_orphans = false, $replace_svalues = false, $import_hashes = array())
{
    /* import_xml_data() reports an XML parse error through $import_messages */
    foreach ($GLOBALS['tir_import_messages'] as $message) {
        $GLOBALS['import_messages'][] = $message;
    }

    return $GLOBALS['tir_result'];
}

function raise_message($message_id, $message = '', $message_level = 0)
{
    $GLOBALS['tir_events'][] = 'message:' . $message_id;
}

function raise_message_javascript($title, $header, $message)
{
    $GLOBALS['tir_events'][] = 'javascript:' . $header;
}

function header($header)
{
    $GLOBALS['tir_events'][] = 'header:' . $header;
}

/* the preview page ends with exit, so the display stub stops form_save() before it gets there */
function prepare_template_display(&$import_info)
{
    return $import_info;
}

function display_template_data(&$templates)
{
    $GLOBALS['tir_events'][] = 'preview';

    throw new \RuntimeException('preview displayed');
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['tir_events'][] = 'log:' . $message;
}

function cacti_sizeof($array)
{
    return is_array($array) ? count($array) : 0;
}

function __($text, ...$args)
{
    if (cacti_sizeof($args) && $args[cacti_sizeof($args) - 1] === 'package') {
        array_pop($args);
    }

    return vsprintf($text, $args);
}

beforeEach(function () {
    $this->savedGlobals = array_intersect_key($GLOBALS, array_flip(array('preview_only', 'messages', 'import_messages')));
    $this->savedFiles   = $_FILES;
    $this->savedPost    = $_POST;

    $this->upload = tempnam(sys_get_temp_dir(), 'tpl-import-');
    file_put_contents($this->upload, '<cacti></cacti>');

    $_FILES = array('import_file' => array('tmp_name' => $this->upload, 'name' => 'Uptime_Probe.xml'));
    $_POST  = array();

    $GLOBALS['messages']            = array();
    $GLOBALS['tir_events']          = array();
    $GLOBALS['tir_import_messages'] = array();
    $GLOBALS['tir_request']         = array(
        'save_component_import'      => 'yes',
        'import_data_source_profile' => '1',
        'preview_only'               => 'false',
    );
});

afterEach(function () {
    @unlink($this->upload);

    $_FILES = $this->savedFiles;
    $_POST  = $this->savedPost;

    foreach (array('preview_only', 'messages', 'import_messages') as $name) {
        if (array_key_exists($name, $this->savedGlobals)) {
            $GLOBALS[$name] = $this->savedGlobals[$name];
        } else {
            unset($GLOBALS[$name]);
        }
    }
});

test('a successful template import keeps the success message and redirect', function () {
    $GLOBALS['tir_result'] = array('data_template' => array());

    form_save();

    expect($GLOBALS['tir_events'])->toBe(array('message:import_success', 'header:Location: templates_import.php'));
});

test('a failed template import shows the validation error instead of success', function () {
    $GLOBALS['tir_result'] = false;

    form_save();

    expect($GLOBALS['tir_events'])->toBe(array(
        'log:ERROR: Import or Preview failed for XML file Uptime_Probe.xml!',
        'javascript:The Template XML file "Uptime_Probe.xml" validation failed',
    ));
});

test('a successful preview shows the template data, not the success message', function () {
    $GLOBALS['tir_result']                  = array('data_template' => array());
    $GLOBALS['tir_request']['preview_only'] = 'true';

    expect(fn() => form_save())->toThrow(\RuntimeException::class, 'preview displayed');
    expect($GLOBALS['tir_events'])->toBe(array('preview'));
});

test('a failed preview still shows the validation error', function () {
    $GLOBALS['tir_result']                  = false;
    $GLOBALS['tir_request']['preview_only'] = 'true';

    form_save();

    expect($GLOBALS['tir_events'])->toContain('javascript:The Template XML file "Uptime_Probe.xml" validation failed')
        ->and($GLOBALS['tir_events'])->not->toContain('message:import_success');
});

test('a malformed template file shows the validation error instead of success', function () {
    $GLOBALS['tir_result']          = array();
    $GLOBALS['tir_import_messages'] = array(7);

    form_save();

    expect($GLOBALS['tir_events'])->not->toContain('message:import_success')
        ->and($GLOBALS['tir_events'])->not->toContain('header:Location: templates_import.php')
        ->and($GLOBALS['tir_events'])->toContain('javascript:The Template XML file "Uptime_Probe.xml" validation failed');
});

test('an import that leaves nothing to change still reports success', function () {
    $GLOBALS['tir_result'] = array();

    form_save();

    expect($GLOBALS['tir_events'])->toBe(array('message:import_success', 'header:Location: templates_import.php'));
});
