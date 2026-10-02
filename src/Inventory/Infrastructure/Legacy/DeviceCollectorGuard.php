<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use PDO;
use PDOStatement;
use RuntimeException;

/** Revalidate collector configuration after I/O without blocking its heartbeat. */
final readonly class DeviceCollectorGuard
{
    private const CONFIGURATION = 'id, disabled, hostname, dbhost, dbport, dbdefault, dbuser, dbpass, dbretries, dbssl, dbsslkey, dbsslcert, dbsslca';

    /** @param array<string, mixed> $configuration */
    private function __construct(private PDO $connection, private int $pollerId, #[\SensitiveParameter] private array $configuration, private ?int $maximumHeartbeatAge) {}

    public static function capture(PDO $connection, int $pollerId, ?int $maximumHeartbeatAge = null): self
    {
        $row = self::read($connection, $pollerId, false);
        self::requireAvailable($row, $maximumHeartbeatAge);
        unset($row['heartbeat_age']);
        return new self($connection, $pollerId, $row, $maximumHeartbeatAge);
    }

    /** Call immediately before commit, after all slow/external work. */
    public function assertCurrent(): void
    {
        $row = self::read($this->connection, $this->pollerId, true);
        self::requireAvailable($row, $this->maximumHeartbeatAge);
        unset($row['heartbeat_age']);
        if ($row !== $this->configuration) {
            throw new RuntimeException('Collector configuration changed');
        }
    }

    /** @return array<string, mixed> */
    private static function read(PDO $connection, int $pollerId, bool $lock): array
    {
        if (!$connection->inTransaction()) {
            throw new \LogicException('Collector verification requires the worker transaction');
        }
        $query = $connection->prepare('SELECT ' . self::CONFIGURATION . ', UNIX_TIMESTAMP() - UNIX_TIMESTAMP(last_status) AS heartbeat_age FROM poller WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        if (!$query instanceof PDOStatement || !$query->execute([$pollerId])) {
            throw new RuntimeException('Collector verification unavailable');
        }
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $query->errorCode() !== '00000') {
            throw new RuntimeException('Collector unavailable');
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function requireAvailable(#[\SensitiveParameter] array $row, ?int $maximumHeartbeatAge): void
    {
        if ($maximumHeartbeatAge !== null && ($row['disabled'] !== '' || $row['heartbeat_age'] === null || (int) $row['heartbeat_age'] >= $maximumHeartbeatAge)) {
            throw new RuntimeException('Collector unavailable');
        }
    }
}
