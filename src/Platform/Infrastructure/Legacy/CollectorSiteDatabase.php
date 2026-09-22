<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyConfiguration;

/** Preserve legacy online collector site administration without a local-write fallback. */
final class CollectorSiteDatabase
{
    public static function verify(#[\SensitiveParameter] array $local, #[\SensitiveParameter] array $primary): void
    {
        try {
            $localDb = self::connect($local);
            $version = $localDb->query('SELECT cacti FROM version LIMIT 1')->fetchColumn();
            if (!is_string($version) || $version === '' || $version === 'new_install'
                || (int) $localDb->query('SELECT COUNT(*) FROM poller_output_boost')->fetchColumn() > 0) {
                throw new \RuntimeException('Collector recovery is pending.');
            }
            // The same connection factory enforces DSN and TLS rules for both endpoints.
            $version = self::connect($primary)->query('SELECT cacti FROM version LIMIT 1')->fetchColumn();
            if (!is_string($version) || $version === '' || $version === 'new_install') {
                throw new \RuntimeException('Primary installation is incomplete.');
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Sites administration requires an online collector with a reachable primary.');
        }
    }

    private static function connect(#[\SensitiveParameter] array $values): \PDO
    {
        $configuration = new class ($values) implements LegacyConfiguration {
            public function __construct(#[\SensitiveParameter] private readonly array $configuration) {}
            public function values(): array
            {
                return $this->configuration;
            }
        };
        return (new InstallationDatabase($configuration))->get();
    }
}
