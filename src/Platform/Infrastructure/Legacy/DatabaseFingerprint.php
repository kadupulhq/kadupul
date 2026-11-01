<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** One identity format for the HTTP connection and its private native worker. */
final class DatabaseFingerprint
{
    /** @param array<string,mixed> $values */
    public static function capture(\PDO $database, #[\SensitiveParameter] array $values): string
    {
        $collector = self::decimal($values['collector_id'] ?? null, 4294967295);
        $state = $values['connection_status'] ?? null;
        $host = $values['host'] ?? null;
        $port = self::decimal($values['port'] ?? null, 65535);
        if ($collector === null
            || !in_array($state, ['online', 'offline', 'recovery'], true)
            || !is_string($host) || $host === '' || str_contains($host, ';') || preg_match('/[\x00-\x20]/', $host) === 1
            || $port === null
            || $database->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('The database target could not be confirmed.');
        }
        $statement = $database->prepare('SELECT DATABASE()');
        if ($statement === false || !$statement->execute()) {
            throw new \RuntimeException('The database schema could not be confirmed.');
        }
        self::confirmRead($statement);
        $schema = $statement->fetchColumn();
        self::confirmRead($statement);
        if (!is_string($schema) || $schema === '') {
            throw new \RuntimeException('The database schema could not be confirmed.');
        }
        return hash('sha256', json_encode([$collector, $state, strtolower($host), $port, $schema], JSON_THROW_ON_ERROR));
    }

    private static function confirmRead(\PDOStatement $statement): void
    {
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException('The database schema could not be confirmed.');
        }
    }

    private static function decimal(mixed $value, int $maximum): ?int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A[0-9]+\z/D', (string) $value) !== 1) {
            return null;
        }
        $digits = ltrim((string) $value, '0');
        $limit = (string) $maximum;
        if ($digits === '' || strlen($digits) > strlen($limit)
            || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            return null;
        }
        return (int) $digits;
    }
}
