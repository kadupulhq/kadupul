<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[, $root, $directory, $input] = $argv;
$case = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
$isPost = isset($case['post']);
require_once $root . '/tests/Helpers/NativeAutomationPresentation.php';
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (getenv('AUTOMATION_PRESENTATION_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (['automation_devices.php', 'automation_graph_rules.php', 'automation_snmp.php', 'automation_templates.php', 'automation_tree_rules.php', 'lib/html_form.php', 'lib/html.php', 'lib/functions.php', 'lib/html_utility.php', 'include/csrf.php'] as $source) $filter->includeFile($root . '/' . $source);
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/automation-presentation-native.php', $input, NativeAutomationPresentation::sources());
    $coverage->start('native automation presentation');
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader, $isPost): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $testLoader, $isPost): void {
            if (($GLOBALS['nativeAutomationMarkers'] ?? []) !== NativeAutomationPresentation::markers($isPost)) throw new RuntimeException('Automation outcomes did not complete');
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $serialized = serialize($coverage);
            $report = $directory . '/automation.coverage';
            if (file_put_contents($report, $serialized) !== strlen($serialized)) throw new RuntimeException('Cannot preserve automation coverage');
            NativeChildCoverageEvidence::write($report, $root, $snapshot, NativeAutomationPresentation::markers($isPost));
        });
    });
}
$definitions = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $definitions['pages']['auth_profile'];
$scenario['page'] = $case['page'];
$scenario['request'] = ($case['request'] ?? []) + ['header' => 'false'];
$scenario['settings'] = ['poller_interval' => '300', 'num_rows_table' => '30'];
if (file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Cannot preserve owned automation request');
}
$database = NativeAutomationPresentation::database($root, $directory);
foreach ($case['rows'] ?? [] as $table => $rows) {
    foreach ($rows as $row) NativeAutomationPresentation::insert($database, $table, $row);
}
if (isset($case['rejectDelete'])) {
    $table = $case['rejectDelete'];
    if (!in_array($table, $case['writes'] ?? [], true)) throw new RuntimeException('Unregistered owned trigger target');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $trigger = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? "CREATE TRIGGER reject_owned_delete BEFORE DELETE ON " . $table . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned deletion refused'"
        : "CREATE TRIGGER reject_owned_delete BEFORE DELETE ON " . $table . " BEGIN SELECT RAISE(ABORT, 'owned deletion refused'); END";
    if ($database->exec($trigger) === false) throw new RuntimeException('Cannot install owned backend failure control');
}
$before = NativeAutomationPresentation::snapshot($database);
$connection = new NativeAutomationConnection($database, $case['writes'] ?? []);
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationBootstrap'] = static function () use ($connection, $case): void {
    $GLOBALS['database_sessions'] = array_fill_keys(array_keys($GLOBALS['database_sessions']), $connection);
    if (isset($case['post'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $case['post'] + ['header' => 'false'];
        $_POST['__csrf_magic'] = csrf_get_tokens();
        $_GET = [];
        foreach ($_POST as $name => $value) set_request_var($name, $value);
        if (!csrf_check(false)) throw new RuntimeException('Actual generated CSRF token was refused');
        $GLOBALS['nativeAutomationCsrfChecked'] = true;
    }
    $_SESSION = ($case['session'] ?? []) + $_SESSION;
};
$GLOBALS['nativePresentationObserver'] = static function (array &$result) use ($database, $before, $connection, $case, $isPost): void {
    if (LegacyFormGoldenFiles::$transformedIncludes !== 0) throw new RuntimeException('Automation presentation executed transformed source');
    $expected = $before;
    foreach ($case['deleted'] ?? [] as $table => $identities) {
        foreach ($identities as $identity) {
            $expected[$table] = array_values(array_filter($expected[$table], static function (array $row) use ($identity): bool {
                foreach ($identity as $key => $value) if ((string) $row[$key] !== (string) $value) return true;
                return false;
            }));
        }
    }
    foreach ($case['updated'] ?? [] as $table => $updates) {
        foreach ($updates as $update) {
            foreach ($expected[$table] as &$row) {
                if ((string) $row['id'] === (string) $update['id']) $row = array_replace($row, $update['values']);
            }
            unset($row);
        }
    }
    foreach ($expected as &$rows) usort($rows, static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b));
    unset($rows);
    if ($expected !== NativeAutomationPresentation::snapshot($database)) throw new RuntimeException('Automation persisted outcome differs from admitted contract');
    if ($result['diagnostics'] !== []) throw new RuntimeException('Automation presentation emitted unexpected diagnostics: ' . json_encode($result['diagnostics']));
    if ($connection->domainQueries === []) throw new RuntimeException('Automation page did not query actual persisted records');
    $allowed = array_flip(NativeAutomationPresentation::sources());
    foreach (get_included_files() as $included) {
        $canonical = realpath($included);
        if ($canonical !== false && str_starts_with($canonical, $GLOBALS['root'] . '/')) {
            $relative = substr($canonical, strlen($GLOBALS['root']) + 1);
            if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($allowed[$relative])) {
                throw new RuntimeException('Automation page loaded unregistered source: ' . $relative);
            }
        }
    }
    $result['automation_queries'] = $connection->queries;
    $result['csrf_checked'] = $GLOBALS['nativeAutomationCsrfChecked'] ?? false;
    $result['row_cache'] = $database->query('SELECT * FROM user_auth_row_cache')->fetchAll(PDO::FETCH_ASSOC);
    $result['automation_state'] = NativeAutomationPresentation::snapshot($database);
    $GLOBALS['nativeAutomationMarkers'] = NativeAutomationPresentation::markers($isPost);
};
$argv = [__FILE__, $root, $directory];
require $root . '/tests/Fixtures/legacy-form-golden.php';
