<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\UserSettingPersistenceNative;

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/** Execute the shipped preference functions against persisted SQLite rows. */
function runPreferences(string $mode): array
{
    $root = dirname(__DIR__, 4);
    $program = <<<'CHILD'
        require $argv[1] . '/include/global_constants.php';
        require $argv[1] . '/lib/functions.php';
        require $argv[1] . '/lib/html_utility.php';
        $db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $db->sqliteCreateFunction('REGEXP', static fn($pattern, $value) => preg_match('/' . $pattern . '/', (string) $value));
        $db->exec("CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (user_id, name));
            CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);
            CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, name TEXT);
            INSERT INTO graph_tree VALUES (4, 'Main');
            INSERT INTO settings_user VALUES (42, 'auth_credential_generation', 'original'), (43, 'page_refresh', '15');");
        function query($sql, $params = array()) {
            $sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
            $sql = str_replace('ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', 'ON CONFLICT(user_id, name) DO UPDATE SET value = excluded.value', $sql);
            $statement = $GLOBALS['db']->prepare($sql);
            $statement->execute($params);
            $GLOBALS['affected'] = $statement->rowCount();
            return $statement;
        }
        function db_execute_prepared($sql, $params) { query($sql, $params); return true; }
        function db_fetch_assoc($sql) { return query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        function db_table_exists($table) { return (bool) query('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', array('table', $table))->fetchColumn(); }
        function db_affected_rows() { return $GLOBALS['affected']; }
        $_SESSION = array('sess_user_id' => 42, 'sess_user_config_array' => array('stale'));
        $_CACTI_REQUEST = array();
        $config = array('is_web' => false, 'config_options_array' => array('log_validation' => ''));
        // These field definitions retain the shipped methods/defaults/limits in
        // include/global_settings.php; unrelated presentation labels are omitted.
        $fields = array('page_refresh' => array('method' => 'drop_array', 'default' => '300', 'array' => array('15' => '15s', '60' => '1m', '300' => '5m')),
            'day_shift_start' => array('method' => 'textbox', 'default' => '07:00', 'max_length' => 5),
            'default_tree_id' => array('method' => 'drop_sql', 'sql' => 'SELECT id, name FROM graph_tree ORDER BY name', 'default' => '0'),
            'title_font' => array('method' => 'font', 'max_length' => 100),
            'min_tree_width' => array('method' => 'textbox', 'default' => '170', 'max_length' => 5));
        if ($argv[2] === 'save') {
            $settings_user = array('general' => $fields + array('hide_disabled' => array('method' => 'checkbox'),
                'thumbnail_sections' => array('method' => 'checkbox_group', 'items' => array('thumbnail_section_preview' => array('default' => 'on'), 'thumbnail_section_tree_2' => array('default' => ''))),
                'nested' => array('method' => 'group', 'items' => array('nested_valid' => array('method' => 'textbox'), 'nested_invalid' => array('method' => 'textbox')))));
            $_REQUEST = array('page_refresh' => '60', 'day_shift_start' => '<b>', 'default_tree_id' => '4', 'title_font' => 'Arial',
                'min_tree_width' => '250', 'hide_disabled' => 'on', 'thumbnail_section_tree_2' => 'on', 'nested_valid' => 'safe', 'nested_invalid' => '<bad>');
            save_user_settings();
            set_user_setting('auth_credential_generation', 'forged');
            clear_user_setting('auth_credential_generation');
            set_user_setting('temporary', 'value');
            clear_user_setting('temporary');
            $result = array('rows' => $db->query('SELECT user_id, name, value FROM settings_user ORDER BY user_id, name')->fetchAll(PDO::FETCH_ASSOC),
                'errors' => array_keys($_SESSION['sess_error_fields']), 'cached' => isset($_SESSION['sess_user_config_array']));
            if (query('SELECT value FROM settings_user WHERE name = ?', array('auth_credential_generation'))->fetchColumn() !== 'original') {
                throw new RuntimeException('Preference mutation changed authentication metadata.');
            }
            $GLOBALS['nativeChildCoverageMarkers'] = array('native-preference-persistence-readback', 'credential-generation-retained');
        } elseif ($argv[2] === 'validate') {
            $cases = array(
                array($fields['page_refresh'], array('60')), array($fields['page_refresh'], '300'), array($fields['page_refresh'], '60'), array($fields['page_refresh'], '1'),
                array(array('method' => 'checkbox'), 'on'), array(array('method' => 'checkbox'), ''), array(array('method' => 'checkbox'), 'yes'),
                array(array('method' => 'drop_language', 'array' => array('de-DE' => 'Deutsch')), 'de-DE'),
                array($fields['default_tree_id'], '4'), array($fields['default_tree_id'], '99'),
                array($fields['day_shift_start'], '08:30'), array($fields['day_shift_start'], '07:00:00'),
                array($fields['min_tree_width'], '250'), array($fields['min_tree_width'], 'bad'),
                array($fields['title_font'], 'Arial'), array($fields['title_font'], '<bad>'), array(array('method' => 'unknown'), 'value'));
            $result = array_map(static fn($case) => user_setting_value_allowed($case[0], $case[1]), $cases);
            $GLOBALS['nativeChildCoverageMarkers'] = array('native-preference-validation-completed');
        } else {
            $first = debounce_claim_notification('page_error_user_42', 300);
            $repeated = debounce_claim_notification('page_error_user_42', 300);
            query('UPDATE settings SET value = ? WHERE name = ?', array(time() - 301, 'debounce_page_error_user_42'));
            $expired = debounce_claim_notification('page_error_user_42', 300);
            query('UPDATE settings SET value = ? WHERE name = ?', array('invalid', 'debounce_page_error_user_42'));
            $malformed = debounce_claim_notification('page_error_user_42', 300);
            $result = array('claims' => array($first, $repeated, $expired, $malformed),
                'rows' => $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_ASSOC), 'now' => time());
            $GLOBALS['nativeChildCoverageMarkers'] = array('native-debounce-persistence-readback');
        }
        print json_encode($result, JSON_THROW_ON_ERROR);
        CHILD;
    $markers = match ($mode) {
        'save' => array('native-preference-persistence-readback', 'credential-generation-retained'),
        'validate' => array('native-preference-validation-completed'),
        default => array('native-debounce-persistence-readback'),
    };
    $registration = \child_coverage_registration(
        __FILE__,
        'preferences-' . $mode,
        array($mode),
        $markers,
        array('lib/functions.php'),
        array('lib/path_helpers.php', 'lib/html_utility.php', 'include/global_constants.php', 'include/global_settings.php')
    );
    $command = \child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $mode), $directory, $registration);
    $pipes = array();
    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch native user preference persistence.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        \child_coverage_collect($directory);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($directory !== null && is_dir($directory)) {
            foreach (glob($directory . '/*.coverage*') ?: array() as $ownedReport) {
                unlink($ownedReport);
            }
            rmdir($directory);
            unset($GLOBALS['child_coverage_registrations'][$directory]);
        }
    }
}

test('native preference saving preserves validation and authentication metadata', function () {
    $state = runPreferences('save');
    $expected = array('auth_credential_generation' => 'original', 'default_tree_id' => '4', 'hide_disabled' => 'on', 'min_tree_width' => '250',
        'nested_valid' => 'safe', 'page_refresh' => '60', 'thumbnail_section_preview' => '', 'thumbnail_section_tree_2' => 'on', 'title_font' => 'Arial');
    expect(array_column(array_filter($state['rows'], static fn($row) => $row['user_id'] === 42), 'value', 'name'))->toBe($expected)
        ->and($state['rows'][count($state['rows']) - 1])->toBe(array('user_id' => 43, 'name' => 'page_refresh', 'value' => '15'))
        ->and($state['errors'])->toBe(array('day_shift_start', 'nested_invalid'))
        ->and($state['cached'])->toBeFalse();
});

test('native setting validation admits field choices and rejects malformed values', function () {
    expect(runPreferences('validate'))->toBe(array(false, true, true, false, true, true, false, true, true, false, true, false, true, false, true, false, false));
});

test('native debounce claims persist one row and recover expired or malformed timestamps', function () {
    $state = runPreferences('debounce');
    expect($state['claims'])->toBe(array(true, false, true, true))
        ->and($state['rows'])->toHaveCount(1)
        ->and($state['rows'][0]['name'])->toBe('debounce_page_error_user_42')
        ->and((int) $state['rows'][0]['value'])->toBeGreaterThanOrEqual($state['now'] - 1);
});
