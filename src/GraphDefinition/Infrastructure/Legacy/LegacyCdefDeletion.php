<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Legacy;

use Kadupul\Platform\Contract\CdefReferenceReadiness;

/** The legacy page retains its existing authorization and CSRF boundary. */
final class LegacyCdefDeletion
{
    public function __construct(private readonly \PDO $database, private readonly mixed $collectorId, private readonly ?CdefReferenceReadiness $readiness = null) {}

    /** @param list<int|string> $selection */
    public function delete(array $selection): void
    {
        if ($this->collectorId !== 1) {
            throw new \RuntimeException('CDEF deletion requires the explicitly configured primary collector.');
        }
        if ($this->database->inTransaction()) {
            throw new \RuntimeException('CDEF deletion cannot use a caller transaction.');
        }
        if (!array_is_list($selection) || $selection === [] || count($selection) > 5000) {
            throw new \InvalidArgumentException('Invalid CDEF selection.');
        }
        $ids = [];
        foreach ($selection as $id) {
            if ((!is_int($id) && (!is_string($id) || preg_match('/^[1-9][0-9]{0,7}$/D', $id) !== 1))
                || (int) $id < 1 || (int) $id > 16777215 || isset($ids[(int) $id])) {
                throw new \InvalidArgumentException('Invalid CDEF selection.');
            }
            $ids[(int) $id] = (int) $id;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        $driver = $this->database->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \RuntimeException('Unsupported CDEF deletion database.');
        }
        if ($driver === 'mysql') {
            if ($this->readiness === null) {
                throw new \RuntimeException('Native CDEF deletion requires an explicitly bound readiness check.');
            }
            $this->readiness->assertReady();
            $this->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $owned = false;
        $commitAttempted = false;
        try {
            $owned = $this->database->beginTransaction();
            if (!$owned || $this->database->errorCode() !== '00000') {
                throw new \RuntimeException('The CDEF deletion transaction could not be started.');
            }
            $statement = $this->query("SELECT id, `system` FROM cdef WHERE id IN ($placeholders) ORDER BY id$lock", $ids);
            $parents = $statement->fetchAll(\PDO::FETCH_ASSOC);
            if ($statement->errorCode() !== '00000') {
                throw new \RuntimeException('The CDEF deletion eligibility read could not be confirmed.');
            }
            if (array_map('intval', array_column($parents, 'id')) !== $ids) {
                throw new \RuntimeException('The selected CDEF definitions changed. Reload the selection.');
            }
            foreach ($parents as $parent) {
                if (!in_array($parent['system'], [0, '0'], true)) {
                    throw new \RuntimeException('A selected CDEF is not eligible for deletion.');
                }
            }
            $this->query("DELETE FROM cdef_items WHERE cdef_id IN ($placeholders)", $ids);
            foreach (['graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
                $rows = $this->readColumn("SELECT cdef_id FROM `$table` WHERE cdef_id IN ($placeholders) LIMIT 1$lock", $ids);
                if ($rows !== []) {
                    throw new \RuntimeException('A selected CDEF is still referenced.');
                }
            }
            $value = $driver === 'mysql' ? 'BINARY value' : 'value';
            $rows = $this->readColumn("SELECT cdef_id FROM cdef_items WHERE type=5 AND $value IN ($placeholders) LIMIT 1$lock", array_map('strval', $ids));
            if ($rows !== []) {
                throw new \RuntimeException('A selected CDEF is still referenced by another definition.');
            }
            if ($this->query("DELETE FROM cdef WHERE id IN ($placeholders)", $ids)->rowCount() !== count($ids)) {
                throw new \RuntimeException('The selected CDEF deletion could not be confirmed.');
            }
            $commitAttempted = true;
            if (!$this->database->commit() || $this->database->errorCode() !== '00000') {
                throw new \RuntimeException('The CDEF deletion commit could not be confirmed.');
            }
        } catch (\Throwable $error) {
            try {
                if ($owned && $this->database->inTransaction()) {
                    if (!$this->database->rollBack() || $this->database->errorCode() !== '00000') {
                        throw new \RuntimeException('The CDEF deletion rollback could not be confirmed.');
                    }
                }
            } catch (\Throwable $rollbackError) {
                throw new \RuntimeException('CDEF deletion could not be confirmed. Reload before retrying.', 0, $rollbackError);
            }
            if ($commitAttempted) {
                throw new \RuntimeException('CDEF deletion could not be confirmed. Reload before retrying.', 0, $error);
            }
            throw $error;
        }
    }

    /** @param list<int|string> $parameters
     * @return list<mixed>
     */
    private function readColumn(string $sql, array $parameters): array
    {
        $statement = $this->query($sql, $parameters);
        $rows = $statement->fetchAll(\PDO::FETCH_COLUMN);
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException('The CDEF deletion dependency read could not be confirmed.');
        }

        return $rows;
    }

    /** @param list<int|string> $parameters */
    private function query(string $sql, array $parameters): \PDOStatement
    {
        $statement = $this->database->prepare($sql);
        if ($statement === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('The CDEF deletion SQL could not be prepared.');
        }
        foreach ($parameters as $index => $value) {
            if (!$statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR)) {
                throw new \RuntimeException('The CDEF deletion identity could not be bound.');
            }
        }
        if (!$statement->execute() || $statement->errorCode() !== '00000') {
            throw new \RuntimeException('The CDEF deletion SQL could not be confirmed.');
        }
        return $statement;
    }

    private function execute(string $sql): void
    {
        if ($this->database->exec($sql) === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('The CDEF deletion transaction setting could not be confirmed.');
        }
    }
}
