<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

final class RecoveryQueueFaultPdo extends \PDO
{
    /** @var list<string> */
    public array $events = [];
    public function __construct(private readonly \PDO $database, public readonly string $stage) {}

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->events[] = 'query';
        if ($this->stage === 'query-false') return false;
        if ($this->stage === 'query-throw') throw new \PDOException('Injected queue read failure.');
        if ($this->stage === 'program-error') throw new \LogicException('Injected programming failure.');
        $statement = $fetchMode === null ? $this->database->query($query) : $this->database->query($query, $fetchMode, ...$fetchModeArgs);
        if ($statement === false) throw new \RuntimeException('Actual queue fixture statement unavailable.');
        return new RecoveryQueueFaultStatement($statement, $this);
    }
}

final class RecoveryQueueFaultStatement extends \PDOStatement
{
    private bool $fetched = false;
    private bool $closed = false;
    public function __construct(private readonly \PDOStatement $statement, private readonly RecoveryQueueFaultPdo $owner) {}

    public function fetchColumn(int $column = 0): mixed
    {
        $this->owner->events[] = 'fetchColumn';
        $value = $this->statement->fetchColumn($column);
        $this->fetched = true;
        if ($this->owner->stage === 'fetch-throw') throw new \PDOException('Injected late queue read failure.');
        return $this->owner->stage === 'fetch-false' ? false : $value;
    }

    public function errorCode(): ?string
    {
        $this->owner->events[] = $this->closed ? 'state-closed' : ($this->fetched ? 'state-fetched' : 'state-initial');
        $stage = $this->owner->stage;
        if ($stage === 'state-throw') throw new \PDOException('Injected queue state failure.');
        if ($stage === 'pre-null') return null;
        if ($stage === 'pre-error' || ($this->fetched && $stage === 'post-error') || ($this->closed && $stage === 'close-error')) return 'HY000';
        return $this->statement->errorCode();
    }

    public function closeCursor(): bool
    {
        $this->owner->events[] = 'closeCursor';
        $closed = $this->statement->closeCursor();
        $this->closed = true;
        if ($this->owner->stage === 'close-throw') throw new \PDOException('Injected queue close failure.');
        return $this->owner->stage === 'close-false' ? false : $closed;
    }
}
