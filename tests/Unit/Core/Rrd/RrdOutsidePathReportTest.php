<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The upgrade's Directory Permission Checks step reports Data Sources whose
 * stored RRD path the poller will refuse. rrd_data_sources_outside_allowed_paths()
 * expands each stored path as get_data_source_path() does, checks it with the
 * shipped rrd_check_path(), reads the table in batches and tells a database
 * failure apart from an install with nothing to report. The installer section
 * renders that result with the shipped Installer section helpers.
 */

namespace Kadupul\Tests\RrdOutsidePathReport;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

foreach (array('DB_STATUS_ERROR' => 0, 'DB_STATUS_WARNING' => 1, 'DB_STATUS_SUCCESS' => 3) as $name => $value) {
    if (!\defined($name)) {
        \define($name, $value);
    }
}

function rrdtool_uses_proxy()
{
    return false;
}

function cacti_log($message, ...$arguments) {}

function __($text, ...$values)
{
    return $values ? vsprintf($text, $values) : $text;
}

function html_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function db_error()
{
    return $GLOBALS['database_last_error'];
}

/** Serves data_template_data rows the way the query asks for them. */
function db_fetch_assoc_prepared($sql, $params = array(), $log = true, $db_conn = false)
{
    $GLOBALS['report_queries'][] = $params;

    if ($GLOBALS['report_fail_at'] === count($GLOBALS['report_queries'])) {
        $GLOBALS['database_last_error'] = 'DB Row Failed!, Error 2006: MySQL server has gone away';

        return array();
    }

    expect($sql)->toContain('local_data_id > ?')
        ->and($sql)->toContain("data_source_path != ''")
        ->and($sql)->toContain('ORDER BY local_data_id')
        ->and($sql)->toContain('LIMIT 1000');

    $rows = array();
    foreach ($GLOBALS['report_rows'] as $id => $path) {
        if ($id > $params[0] && $path !== '' && count($rows) < 1000) {
            $rows[] = array('local_data_id' => (string) $id, 'data_source_path' => $path);
        }
    }

    return $rows;
}

$rrd = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
foreach (array('rrd_check_path', 'rrd_path_is_contained', 'rrd_resolve_missing_path', 'rrd_additional_roots', 'rrd_path_in_additional_root', 'rrd_data_sources_outside_allowed_paths') as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($rrd, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}

/* The shipped static section helpers and the new section, as one class. */
$installer = file_get_contents(dirname(__DIR__, 4) . '/lib/installer.php');
$class     = '';
foreach (array('section', 'sectionSubTitle', 'sectionSubTitleEnd', 'sectionNormal', 'sectionNote') as $method) {
    $class .= 'public static ' . \test_php_function_source($installer, $method) . "\n";
}
$section = \test_php_function_source($installer, 'rrdLocationSection');
$class  .= 'public ' . str_replace('include_once(__DIR__ . \'/rrd.php\');', '', $section) . "\n";
eval('namespace ' . __NAMESPACE__ . '; final class Installer { const MODE_UPGRADE = 3; ' . $class . ' }'); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
    $this->saved = $GLOBALS['config'] ?? null;
    $this->root  = realpath(sys_get_temp_dir()) . '/rrd-outside-report-' . bin2hex(random_bytes(8));
    mkdir($this->root . '/rra/5', 0700, true);
    mkdir($this->root . '/elsewhere', 0700);
    symlink($this->root . '/elsewhere', $this->root . '/rra/away');

    $GLOBALS['config']              = array('rra_path' => $this->root . '/rra');
    $GLOBALS['database_last_error'] = 'an error left from an earlier query';
    $GLOBALS['report_queries']      = array();
    $GLOBALS['report_fail_at']      = 0;
    $GLOBALS['report_rows']         = array(
        1 => '<path_rra>/5/1.rrd',
        2 => 'localhost_load_2.rrd',
        3 => $this->root . '/elsewhere/3.rrd',
        4 => '<path_rra>/away/4.rrd',
        5 => '',
        6 => $this->root . '/rra/5/6.rrd',
        7 => '/var/lib/legacy/<script>.rrd',
    );
});

afterEach(function () {
    $GLOBALS['config'] = $this->saved;
    unlink($this->root . '/rra/away');
    rmdir($this->root . '/rra/5');
    rmdir($this->root . '/rra');
    rmdir($this->root . '/elsewhere');
    rmdir($this->root);
});

test('Data Sources outside the RRA directory are counted and listed with their stored path', function () {
    expect(rrd_data_sources_outside_allowed_paths(25))->toBe(array(
        'total' => 3,
        'items' => array(
            array('local_data_id' => 3, 'path' => $this->root . '/elsewhere/3.rrd'),
            array('local_data_id' => 4, 'path' => '<path_rra>/away/4.rrd'),
            array('local_data_id' => 7, 'path' => '/var/lib/legacy/<script>.rrd'),
        ),
    ));
});

test('a directory listed in rrd_additional_paths is not reported', function () {
    $GLOBALS['config']['rrd_additional_paths'] = array($this->root . '/elsewhere');

    expect(rrd_data_sources_outside_allowed_paths(25))->toBe(array(
        'total' => 1,
        'items' => array(array('local_data_id' => 7, 'path' => '/var/lib/legacy/<script>.rrd')),
    ));
});

test('the list is capped but the total counts every Data Source', function () {
    expect(rrd_data_sources_outside_allowed_paths(1))->toBe(array(
        'total' => 3,
        'items' => array(array('local_data_id' => 3, 'path' => $this->root . '/elsewhere/3.rrd')),
    ));
});

test('the table is read in batches of 1000 by local_data_id', function () {
    $rows = array();
    for ($id = 1; $id <= 2500; $id++) {
        $rows[$id] = $id % 2 === 0 ? '<path_rra>/5/' . $id . '.rrd' : $this->root . '/elsewhere/' . $id . '.rrd';
    }
    $GLOBALS['report_rows'] = $rows;

    $result = rrd_data_sources_outside_allowed_paths(2);

    expect($result['total'])->toBe(1250)
        ->and($result['items'])->toHaveCount(2)
        ->and($GLOBALS['report_queries'])->toBe(array(array(0), array(1000), array(2000)));
});

test('a database failure is reported as unavailable, not as nothing to report', function (int $failing_query) {
    $rows = array();
    for ($id = 1; $id <= 1500; $id++) {
        $rows[$id] = '<path_rra>/5/' . $id . '.rrd';
    }
    $GLOBALS['report_rows']    = $rows;
    $GLOBALS['report_fail_at'] = $failing_query;

    expect(rrd_data_sources_outside_allowed_paths(25))->toBeFalse();
})->with(array('first batch' => array(1), 'later batch' => array(2)));

test('an install with nothing to report shows success', function () {
    $GLOBALS['report_rows'] = array(1 => '<path_rra>/5/1.rrd', 2 => '');
    $sections = array();

    $html = (new Installer())->rrdLocationSection($sections);

    expect($sections)->toBe(array('rrd_locations' => DB_STATUS_SUCCESS))
        ->and($html)->toContain('Every Data Source RRDfile path is inside an allowed directory');
});

test('the upgrade section warns, escapes each listed path and names the remedies', function () {
    $sections = array();

    $html = (new Installer())->rrdLocationSection($sections);

    expect($sections)->toBe(array('rrd_locations' => DB_STATUS_WARNING))
        ->and($html)->toContain('3 Data Sources have an RRDfile path outside the RRA directory')
        ->and($html)->toContain("rrd_additional_paths")
        ->and($html)->toContain('structure_rra_paths.php --proceed')
        // clearing the path names a new file, so that would orphan the moved history
        ->and($html)->not->toContain('clear the Data Source Path so the poller generates it')
        ->and($html)->toContain('7: /var/lib/legacy/&lt;script&gt;.rrd')
        ->and($html)->not->toContain('<script>')
        ->and($html)->toContain('docs/rrd-path-containment.md');
});

test('the upgrade section says how many were left out of the list', function () {
    $rows = array();
    for ($id = 1; $id <= 30; $id++) {
        $rows[$id] = $this->root . '/elsewhere/' . $id . '.rrd';
    }
    $GLOBALS['report_rows'] = $rows;
    $sections = array();

    $html = (new Installer())->rrdLocationSection($sections);

    expect($html)->toContain('25: ' . $this->root . '/elsewhere/25.rrd')
        ->and($html)->not->toContain('26: ')
        ->and($html)->toContain('and 5 more');
});

test('the upgrade section says when the paths could not be read', function () {
    $GLOBALS['report_fail_at'] = 1;
    $sections = array();

    $html = (new Installer())->rrdLocationSection($sections);

    expect($sections)->toBe(array('rrd_locations' => DB_STATUS_WARNING))
        ->and($html)->toContain('RRDfile locations were not checked');
});

test('the Directory Permission Checks step shows the section on upgrades only', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/installer.php');
    $step   = \test_php_function_source($source, 'processStepPermissionCheck');

    expect($step)->toContain("if (\$this->mode == Installer::MODE_UPGRADE) {\n            \$output .= \$this->rrdLocationSection(\$sections);");
});
