<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

final class ConsoleSelectionFaultPdo extends \PDO
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly \PDO $database, public readonly string $stage) {}

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->events[] = 'query';
        if ($this->stage === 'query-false') return false;
        $statement = $fetchMode === null ? $this->database->query($query) : $this->database->query($query, $fetchMode, ...$fetchModeArgs);
        if ($statement === false) throw new \RuntimeException('Real collector queue statement unavailable.');
        return new ConsoleSelectionFaultStatement($statement, $this);
    }
}

final class ConsoleSelectionFaultStatement extends \PDOStatement
{
    public function __construct(private readonly \PDOStatement $statement, private readonly ConsoleSelectionFaultPdo $owner) {}

    public function fetchColumn(int $column = 0): mixed
    {
        $this->owner->events[] = 'fetchColumn';
        return $this->statement->fetchColumn($column);
    }

    public function closeCursor(): bool
    {
        $this->owner->events[] = 'closeCursor';
        $closed = $this->statement->closeCursor();
        return $this->owner->stage === 'close-false' ? false : $closed;
    }
}
