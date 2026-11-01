<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace Kadupul\Tests;

final class NativeSessionCoverageRegistration
{
    public const PRODUCER = 'tests/Fixtures/native-session-worker-coverage.php';
    public const MARKERS = ['native-session-scenario-completed'];
    public const MEASURED = ['lib/session_data.php', 'lib/session_store.php', 'lib/session_metadata.php', 'include/session.php'];
    public const FIXTURES = ['session-data-native.php', 'session-store-native.php', 'session-store-fault-native.php', 'session-store-budget-native.php', 'session-store-metadata-lock-native.php', 'session-engine-endpoint.php'];
    public const SOURCES = ['src/Platform/Infrastructure/Legacy/LegacyConnectionRegistry.php', 'lib/functions.php', 'include/global_constants.php', 'src/IdentityAccess/Infrastructure/Legacy/AuthenticationDatabaseSessionHandler.php', 'composer.lock', 'tests/composer.lock', 'cacti.sql', 'lib/session_data.php', 'lib/session_store.php', 'lib/session_metadata.php', 'include/session.php',
        'tests/Symfony/NativeCoverageStack.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Fixtures/rrd-process-coverage.php',
        self::PRODUCER, 'tests/Symfony/NativeSessionCoverageRegistration.php', 'tests/Symfony/NativeSessionChildCoverage.php',
        'tests/Symfony/NativeSessionDataTest.php', 'tests/Symfony/NativeSessionPersistenceTest.php', 'tests/Symfony/NativeSessionCoverageRegistrationTest.php',
        'tests/Fixtures/session-data-native.php', 'tests/Fixtures/session-store-native.php', 'tests/Fixtures/session-store-fault-native.php',
        'tests/Fixtures/session-store-budget-native.php', 'tests/Fixtures/session-store-metadata-lock-native.php', 'tests/Fixtures/session-engine-native.php', 'tests/Fixtures/session-engine-endpoint.php'];

    /** @param array<string,array<int,list<string>|null>> $lines */
    public static function requireMeasured(array $lines, string $root): void
    {
        foreach (self::MEASURED as $source) {
            $path = realpath($root . '/' . $source);
            $observed = false;
            foreach ($path === false ? [] : ($lines[$path] ?? []) as $tests) {
                if (is_array($tests) && $tests !== []) {
                    $observed = true;
                    break;
                }
            }
            if (!$observed) {
                throw new \RuntimeException('Missing measured native session execution: ' . $source);
            }
        }
    }

}
