<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class PredicateNativeEvidence
{
    public const SOURCES = [
        'tests/Fixtures/string-predicates-native.php',
        'tests/Fixtures/rrd-process-coverage.php',
        'tests/Unit/Core/Helpers/StringPredicateNativeTest.php',
        'tests/Helpers/PredicateNativeEvidence.php',
        'tests/composer.lock', 'composer.lock',
        'include/global_constants.php', 'lib/functions.php', 'lib/html_utility.php',
        'lib/html.php', 'lib/database.php', 'lib/path_helpers.php', 'lib/poller.php',
        'src/Platform/Infrastructure/Legacy/LegacyIncludePathResolver.php', 'lib/ping.php', 'lib/api_automation.php', 'lib/ldap.php', 'lib/snmp.php',
        'include/vendor/phpsnmp/classSNMP.php', 'include/vendor/phpsnmp/extension.php',
    ];

    public static function capture(string $root, string $result): array
    {
        $sources = [];
        foreach (self::SOURCES as $path) {
            $hash = hash_file('sha256', $root . '/' . $path);
            if ($hash === false) {
                throw new RuntimeException('Missing predicate evidence source: ' . $path);
            }
            $sources[$path] = $hash;
        }
        return ['sources' => $sources, 'runtime' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'result' => hash('sha256', $result), 'completed' => true];
    }

    public static function verifyCoverage(array $receipt, string $root, string $result, string $artifact): void
    {
        if (($receipt['artifact'] ?? null) !== hash('sha256', $artifact)) {
            throw new RuntimeException('Predicate coverage artifact is missing or altered');
        }
        unset($receipt['artifact']);
        self::verify($receipt, $root, $result);
    }

    public static function verify(array $receipt, string $root, string $result): void
    {
        if ($receipt !== self::capture($root, $result)) {
            throw new RuntimeException('Predicate evidence is stale, incomplete or from another runtime');
        }
    }
}
