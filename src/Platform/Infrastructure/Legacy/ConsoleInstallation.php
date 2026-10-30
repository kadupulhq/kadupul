<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Infrastructure\DatabaseTls;

/** Match global.php's primary/local selection for the console's native session. */
final class ConsoleInstallation
{
    /**
     * @param array<string, mixed> $values
     * @param array{collector_id: int, local: array<string, mixed>, main: ?array<string, mixed>} $targets
     * @return array<string, mixed>
     */
    public static function select(#[\SensitiveParameter] array $values, #[\SensitiveParameter] array $targets): array
    {
        if ($targets['collector_id'] === 1) {
            return $values + ['connection_status' => 'online'];
        }
        $local = self::connect($targets['local']);
        $queued = $output = false;
        try {
            $queued = self::value($local, 'SELECT COUNT(*) FROM poller_output_boost');
            $output = self::value($local, 'SELECT EXISTS(SELECT 1 FROM poller_output LIMIT 1)');
        } catch (\PDOException) {
            // A failed queue read cannot establish that local recovery ended.
        }
        $version = self::value($local, 'SELECT cacti FROM version LIMIT 1');
        if (!is_string($version) || $version === '') {
            throw new \RuntimeException('Collector state is unavailable.');
        }
        $online = false;
        if ($targets['main'] !== null) {
            try {
                self::connect($targets['main']);
                $online = true;
            } catch (\PDOException) {
                // An unreachable primary selects the existing local identity
                // store, exactly as the offline legacy console does.
            }
        }
        $state = !$online ? 'offline' : (($queued !== 0 && $queued !== '0') || ($output !== 0 && $output !== '0') ? 'recovery' : 'online');
        if ($state === 'online' && $version !== 'new_install') {
            $values = array_replace($values, $targets['main']);
        }

        return array_replace($values, [
            'connection_status' => $state,
            'console_schema_version' => $version,
            'database_sessions' => $values['database_sessions'] && $state === 'online',
        ]);
    }

    private static function value(\PDO $database, string $sql): string|int|bool|null
    {
        $statement = $database->query($sql);
        if (!$statement instanceof \PDOStatement) {
            return false;
        }
        $value = $statement->fetchColumn();
        if (!$statement->closeCursor()) {
            return false;
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function connect(#[\SensitiveParameter] array $values): \PDO
    {
        foreach (['host', 'database'] as $key) {
            if (str_contains((string) $values[$key], ';') || str_contains((string) $values[$key], "\0")) {
                throw new \RuntimeException('Invalid collector database configuration.');
            }
        }

        return new \PDO('mysql:host=' . $values['host'] . ';port=' . (int) $values['port']
            . ';dbname=' . $values['database'] . ';charset=utf8mb4', $values['username'], $values['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ] + DatabaseTls::options($values));
    }
}
