<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Port\RrdCheckStore;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckFilters;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckPage;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckProblem;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(RrdCheckStore::class)]
final readonly class DbalRrdCheckStore implements RrdCheckStore
{
    private const string FROM = ' FROM rrdcheck AS rc
        LEFT JOIN data_local AS dl ON rc.local_data_id = dl.id
        LEFT JOIN data_template_data AS dtd ON rc.local_data_id = dtd.local_data_id
        LEFT JOIN host AS h ON dl.host_id = h.id';
    private const array SORT_EXPRESSIONS = [
        'description' => 'h.description',
        'name_cache' => 'dtd.name_cache',
        'local_data_id' => 'rc.local_data_id',
        'message' => 'rc.message',
        'test_date' => 'rc.test_date',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private RrdCheckAccess $access,
        private AuditTrail $audit,
        private LegacyConfiguration $configuration,
    ) {}

    public function enabled(): bool
    {
        return $this->database->fetchOne("SELECT value FROM settings WHERE name = 'rrdcheck_enable'") === 'on';
    }

    public function defaultRows(): int
    {
        $rows = filter_var($this->database->fetchOne("SELECT value FROM settings WHERE name = 'num_rows_table'"), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        return $rows === false ? 25 : $rows;
    }

    public function count(): int
    {
        return (int) $this->database->fetchOne('SELECT COUNT(*) FROM rrdcheck');
    }

    public function list(RrdCheckFilters $filters): RrdCheckPage
    {
        // The database clock stamps each row, so age is measured against it too.
        if ($filters->age === 0) {
            $where = ' WHERE rc.test_date >= NOW() - INTERVAL 7200 SECOND';
            $params = [];
            $types = [];
        } else {
            $where = ' WHERE rc.test_date <= NOW() - INTERVAL ? SECOND';
            $params = [$filters->age];
            $types = [ParameterType::INTEGER];
        }
        if ($filters->filter !== '') {
            $where .= ' AND (rc.message LIKE ? OR dtd.name_cache LIKE ? OR h.description LIKE ?)';
            $like = '%' . $filters->filter . '%';
            array_push($params, $like, $like, $like);
            array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING);
        }
        $total = (int) $this->database->fetchOne('SELECT COUNT(*)' . self::FROM . $where, $params, $types);
        $order = self::SORT_EXPRESSIONS[$filters->sortColumn] . ' ' . $filters->sortDirection;
        $rows = $this->database->fetchAllAssociative(
            'SELECT rc.local_data_id, h.description, dtd.name_cache, rc.message, rc.test_date' . self::FROM . $where
                . ' ORDER BY ' . $order . ', rc.local_data_id ASC, rc.test_date ASC LIMIT ? OFFSET ?',
            [...$params, $filters->rows, ($filters->page - 1) * $filters->rows],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        $problems = array_map(static fn(array $row): RrdCheckProblem => new RrdCheckProblem(
            (int) $row['local_data_id'],
            $row['description'] === null || $row['description'] === '' ? null : (string) $row['description'],
            $row['name_cache'] === null || $row['name_cache'] === '' ? null : (string) $row['name_cache'],
            (string) $row['message'],
            (string) $row['test_date'],
        ), $rows);
        return new RrdCheckPage($problems, $total, $filters);
    }

    public function purge(int $actorId): int
    {
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $this->prepareWrite();
            // DELETE, not TRUNCATE: TRUNCATE commits implicitly, which would
            // release the authorization locks before the rows go and could
            // not be rolled back if the recheck failed.
            $this->database->beginTransaction();
            try {
                $this->access->assertCurrent($actorId);
                $decision = AuditEvent::ALLOWED;
                $outcome = AuditEvent::FAILED;
                $deleted = (int) $this->database->executeStatement('DELETE FROM rrdcheck');
                $this->database->commit();
            } catch (\Throwable $error) {
                if ($this->database->isTransactionActive()) {
                    $this->database->rollBack();
                }
                throw $error;
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $deleted;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'platform.rrdcheck.purge', 'rrdcheck', 'rrdcheck', $decision, $outcome));
            } catch (\Throwable) { /* Audit failure cannot change a confirmed persistence result. */
            }
        }
    }

    private function prepareWrite(): void
    {
        if ($this->database->isTransactionActive()) {
            throw new \RuntimeException('RRD check purge requires its own transaction.');
        }
        if (!$this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        if (($this->configuration->values()['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('RRD check purge requires the primary collector.');
        }
        foreach (['rrdcheck', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'] as $table) {
            // SHOW CREATE TABLE sees a temporary table that shadows the real one.
            $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $this->database->quoteIdentifier($table));
            if ($definition === false || !is_string($definition[1] ?? null) || preg_match('/\n\) ENGINE=InnoDB\b/i', $definition[1]) !== 1) {
                throw new \RuntimeException('RRD check purge requires transactional tables.');
            }
        }
        $this->database->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
}
