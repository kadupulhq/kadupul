<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/ClogProductionFunctions.php';

function clogPurgeProgram(): string
{
    $program = <<<'CODE'
$_SERVER = $input['server'];
$_REQUEST = $input['request'];
$_SESSION = array('sess_user_id' => 1);
$config = array('base_path' => $input['dir']);
$messages = array();
$logs = array();
function read_config_option($name) { return $name == 'path_cactilog' ? $GLOBALS['input']['dir'] . '/cacti.log' : ''; }
function get_nfilter_request_var($name) { return $_REQUEST[$name] ?? ''; }
function raise_message($id) { $GLOBALS['messages'][] = $id; }
function cacti_log($message) { $GLOBALS['logs'][] = $message; }
function get_username($id) { return 'admin'; }
function get_current_page() { return 'clog.php'; }
function __($text, ...$args) { return vsprintf($text, $args); }
CODE;

    return $program . clogProductionFunction('lib/clog_webapi.php', 'clog_validate_filename')
        . clogProductionFunction('lib/clog_webapi.php', 'clog_purge_logfile')
        . 'clog_purge_logfile(); clearstatcache();'
        . 'echo json_encode(array("messages" => $messages, "logs" => $logs, "current" => file_get_contents($input["dir"] . "/cacti.log"), "rotated" => file_exists($input["dir"] . "/cacti.log-20260101")));';
}

function clogPurgeFixture(): string
{
    $dir = sys_get_temp_dir() . '/clog-purge-' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/cacti.log', "2026-01-01 00:00:00 - SYSTEM STATS: keep me\n");
    file_put_contents($dir . '/cacti.log-20260101', "rotated\n");

    return $dir;
}

function clogPurgeCleanup(string $dir): void
{
    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
}

test('a GET purge request neither clears the current log nor deletes a rotated log', function () {
    $dir = clogPurgeFixture();

    try {
        foreach (array('cacti.log', 'cacti.log-20260101') as $filename) {
            $out = clogRunProduction(clogPurgeProgram(), array(
                'dir'     => $dir,
                'server'  => array('REQUEST_METHOD' => 'GET'),
                'request' => array('purge_continue' => '1', 'filename' => $filename),
            ));

            expect($out['current'])->toContain('keep me')
                ->and($out['rotated'])->toBeTrue()
                ->and($out['messages'])->toBe(array())
                ->and($out['logs'][0])->toContain('Rejected non-POST');
        }
    } finally {
        clogPurgeCleanup($dir);
    }
});

test('a POST purge request still clears the current log and deletes a rotated log', function () {
    $dir = clogPurgeFixture();

    try {
        $out = clogRunProduction(clogPurgeProgram(), array(
            'dir'     => $dir,
            'server'  => array('REQUEST_METHOD' => 'POST'),
            'request' => array('purge_continue' => '1', 'filename' => 'cacti.log-20260101'),
        ));

        expect($out['rotated'])->toBeFalse()
            ->and($out['messages'])->toBe(array('clog_remove'));

        $out = clogRunProduction(clogPurgeProgram(), array(
            'dir'     => $dir,
            'server'  => array('REQUEST_METHOD' => 'POST'),
            'request' => array('purge_continue' => '1', 'filename' => 'cacti.log'),
        ));

        expect($out['current'])->not->toContain('keep me')
            ->and($out['current'])->toContain('Log Cleared')
            ->and($out['messages'])->toBe(array('clog_purged'));
    } finally {
        clogPurgeCleanup($dir);
    }
});

test('purge confirmation buttons submit by POST with the CSRF token', function () {
    $clog = clogProductionFunction('lib/clog_webapi.php', 'clog_view_logfile');
    $utilities = clogProductionFunction('utilities.php', 'utilities_view_logfile');

    expect($clog)->not->toContain('?purge_continue=1')
        ->and($clog)->toContain('loadPageUsingPost(location.pathname')
        ->and($clog)->toContain('__csrf_magic: csrfMagicToken')
        ->and($utilities)->not->toContain('?action=purge_logfile')
        ->and($utilities)->toContain("action: 'purge_logfile'")
        ->and($utilities)->toContain('__csrf_magic: csrfMagicToken');
});
