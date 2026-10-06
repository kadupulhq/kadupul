<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A report device item names a device. reports_generate_html() gated it with
 * is_tree_allowed() on the device id, so the item followed whatever tree had
 * that id rather than the owner's device permission. It now asks
 * is_device_allowed() for the report owner.
 *
 * reports_generate_html() is extracted into this namespace with the
 * permission checks and the device expansion stubbed.
 */

namespace ReportDeviceItemPermissionTest;

foreach (array('REPORTS_OUTPUT_STDOUT' => 1, 'REPORTS_OUTPUT_EMAIL' => 2, 'REPORTS_ITEM_GRAPH' => 1, 'REPORTS_ITEM_TEXT' => 2, 'REPORTS_ITEM_TREE' => 3, 'REPORTS_ITEM_HOST' => 5, 'POLLER_VERBOSITY_MEDIUM' => 3) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

if (!function_exists(__NAMESPACE__ . '\reports_generate_html')) {
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/reports.php');

    // test-only eval of source read from this repository, not external input
    eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, 'reports_generate_html'));
}

function db_fetch_row_prepared($sql, $params = array(), $log = true)
{
    return array('id' => 3, 'user_id' => 9, 'name' => 'Daily', 'cformat' => '', 'alignment' => 1, 'font_size' => 10, 'graph_columns' => 1);
}

function db_fetch_assoc_prepared($sql, $params = array(), $log = true)
{
    return array(array('id' => 1, 'item_type' => REPORTS_ITEM_HOST, 'host_id' => 4, 'local_graph_id' => 0));
}

function read_user_setting($name, $default = false, $force = false, $user = 0)
{
    return $default;
}

function reports_log(...$args) {}

function html_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}

function is_device_allowed($device_id, $user_id = 0)
{
    $GLOBALS['report_device']['device_checks'][] = array($device_id, $user_id);

    return $GLOBALS['report_device']['device_allowed'];
}

function is_tree_allowed($tree_id, $user_id = 0)
{
    $GLOBALS['report_device']['tree_checks'][] = array($tree_id, $user_id);

    return $GLOBALS['report_device']['tree_allowed'];
}

function reports_expand_device($report, $item, $host_id, $output, $format_ok, $theme)
{
    return '<DEVICE:' . $host_id . '>';
}

/**
 * @return array{html: string, device_checks: list<array{0: int, 1: int}>, tree_checks: list<array{0: int, 1: int}>}
 */
function report_device_run(bool $device_allowed, bool $tree_allowed): array
{
    global $config, $alignment;

    /* an empty lib/time.php keeps the real one out of the shared test process */
    $base = sys_get_temp_dir() . '/report-device-' . bin2hex(random_bytes(8));
    mkdir($base . '/lib', 0700, true);
    file_put_contents($base . '/lib/time.php', '<?php');

    $saved_globals = array($config, $alignment);

    $config    = array('base_path' => $base);
    $alignment = array(1 => 'left');

    $GLOBALS['report_device'] = array(
        'device_allowed' => $device_allowed,
        'tree_allowed'   => $tree_allowed,
        'device_checks'  => array(),
        'tree_checks'    => array(),
    );

    $saved = $_SESSION ?? null;
    $theme = '';

    try {
        $html = reports_generate_html(3, REPORTS_OUTPUT_EMAIL, $theme);
    } finally {
        $_SESSION = $saved;

        list($config, $alignment) = $saved_globals;

        unlink($base . '/lib/time.php');
        rmdir($base . '/lib');
        rmdir($base);
    }

    return array('html' => $html) + $GLOBALS['report_device'];
}

test('a device item follows the owner device permission, not a tree with the same id', function ($device_allowed, $tree_allowed) {
    $run = report_device_run($device_allowed, $tree_allowed);

    expect($run['device_checks'])->toBe(array(array(4, 9)))
        ->and($run['tree_checks'])->toBe(array());

    if ($device_allowed) {
        expect($run['html'])->toContain('<DEVICE:4>');
    } else {
        expect($run['html'])->not->toContain('<DEVICE:4>');
    }
})->with(array(
    'device allowed, tree 4 denied' => array(true, false),
    'device denied, tree 4 allowed' => array(false, true),
    'both allowed'                  => array(true, true),
    'both denied'                   => array(false, false),
));
