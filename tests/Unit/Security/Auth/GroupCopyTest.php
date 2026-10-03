<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Copying a group must carry its source policy, realms and permissions.
 * The native producer executes the shipped copy with actual persisted rows.
 */
require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

function group_copy_run(string $scenario): array
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/group-copy-wiring-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = \Pest\TestSuite::getInstance()->test->getTestResultObject()->getCodeCoverage();
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=' . error_reporting(), '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/group-copy-native.php', $scenario, $directory, $coverage !== null ? 'coverage' : ''), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        expect($process)->toBeResource();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if ($coverage !== null) {
            $sources = array('composer.lock', 'tests/composer.lock', 'tests/Symfony/GroupCopyTransactionTest.php', 'cacti.sql', 'lib/auth.php', 'lib/functions.php', 'tests/Helpers/PhpSource.php', 'lib/database.php', 'user_group_admin.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/Security/Auth/GroupCopyTest.php');
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $arguments = array($reports[0], $root, 'tests/Fixtures/group-copy-native.php', $scenario, $sources, array('persisted-group-copy-observed'), array('lib/auth.php', 'user_group_admin.php', 'lib/database.php'));
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            if ($scenario === 'wiring-success') {
                expect(NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, array('lib/rrd.php'))))->toBe(35);
            }
            $coverage->merge($measured);
        }
        return $state;
    } finally {
        foreach (glob($directory . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
}

test('a copied group gets the source group\'s permissions and realms', function () {
    $result = group_copy_run('wiring-success');
    expect($result['status'])->toBeTrue()->and($result['copies'])->toHaveCount(1);
    $id = (int) $result['copies'][0]['id'];
    $perms = array_values(array_filter($result['perms'], static fn($row) => (int) $row['group_id'] === $id));
    $realms = array_values(array_filter($result['realms'], static fn($row) => (int) $row['group_id'] === $id));
    expect(array_map(static fn($row) => array((int) $row['item_id'], (int) $row['type']), $perms))->toBe(array(array(12, 2), array(30, 3)))
        ->and(array_map('intval', array_column($realms, 'realm_id')))->toBe(array(7, 8));
});

test('the new group row is copied from the source id', function () {
    $result = group_copy_run('wiring-success');
    expect($result['status'])->toBeTrue()->and($result['copies'])->toHaveCount(1)
        ->and($result['copies'][0]['name'])->toBe('Copied 1')
        ->and($result['copies'][0]['description'])->toBe('policy');
});

test('a source group without grants produces a copy without grants', function () {
    $result = group_copy_run('wiring-empty');
    expect($result['status'])->toBeTrue()->and($result['copies'])->toHaveCount(1);
    $id = (int) $result['copies'][0]['id'];
    expect(array_values(array_filter($result['perms'], static fn($row) => (int) $row['group_id'] === $id)))->toBe(array())
        ->and(array_values(array_filter($result['realms'], static fn($row) => (int) $row['group_id'] === $id)))->toBe(array());
});

test('a failed insert copies nothing further', function () {
    $result = group_copy_run('wiring-parent-insert');
    expect($result['parent_insert_refused'])->toBeTrue()->and($result['status'])->toBeFalse()
        ->and($result['transaction'])->toBeFalse()->and($result['copies'])->toBe(array())
        ->and(array_map('intval', array_column($result['perms'], 'group_id')))->toBe(array(5, 5, 7))
        ->and(array_map('intval', array_column($result['realms'], 'group_id')))->toBe(array(5, 5, 7));
});
