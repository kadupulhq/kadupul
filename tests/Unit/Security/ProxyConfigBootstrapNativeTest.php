<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../../Helpers/PhpSource.php';
require_once __DIR__ . '/../../Helpers/NativeChildCoverageEvidence.php';
require_once __DIR__ . '/../../Helpers/ProxyBootstrapCoverageRegistration.php';

test('real config bootstrap maps trusted proxy variables and preserves legacy unset defaults', function (bool $configured) {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/proxy-config-bootstrap-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $scenario = json_encode(['configured' => $configured], JSON_THROW_ON_ERROR);
    $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/proxy-config-bootstrap-native.php', $scenario, $directory];
    if ($coverage !== null) $command[] = 'coverage';
    try {
        $result = test_php_run($command);
        expect($result['status'])->toBe(0)->and($result['err'])->toBe('');
        expect(json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR))->toBe(['headers' => ['HTTP_X_FORWARDED_FOR'], 'trusted' => $configured ? ['192.0.2.10'] : [], 'client' => $configured ? '203.0.113.5' : '192.0.2.10']);
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $arguments = [$reports[0], $root, 'tests/Fixtures/proxy-config-bootstrap-native.php', $scenario, ProxyBootstrapCoverageRegistration::SOURCES, ProxyBootstrapCoverageRegistration::MARKERS, ProxyBootstrapCoverageRegistration::HITS];
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            expect(NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, ['lib/rrd.php'])))->toBe(count(ProxyBootstrapCoverageRegistration::SOURCES) + 11);
            $original = file_get_contents($reports[0] . '.json');
            expect(is_string($original))->toBeTrue();
            $evidence = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
            try {
                foreach (['producer', 'scenario', 'report', 'source'] as $kind) {
                    $changed = $evidence;
                    if ($kind === 'source') $changed['sources']['include/global.php'] = str_repeat('0', 64);
                    else $changed[$kind] = str_repeat('0', 64);
                    expect(file_put_contents($reports[0] . '.json', json_encode($changed, JSON_THROW_ON_ERROR)))->not->toBeFalse();
                    try {
                        NativeChildCoverageEvidence::load(...$arguments);
                        throw new LogicException('Stale bootstrap evidence was admitted.');
                    } catch (RuntimeException $error) {
                        expect($error->getMessage())->toContain('stale');
                    }
                }
            } finally {
                expect(file_put_contents($reports[0] . '.json', $original))->toBe(strlen($original));
            }
            $coverage->merge($measured);
        }
    } finally {
        foreach (glob($directory . '/*.coverage*') as $file) unlink($file);
        foreach (['include/global.php', 'include/runtime.php', 'include/cacti_version', 'include/config.php', 'lib/database.php'] as $file) if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
        foreach (['include', 'lib'] as $subdirectory) if (is_dir($directory . '/' . $subdirectory)) rmdir($directory . '/' . $subdirectory);
        rmdir($directory);
    }
})->with(['configured proxy list' => true, 'old config without proxy list' => false]);
