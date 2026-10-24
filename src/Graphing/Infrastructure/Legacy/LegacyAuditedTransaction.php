<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;

/**
 * The write transaction the Color, GPRINT Preset and Color Template stores
 * share. On MySQL the primary collector and InnoDB participants are checked
 * before it opens, the actor is rechecked inside it, a rollback must be
 * confirmed before the original error is rethrown, and every attempt is
 * audited. Messages are built from the store's own nouns, for example
 * "GPRINT transaction unavailable." and "GPRINT operation commit was not
 * confirmed.", so each store reports exactly what it reported before.
 */
final readonly class LegacyAuditedTransaction
{
    /**
     * @param class-string<PaletteSql|GprintPresetSql|ColorTemplateSql> $sql
     * @param list<string> $tables every table the store writes or locks, including temporary shadows
     * @param string $subject noun for transaction, table and isolation errors
     * @param string $operation noun for commit and rollback errors
     * @param string $plural noun for the primary collector error
     * @param string $auditAction audit action prefix; the store's action follows a dot
     */
    public function __construct(
        private DatabaseConnection $database,
        private PaletteColorAccess|GprintPresetAccess|ColorTemplateAccess $access,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
        private string $sql,
        private array $tables,
        private string $subject,
        private string $operation,
        private string $plural,
        private string $auditAction,
        private string $auditType,
    ) {}

    public function run(int $actorId, string $action, string $target, callable $operation): mixed
    {
        $db = $this->database->get();
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        $started = false;
        try {
            if ($db->inTransaction()) {
                throw new \RuntimeException($this->subject . ' transaction unavailable.');
            }
            $this->assertTransactional($db);
            if (!$db->beginTransaction()) {
                throw new \RuntimeException($this->subject . ' transaction unavailable.');
            }
            $started = true;
            $this->access->assertCurrent($actorId);
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $result = $operation($db);
            if (!$db->commit()) {
                throw new \RuntimeException($this->operation . ' commit was not confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } catch (\Throwable $error) {
            if ($started && $db->inTransaction()) {
                try {
                    $rolledBack = $db->rollBack();
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException($this->operation . ' rollback was not confirmed.', 0, $rollbackError);
                }
                if (!$rolledBack) {
                    throw new \RuntimeException($this->operation . ' rollback was not confirmed.', 0, $error);
                }
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, $this->auditAction . '.' . $action, $this->auditType, $target, $decision, $outcome));
            } catch (\Throwable) {
                // Audit failure does not change the confirmed persistence outcome.
            }
        }
    }

    private function assertTransactional(\PDO $db): void
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException($this->plural . ' must be changed on the primary collector.');
        }
        foreach ($this->tables as $table) {
            // Check the actual connection table, including temporary shadows.
            $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
            $definition = $query === false ? false : $this->sql::one($query, \PDO::FETCH_NUM);
            if ($definition === false || !is_string($definition[1] ?? null)
                || preg_match('/\ACREATE TABLE /i', $definition[1]) !== 1
                || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException($this->subject . ' writes require transactional tables.');
            }
        }
        // Keep the dependency and reference range locks when the session
        // default is READ COMMITTED.
        if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new \RuntimeException($this->subject . ' transaction isolation was not confirmed.');
        }
    }
}
