<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class CdefCliCoverageRegistration
{
    public static function cases(): array
    {
        return [
            'current' => ['cdef_reference_installer_native_probe.php', [], 5, 'PASS native current-version CLI and cleanup complete'],
            'collector-online' => ['cdef_reference_cli_confirmation_native_probe.php', ['collector-online'], 1, 'PASS native CLI confirmation and cleanup complete'],
            'collector-local' => ['cdef_reference_cli_confirmation_native_probe.php', ['collector-local'], 1, 'PASS native CLI confirmation and cleanup complete'],
            'collector-offline' => ['cdef_reference_cli_confirmation_native_probe.php', ['collector-offline'], 1, 'PASS native CLI confirmation and cleanup complete'],
            'marker-success' => ['cdef_reference_cli_confirmation_native_probe.php', ['marker-success'], 1, 'PASS native CLI confirmation and cleanup complete'],
            'marker-refusal' => ['cdef_reference_cli_confirmation_native_probe.php', ['marker-refusal'], 2, 'PASS native CLI confirmation and cleanup complete'],
            'marker-coercion' => ['cdef_reference_cli_confirmation_native_probe.php', ['marker-coercion'], 2, 'PASS native CLI confirmation and cleanup complete'],
        ];
    }

    public static function sources(): array
    {
        require_once __DIR__ . '/CdefNativeCoverageRegistration.php';
        return array_values(array_unique(array_merge(CdefNativeCoverageRegistration::sources(), [
            'cli/upgrade_database.php', 'include/cli_check.php', 'include/global.php',
            'lib/data_query.php', 'lib/poller.php', 'lib/utility.php', 'install/functions.php',
            'lib/rrd_maintenance.php', 'lib/data_source_profile_integrity.php',
            'install/upgrades/1_2_34.php', 'src/Platform/Infrastructure/Legacy/LegacyComponentAutoloader.php',
            'phpunit-symfony.xml', '.github/workflows/ci.yml',
            'tests/Helpers/CdefCliCoverageRegistration.php', 'tests/Fixtures/cdef-cli-native-coverage.php',
            'tests/Symfony/CdefCliNativeCoverageTest.php',
            'tests/security/cdef_reference_cli_confirmation_native_probe.php',
            'tests/security/cdef_reference_normal_installer_native_probe.php',
            'tests/Fixtures/cdef-reference-runtime-config.php',
            'tests/Fixtures/cdef-reference-collector-runtime-config.php',
            'tests/security/run_cdef_reference_contracts.py',
        ])));
    }

    /** Optional instrumentation; unchanged native runs keep clearing auto_prepend. */
    public static function invocation(string $root, array $environment, string $entry, array $arguments): array
    {
        $directory = $environment['KADUPUL_CLI_COVERAGE_DIRECTORY'] ?? null;
        if ($directory === null) return ['', $environment, null];
        if ($entry !== 'cli/upgrade_database.php' || !is_string($directory)
            || !is_dir($directory) || !is_writable($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Invalid owned CLI coverage invocation.');
        }
        foreach ($arguments as $argument) {
            if (!in_array($argument, ['--local', '--install-cdef-reference-contract'], true)) {
                throw new RuntimeException('Unregistered CLI coverage argument.');
            }
        }
        $id = bin2hex(random_bytes(16));
        $environment['KADUPUL_CLI_COVERAGE_INVOCATION'] = $id;
        $environment['KADUPUL_CLI_COVERAGE_ARGV_HASH'] = hash('sha256', json_encode([$entry, ...$arguments], JSON_THROW_ON_ERROR));
        $invocation = json_encode(['id' => $id, 'arguments' => $arguments,
            'ordinal' => count(glob($directory . '/*.invocation.json') ?: []) + 1,
            'argv_sha256' => $environment['KADUPUL_CLI_COVERAGE_ARGV_HASH']], JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/' . $id . '.invocation.json', $invocation, LOCK_EX) !== strlen($invocation)) {
            throw new RuntimeException('Cannot bind original CLI invocation.');
        }
        return [$root . '/tests/Fixtures/cdef-cli-native-coverage.php', $environment, $directory . '/' . $id];
    }

    public static function receipt(?string $path, int $exit, string $output, string $errors): void
    {
        if ($path === null) return;
        $report = hash_file('sha256', $path . '.coverage');
        if ($report === false) throw new RuntimeException('The actual CLI coverage report is missing.');
        $data = json_encode(['exit' => $exit, 'stdout_sha256' => hash('sha256', $output),
            'stderr_sha256' => hash('sha256', $errors), 'report_sha256' => $report], JSON_THROW_ON_ERROR);
        if (file_put_contents($path . '.exit.json', $data, LOCK_EX) !== strlen($data)) {
            throw new RuntimeException('Cannot confirm original CLI process receipt.');
        }
    }

    /** Capture only digests after original SQL assertions and cleanup. */
    public static function capture(string $path): array
    {
        $result = [];
        foreach (['.invocation.json', '.exit.json', '.coverage'] as $suffix) {
            if (!is_file($path . $suffix)) throw new RuntimeException('Original CLI admission evidence is missing.');
            $digest = hash_file('sha256', $path . $suffix);
            if ($digest === false) throw new RuntimeException('Original CLI admission evidence is unreadable.');
            $result[$suffix] = $digest;
        }
        return $result;
    }

    public static function admit(string $path, array $capture, array $expectedArguments, int $expectedExit): array
    {
        if (self::capture($path) !== $capture) throw new RuntimeException('Original CLI admission evidence changed after completion.');
        try {
            $invocation = json_decode(file_get_contents($path . '.invocation.json'), true, 512, JSON_THROW_ON_ERROR);
            $receipt = json_decode(file_get_contents($path . '.exit.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Original CLI admission evidence is malformed.', 0, $error);
        }
        $digest = hash('sha256', json_encode(['cli/upgrade_database.php', ...$expectedArguments], JSON_THROW_ON_ERROR));
        if (($invocation['arguments'] ?? null) !== $expectedArguments || ($invocation['argv_sha256'] ?? null) !== $digest
            || ($receipt['exit'] ?? null) !== $expectedExit || ($receipt['report_sha256'] ?? null) !== $capture['.coverage']) {
            throw new RuntimeException('Original CLI invocation or confirmed exit does not match the scenario.');
        }
        return [$invocation, $receipt];
    }
}
