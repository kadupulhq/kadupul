<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[, $root,$directory,$case] = $argv;
require_once $root . '/tests/Helpers/PresentationSettingsEvidence.php';
$cases = array('general-selected','general-empty','general-multi-scalar','path-valid','path-invalid','auth-password-retain','auth-password-change','auth-password-mismatch');
if (!in_array($case, $cases, true) || !is_dir($directory)) {
    throw new RuntimeException('Unknown native settings save scenario');
}
$scenarios = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $scenarios['pages']['settings-general'];
$bytes = json_encode($scenario, JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/scenario.json', $bytes) !== strlen($bytes)) {
    throw new RuntimeException('Cannot retain settings bootstrap');
}
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationObserver'] = static function (array $rendered) use ($root, $directory, $case): void {
    if (LegacyFormGoldenFiles::$transformedIncludes !== 0 || $rendered['diagnostics'] !== array()) {
        throw new RuntimeException('Settings bootstrap must execute original source cleanly');
    }
    $validateWorkers = static function () use ($root): void {
        $registered = array_flip(PresentationSettingsEvidence::sources());
        foreach (get_included_files() as $file) {
            if (str_starts_with($file, $root . '/')) {
                $relative = substr($file, strlen($root) + 1);
                if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($registered[$relative])) {
                    throw new RuntimeException('Unregistered settings worker: ' . $relative);
                }
            }
        }
    };
    $validateWorkers();
    $db = PresentationMutationEvidence::database($root, $directory);
    PresentationMutationEvidence::createCanonicalTables($db, $root, array_values(array_diff(PresentationSettingsEvidence::tables(), PresentationMutationEvidence::tables())));
    $db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn(?string $value = null): int => $value === null ? time() : (strtotime($value) ?: 0));
    $insert = static function (string $table, array $row) use ($db): void {
        $cols = array_keys($row);
        $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    };
    $insert('version', array('cacti' => '1.2.35'));
    $insert('settings', array('name' => 'unrelated_setting','value' => 'Retain exactly'));
    $insert('settings', array('name' => 'ldap_specific_password','value' => 'fixture-old-value'));
    foreach (array('cactiApplVersion','cactiApplPollerEnabled') as $name) {
        $insert('snmpagent_cache', array('oid' => $name,'name' => $name,'mib' => 'CACTI-MIB','value' => 'old')) ;
    }
    $before = PresentationSettingsEvidence::snapshot($db);
    $tab = str_starts_with($case, 'path-') ? 'path' : (str_starts_with($case, 'auth-') ? 'authentication' : 'general');
    $request = array('action' => 'save','tab' => $tab,'header' => 'false');
    $expected = array('unrelated_setting' => 'Retain exactly','ldap_specific_password' => 'fixture-old-value');
    if ($tab === 'general') {
        $request += array('log_pstats' => 'on', 'data_source_trace' => 'on','selective_debug' => array('poller.php','cmd.php'),'selective_device_debug' => '100,101','log_verbosity' => '3');
        if ($case === 'general-empty') {
            $request = array('action' => 'save','tab' => $tab,'header' => 'false');
        }
        if ($case === 'general-multi-scalar') {
            $request['selective_debug'] = 'poller.php';
        }
        $expected += array('log_pstats' => $case === 'general-empty' ? '' : 'on','log_pwarn' => '', 'data_source_trace' => $case === 'general-empty' ? '' : 'on','log_perror' => '','selective_debug' => $case === 'general-empty' ? '' : ($case === 'general-multi-scalar' ? 'poller.php' : 'poller.php,cmd.php'),'selective_plugin_debug' => '');
        if ($case !== 'general-empty') {
            $expected['selective_device_debug'] = '100,101';
            $expected['log_verbosity'] = '3';
        }
    } elseif ($tab === 'path') {
        foreach ($GLOBALS['settings']['path'] as $name => $field) {
            if ($field['method'] === 'filepath' || $field['method'] === 'dirpath') {
                $request[$name] = '';
            }
        }
        $request['path_php_binary'] = PHP_BINARY;
        $request['path_cactilog'] = $directory . '/owned.log';
        $request['path_stderrlog'] = $directory . '/owned-stderr.log';
        $request['rrd_archive'] = $directory;
        if ($case === 'path-invalid') {
            $request['path_php_binary'] = $directory . '/missing-binary';
            $request['path_cactilog'] = $directory . '/wrong.txt';
            $request['rrd_archive'] = $directory . '/missing-directory';
        }
        $expected['path_spine_config'] = '';
        if ($case === 'path-valid') {
            $expected['path_php_binary'] = PHP_BINARY;
            $expected['path_cactilog'] = $directory . '/owned.log';
            $expected['rrd_archive'] = $directory;
        }
    } else {
        $request['ldap_specific_password'] = $case === 'auth-password-retain' ? '' : 'fixture-new-value';
        $request['ldap_specific_password_confirm'] = $case === 'auth-password-mismatch' ? 'fixture-mismatch' : $request['ldap_specific_password'];
        if ($case === 'auth-password-change') {
            $expected['ldap_specific_password'] = 'fixture-new-value';
        }
    }
    $key = $GLOBALS['database_hostname'] . ':' . $GLOBALS['database_port'] . ':' . $GLOBALS['database_default'];
    $prior = $GLOBALS['database_sessions'][$key];
    $GLOBALS['database_sessions'][$key] = $db;
    $priorLocal = $GLOBALS['local_db_cnn_id'] ?? null;
    $GLOBALS['local_db_cnn_id'] = $db;
    try {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new RuntimeException('Cannot restore owned native settings session');
        }
        unset($_SESSION['sess_config_array'],$_SESSION['sess_error_fields'],$_SESSION['sess_field_values'],$_SESSION['sess_messages']);
        $_REQUEST = $request;
        $_POST = $request;
        $_GET = array();
        $GLOBALS['request'] = &$_REQUEST;
        $GLOBALS['_CACTI_REQUEST'] = array();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $settings = $GLOBALS['settings'];
        $config = $GLOBALS['config'];
        $disable_log_rotation = false;
        ob_start();
        include $root . '/settings.php';
        $html = ob_get_clean();
        $validateWorkers();
        $after = PresentationSettingsEvidence::snapshot($db);
        $stored = array_column($after['settings'], 'value', 'name');
        foreach ($expected as $name => $value) {
            if (!array_key_exists($name, $stored) || $stored[$name] !== $value) {
                throw new RuntimeException('Settings persisted value/omission mismatch: ' . $name);
            }
        }
        $errorCase = in_array($case, array('path-invalid','auth-password-mismatch'), true);
        if ($errorCase !== !empty($_SESSION['sess_error_fields'])) {
            throw new RuntimeException('Settings save validation outcome mismatch: ' . $case . ' fields=' . json_encode(array_keys($_SESSION['sess_error_fields'] ?? array())));
        }
        $messages = $_SESSION['sess_messages'] ?? array();
        if ($errorCase ? (!isset($messages[35]) || isset($messages[1])) : (!isset($messages[1]) || isset($messages[35]))) {
            throw new RuntimeException('Settings caller reported the wrong success/error outcome');
        }
        if ($case === 'path-invalid') {
            foreach (array('path_php_binary','path_cactilog','rrd_archive') as $name) {
                if (isset($stored[$name])) {
                    throw new RuntimeException('Invalid path was persisted');
                }
            }
        }
        if ($case === 'auth-password-mismatch' && ($_SESSION['sess_field_values']['ldap_specific_password'] ?? null) !== 'fixture-new-value') {
            throw new RuntimeException('Original password mismatch redisplay contract changed');
        }
        if ($after['poller'] !== array()) {
            throw new RuntimeException('Settings save unexpectedly allocated a remote collector');
        }
        foreach ($after['snmpagent_cache'] as $row) {
            $expectedValue = $row['name'] === 'cactiApplVersion' ? '1.2.35' : '1';
            if ($row['value'] !== $expectedValue) {
                throw new RuntimeException('Actual local SNMP settings handoff missing');
            }
        }
        if ($html !== '') {
            throw new RuntimeException('Settings save unexpectedly rendered a success page');
        }
        if (($GLOBALS['diagnostics'] ?? array()) !== array()) {
            throw new RuntimeException('Actual presentation caller emitted unexpected diagnostics: ' . implode('; ', $GLOBALS['diagnostics']));
        }
        $bytes = json_encode(array('case' => $case,'before' => $before,'after' => $after,'errors' => $_SESSION['sess_error_fields'] ?? array(),'messages' => $_SESSION['sess_messages'] ?? array()), JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/outcome.json', $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot retain settings outcome');
        }
        $GLOBALS['presentationSettingsMarkers'] = PresentationSettingsEvidence::markers($case);
    } finally {
        $GLOBALS['database_sessions'][$key] = $prior;
        $GLOBALS['local_db_cnn_id'] = $priorLocal;
    }
};

if (getenv('PRESENTATION_SETTINGS_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (array('settings.php', 'lib/database.php', 'lib/mib_cache.php') as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-settings-native.php', $case, PresentationSettingsEvidence::sources());
    $coverage->start('persisted presentation ' . $case);
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
            if (($GLOBALS['presentationSettingsMarkers'] ?? array()) !== PresentationSettingsEvidence::markers($case)) {
                throw new RuntimeException('Persisted mutation assertions incomplete');
            }
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $report = $directory . '/settings.coverage';
            $bytes = serialize($coverage);
            if (file_put_contents($report, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot retain persisted mutation coverage');
            }
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationSettingsEvidence::markers($case));
        });
    });
}
$argv = array(__FILE__, $root, $directory);
require $root . '/tests/Fixtures/legacy-form-golden.php';
