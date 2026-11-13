<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Kadupul\Alerting\Application\Port\RunLock;

/**
 * MariaDB/MySQL named locks (GET_LOCK) on the connection the alerting stores
 * use, so the lock lives exactly as long as the session that evaluates. The
 * server releases it when that session ends, so a killed run never leaves it
 * held. The name carries a hash of the schema name, so two installations on
 * one server do not block each other; it stays within the 64-character limit.
 */
final readonly class DbalRunLock implements RunLock
{
    public function __construct(private Connection $database) {}

    public function acquire(string $purpose): bool
    {
        // GET_LOCK returns 1 when taken, 0 when another session holds it, NULL on error.
        $taken = $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$this->name($purpose)]);
        if ($taken === null || $taken === false) {
            throw new \RuntimeException('The alerting lock could not be requested.');
        }

        return self::isOne($taken);
    }

    public function release(string $purpose): bool
    {
        try {
            // 1 when this session held it, 0 when another does, NULL when nobody does.
            return self::isOne($this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$this->name($purpose)]));
        } catch (DbalException|\RuntimeException) {
            // A lost session already dropped the lock with it; the failure that lost it is reported by the run.
            return false;
        }
    }

    /** The driver returns the lock functions' 1 as an integer or a string, depending on emulated prepares. */
    private static function isOne(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }

    private function name(string $purpose): string
    {
        if (!in_array($purpose, [self::EVALUATE, self::DELIVER], true)) {
            throw new \InvalidArgumentException('Unknown alerting lock.');
        }
        $schema = $this->database->fetchOne('SELECT DATABASE()');
        if (!is_string($schema) || $schema === '') {
            throw new \RuntimeException('The alerting lock needs a selected database.');
        }

        return 'kadupul-alerting-' . $purpose . '-' . substr(hash('sha256', $schema), 0, 32);
    }
}
