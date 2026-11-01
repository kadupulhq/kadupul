<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

/** Real schema reads with one explicitly selected driver-stage failure. */
final class DatabaseTargetFaultPdo extends \PDO
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly \PDO $database, public readonly string $stage) {}

    public function getAttribute(int $attribute): mixed
    {
        return $this->database->getAttribute($attribute);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->events[] = 'prepare';
        if ($this->stage === 'prepare-false') return false;
        if ($this->stage === 'prepare-throw') throw new \PDOException('Injected prepare failure.');
        $statement = $this->database->prepare($query, $options);
        if ($statement === false) throw new \RuntimeException('Real schema statement unavailable.');
        return new DatabaseTargetFaultStatement($statement, $this);
    }
}

final class DatabaseTargetFaultStatement extends \PDOStatement
{
    private bool $fetched = false;

    public function __construct(private readonly \PDOStatement $statement, private readonly DatabaseTargetFaultPdo $owner) {}

    public function execute(?array $params = null): bool
    {
        $this->owner->events[] = 'execute';
        if ($this->owner->stage === 'execute-false') return false;
        if ($this->owner->stage === 'execute-throw') throw new \PDOException('Injected execute failure.');
        return $this->statement->execute($params);
    }

    public function errorCode(): ?string
    {
        $this->owner->events[] = 'errorCode';
        if ($this->owner->stage === 'execute-state') return '45000';
        if ($this->owner->stage === 'execute-null-state') return null;
        if ($this->owner->stage === 'state-throw') throw new \PDOException('Injected state failure.');
        if ($this->fetched && $this->owner->stage === 'fetch-state') return '45000';
        if ($this->fetched && $this->owner->stage === 'fetch-null-state') return null;
        return $this->statement->errorCode();
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $this->owner->events[] = 'fetchColumn';
        $value = $this->statement->fetchColumn($column);
        $this->fetched = true;
        return match ($this->owner->stage) {
            'fetch-throw' => throw new \PDOException('Injected fetch failure.'),
            'fetch-false' => false,
            'fetch-empty' => '',
            'fetch-integer' => 1,
            default => $value,
        };
    }
}
