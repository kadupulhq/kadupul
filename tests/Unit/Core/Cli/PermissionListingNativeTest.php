<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\PermissionListing;

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

test('trusted permission CLI lists canonical records without changing permissions', function (array $scenario): void {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/permission-cli-native-' . bin2hex(random_bytes(8));
    foreach (['', '/cli', '/include'] as $suffix) {
        if (!mkdir($directory . $suffix, 0700)) throw new \RuntimeException('Cannot create owned permission directory');
    }
    if (!copy($root . '/cli/add_perms.php', $directory . '/cli/add_perms.php')
        || file_put_contents($directory . '/include/cli_check.php', '<?php') !== 5) {
        throw new \RuntimeException('Cannot preserve actual permission CLI');
    }
    $program = <<<'CHILD'
        require $argv[1] . '/tests/Fixtures/permission-cli-native.php';
        if (!defined('PERMISSION_CLI_NATIVE_TEST_COVERAGE')) register_shutdown_function('permission_cli_observe');
        permission_cli_prepare($argv[1], $argv[2], json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR));
        require $argv[2] . '/cli/add_perms.php';
        CHILD;
    $registration = \child_coverage_registration(
        __FILE__,
        'permission-cli-' . $scenario['kind'],
        $scenario,
        ['permission-cli-persisted-state-unchanged'],
        array_merge(['cli/add_perms.php'], $scenario['helperHit'] ? ['lib/api_automation_tools.php'] : []),
        ['cli/add_perms.php', 'lib/api_automation_tools.php', 'tests/Fixtures/permission-cli-native.php', 'cacti.sql', 'include/cacti_version', 'include/global_constants.php']
    );
    $registration['collectorPrelude'] = 'define("PERMISSION_CLI_NATIVE_TEST_COVERAGE", true);'
        . 'define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($directory . '/cli/add_perms.php', true) . ');'
        . 'define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . '/cli/add_perms.php', true) . ');'
        . 'register_shutdown_function(static function (): void { permission_cli_observe(); });';
    $command = \child_coverage_command([PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'display_errors=stderr', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-r', $program,
        $root, $directory, json_encode($scenario, JSON_THROW_ON_ERROR)], $coverageDirectory, $registration);
    try {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/stdout', 'w'], 2 => ['file', $directory . '/stderr', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $exit = proc_close($process);
        $stdout = file_get_contents($directory . '/stdout');
        $stderr = file_get_contents($directory . '/stderr');
        expect($stderr)->toBe('')->and($exit)->toBe($scenario['exit']);
        expect($stdout)->toBeString();
        foreach ($scenario['contains'] as $text) expect($stdout)->toContain($text);
        foreach ($scenario['absent'] as $text) expect($stdout)->not->toContain($text);
        if ($scenario['kind'] === 'version') {
            $release = file_get_contents($root . '/include/cacti_version');
            if ($release === false || preg_match('/^\d+\.\d+\.\d+\s*$/D', $release) !== 1) throw new \RuntimeException('Cannot read expected permission release');
            $release = trim($release);
            expect($stdout)->toContain('Kadupul Add Permissions Utility, Version ' . $release . ' (DB: ' . $release . '),');
        }
        $json = file_get_contents($directory . '/state.json');
        expect($json)->toBeString();
        $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        expect($state['unchanged'])->toBeTrue()->and($state['readOnly'])->toBe(1)
            ->and($state['queries'])->toHaveCount($scenario['queries']);
        if ($scenario['kind'] === 'graphs') expect($state['queries'][1][1])->toBe(['21']);
        \child_coverage_collect($coverageDirectory);
    } finally {
        foreach (array_filter([$directory, $coverageDirectory]) as $owned) {
            if (!is_dir($owned)) continue;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($owned, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($owned);
            unset($GLOBALS['child_coverage_registrations'][$owned]);
        }
    }
})->with([
    'version reports current schema' => [['kind' => 'version', 'arguments' => ['--version'], 'exit' => 0, 'contains' => ['Kadupul Add Permissions Utility, Version '], 'absent' => ['Known'], 'queries' => 1, 'helperHit' => false]],
    'groups preserve explicit unsupported response' => [['kind' => 'groups', 'arguments' => ['--list-groups'], 'exit' => 0, 'contains' => ['This option has not yet been implemented'], 'absent' => ['Known'], 'queries' => 0, 'helperHit' => false]],
    'trees list persisted identities and ordering' => [['kind' => 'trees', 'arguments' => ['--list-trees'], 'exit' => 0, 'contains' => ["7\tManual Ordering (No Sorting)\tFirst tree", "9\tManual Ordering (No Sorting)\tAdjacent tree"], 'absent' => ['Selected graph'], 'queries' => 1, 'helperHit' => true]],
    'hosts list stored device identities' => [['kind' => 'hosts', 'arguments' => ['--list-hosts'], 'exit' => 0, 'contains' => ["21\t\t0\tSelected device", "22\t\t0\tAdjacent device"], 'absent' => ['Selected graph'], 'queries' => 1, 'helperHit' => true]],
    'templates list stored template identity' => [['kind' => 'templates', 'arguments' => ['--list-graph-templates'], 'exit' => 0, 'contains' => ["31\tTraffic template"], 'absent' => ['Selected graph'], 'queries' => 1, 'helperHit' => true]],
    'empty trees retain normal header' => [['kind' => 'trees-empty', 'arguments' => ['--list-trees'], 'empty' => true, 'exit' => 0, 'contains' => ['Known Trees: (id, sort method, name)'], 'absent' => ['First tree'], 'queries' => 1, 'helperHit' => true]],
    'missing host refuses graph listing' => [['kind' => 'missing-host', 'arguments' => ['--list-graphs'], 'exit' => 1, 'contains' => ['You must supply a valid host_id', 'Try --list-hosts'], 'absent' => ['Selected graph', 'Adjacent graph'], 'queries' => 1, 'helperHit' => false]],
    'unknown host refuses graph listing' => [['kind' => 'unknown-host', 'arguments' => ['--list-graphs', '--host-id=999'], 'exit' => 1, 'contains' => ['You must supply a valid host_id'], 'absent' => ['Selected graph', 'Adjacent graph'], 'queries' => 2, 'helperHit' => false]],
    'graphs scope actual adjacent identities' => [['kind' => 'graphs', 'arguments' => ['--list-graphs', '--host-id=21'], 'exit' => 0, 'contains' => ["101\tSelected graph\tTraffic template"], 'absent' => ['Adjacent graph'], 'queries' => 2, 'helperHit' => true]],
    'quiet graphs preserve only record rows' => [['kind' => 'graphs', 'arguments' => ['--list-graphs', '--host-id=21', '--quiet'], 'exit' => 0, 'contains' => ["101\tSelected graph\tTraffic template"], 'absent' => ['Adjacent graph', 'Known Device Graphs'], 'queries' => 2, 'helperHit' => true]],
]);
