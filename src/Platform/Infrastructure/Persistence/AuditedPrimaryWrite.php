<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;

/**
 * The audited write transaction the DBAL CDEF, automation template and Data
 * Source Profile stores share. The store passes its own connection, so the
 * checks, the operation and the commit use one session. On MySQL the write
 * must run on the primary collector, every participant must be InnoDB, and
 * the transaction runs at REPEATABLE READ so the range locks the operation
 * takes hold when the session default is READ COMMITTED. The actor is
 * rechecked inside the transaction, and every attempt is audited.
 *
 * Messages are built from the store's nouns, for example "CDEF writes require
 * their own transaction." and "CDEFs must be changed on the primary
 * collector.", so each store reports what it reported before.
 */
final readonly class AuditedPrimaryWrite
{
    /**
     * @param Closure(int): void $assertCurrent refuses an actor who may no longer write
     * @param list<string> $tables every table the store writes or locks, including the permission tables
     * @param string $subject noun for the transaction and table errors
     * @param string $plural noun for the primary collector error
     * @param string $auditAction audit action prefix; the store's action follows a dot
     * @param ?Closure(): void $prepare a further MySQL check, run after the table check
     */
    public function __construct(
        private Connection $database,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
        private Closure $assertCurrent,
        private array $tables,
        private string $subject,
        private string $plural,
        private string $auditAction,
        private string $auditType,
        private ?Closure $prepare = null,
    ) {}

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(int $actorId, string $action, string $target, callable $operation): mixed
    {
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->prepare();
            $this->database->beginTransaction();
            try {
                ($this->assertCurrent)($actorId);
                $decision = AuditEvent::ALLOWED;
                $outcome = AuditEvent::FAILED;
                $result = $operation();
                $this->database->commit();
            } catch (\Throwable $error) {
                if ($this->database->isTransactionActive()) {
                    $this->database->rollBack();
                }
                throw $error;
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } finally {
            $this->record($actorId, $action, $target, $decision, $outcome);
        }
    }

    /** The checks run() makes before it opens the transaction, for a store that opens its own. */
    public function prepare(): void
    {
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException($this->subject . ' writes require their own transaction.');
        }
        if (!$this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        // Remote collectors receive these tables by replication from the primary.
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException($this->plural . ' must be changed on the primary collector.');
        }
        foreach ($this->tables as $table) {
            // SHOW CREATE TABLE sees a temporary table that shadows the real one.
            $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $this->database->quoteIdentifier($table));
            if ($definition === false || !is_string($definition[1] ?? null) || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException($this->subject . ' writes require transactional tables.');
            }
        }
        if ($this->prepare !== null) {
            ($this->prepare)();
        }
        // Keep the operation's range locks when the session default is READ COMMITTED.
        $this->database->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    public function record(int $actorId, string $action, string $target, string $decision, string $outcome): void
    {
        try {
            $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, $this->auditAction . '.' . $action, $this->auditType, $target, $decision, $outcome));
        } catch (\Throwable) { /* Audit failure cannot change a confirmed persistence result. */
        }
    }

    /**
     * Refuse a selection that is empty, too large, unsorted, repeated or out
     * of the id column's range, and revisions that do not name exactly the
     * selected ids. Both come from a submitted form, so their shape is checked
     * here rather than assumed.
     *
     * @param array<mixed> $ids
     * @param array<mixed> $revisions
     * @phpstan-assert non-empty-list<int> $ids
     * @phpstan-assert array<int, string> $revisions
     */
    public static function assertSelection(array $ids, array $revisions, int $maxCount, int $maxId, string $invalid, string $stale): void
    {
        if ($ids === [] || count($ids) > $maxCount || !array_is_list($ids)) {
            throw new \InvalidArgumentException($invalid);
        }
        $checked = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > $maxId) {
                throw new \InvalidArgumentException($invalid);
            }
            $checked[] = $id;
        }
        $sorted = $checked;
        sort($sorted, SORT_NUMERIC);
        if ($sorted !== $checked || count(array_unique($checked)) !== count($checked)) {
            throw new \InvalidArgumentException($invalid);
        }
        $keys = array_keys($revisions);
        sort($keys, SORT_NUMERIC);
        if ($keys !== $ids || array_any($revisions, static fn(mixed $revision): bool => !is_string($revision))) {
            throw new \InvalidArgumentException($stale);
        }
    }
}
