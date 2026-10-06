<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 2) . '/Helpers/InstallerStateEvidence.php';
require_once dirname(__DIR__, 2) . '/Helpers/NativeChildCoverageEvidence.php';
require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

it('preserves installer hydration and normalization contracts', function (string $scenario, int $expected): void {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/installer-state-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $result = test_php_run(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root,
                $root . '/' . InstallerStateEvidence::PRODUCER, $directory, $scenario, $coverage !== null ? 'coverage' : ''],
        );
        $output = $result['out'];
        $errors = $result['err'];
        expect($result['status'])->toBe(0, $errors . $output);
        expect($errors)->toBe('');
        if (getenv('INSTALLER_STATE_TEST_DSN')) {
            $cleanup = file_get_contents($directory . '/owned-schema-cleanup.json');
            expect($cleanup)->not->toBeFalse();
            $receipt = json_decode($cleanup, true, flags: JSON_THROW_ON_ERROR);
            expect($receipt['removed'])->toBeTrue();
            expect($receipt['schema'])->toMatch('/\Akadupul_installer_state_[a-f0-9]{24}\z/D');
        }
        $state = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect((int) $state['persisted']['install_step'])->toBe($expected);
        expect($state['readback'])->toBe($state['persisted']['install_step']);
        expect((int) $state['next_local_step'])->toBe($expected);
        expect($state['persisted'])->toBe($state['next_persisted']);
        expect($state['persisted']['adjacent_setting'])->toBe('unchanged');
        expect($state['persisted']['selected_theme'])->toBe('modern');
        expect($state['navigation']['Prev']['Step'])->toBe($expected >= 98 || $expected === 1 ? 0 : 96);
        expect($state['navigation']['Next']['Step'])->toBe($expected >= 98 ? 0 : ($expected === 1 ? 2 : 98));
        if (in_array($scenario, ['new-version', 'retry-reset'], true)) {
            expect($state['deletes'][0])->toBe("DELETE FROM settings WHERE name LIKE 'install_%'");
            expect(count(array_filter($state['deletes'], static fn(string $sql): bool => $sql === "DELETE FROM settings WHERE name LIKE 'install_%'")))->toBe(1);
            foreach (['install_error', 'install_complete', 'install_version', 'install_snmp_option_test'] as $name) {
                expect($state['persisted'])->not->toHaveKey($name);
            }
            expect($state['persisted']['default_template'])->toBe('1');
            expect($state['persisted']['install_mode'])->toBe('3');
            expect($state['version'])->toBe('1.2.33');
            expect($state['persisted']['path_rrdtool'])->toBe($state['before']['path_rrdtool']);
            expect(array_values(array_filter($state['writes'], static fn(array $write): bool => $write[0] === 'install_step')))->toBe([['install_step', 1]]);
        }
        if ($scenario === 'numeric-string') {
            expect($state['persisted']['install_step'])->toBe('097');
            expect($state['next_local_step'])->toBe('097');
        }
        if (str_starts_with($scenario, 'normalize-')) {
            expect($state['persisted']['install_step'])->toBe('1');
            expect($state['persisted']['install_prev'])->toBe('0');
            expect($state['persisted']['install_next'])->toBe('2');
        }
        if (str_starts_with($scenario, 'complete-')) {
            expect($state['persisted']['install_prev'])->toBe('0');
            expect($state['persisted']['install_next'])->toBe('0');
            expect($state['persisted']['install_error'])->toBe('');
            expect(array_values(array_filter($state['writes'], static fn(array $write): bool => $write[0] === 'install_step')))->toBe([['install_step', 98]]);
        }
        if (str_starts_with($scenario, 'failure-') || in_array($scenario, ['failed', 'reported-error'], true)) {
            expect($state['persisted']['install_error'])->toBe('owned worker refusal');
        }
        if ($coverage !== null) {
            $report = $directory . '/state.coverage';
            $child = NativeChildCoverageEvidence::load(
                $report,
                $root,
                InstallerStateEvidence::PRODUCER,
                $scenario,
                InstallerStateEvidence::sources(),
                InstallerStateEvidence::MARKERS,
                ['lib/installer.php', 'lib/functions.php']
            );
            expect(NativeChildCoverageEvidence::verifyRejections(
                $report,
                $root,
                InstallerStateEvidence::PRODUCER,
                $scenario,
                InstallerStateEvidence::sources(),
                InstallerStateEvidence::MARKERS,
                ['lib/installer.php', 'lib/functions.php'],
                'lib/installer.php'
            ))->toBe(count(InstallerStateEvidence::sources()) + 12);
            $coverage->merge($child);
        }
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            expect(unlink($file))->toBeTrue();
        }
        expect(rmdir($directory))->toBeTrue();
    }
})->with([
    'complete after step read' => ['complete-read', 98],
    'complete before hydration write' => ['complete-write', 98],
    'failure after step read' => ['failure-read', 99],
    'failure before hydration write' => ['failure-write', 99],
    'active worker' => ['running', 97],
    'already complete' => ['completed', 98],
    'failed background poll' => ['failed', 99],
    'error recovery transition' => ['reported-error', 99],
    'absent step default' => ['default', 98],
    'numeric string hydration retains its original type' => ['numeric-string', 97],
    'invalid setter input retains welcome normalization' => ['normalize-invalid', 1],
    'zero setter input retains welcome normalization' => ['normalize-zero', 1],
    'negative setter input retains welcome normalization' => ['normalize-negative', 1],
    'outside setter input retains welcome normalization' => ['normalize-outside', 1],
    'completed prior version starts a new wizard' => ['new-version', 1],
    'normal failed-version reload starts a retry wizard' => ['retry-reset', 1],
]);
