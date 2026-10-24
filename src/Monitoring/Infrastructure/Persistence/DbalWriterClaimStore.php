<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\RrdDataSourceName;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Symfony\Component\Clock\ClockInterface;

/**
 * Model-driven claims in kadupul_writer_ownership, one row per storage field.
 * The primary key is the compare-and-set: of two writers inserting the same
 * field, one commits and the other gets a duplicate key and reports the field
 * as already claimed. A missing row means no claim, so the legacy answer
 * stands. Nothing here deletes a row; releasing a claim is not implemented.
 */
final readonly class DbalWriterClaimStore implements WriterClaimStore
{
    private const string SELECT = 'SELECT local_data_id, data_source_name, writer FROM kadupul_writer_ownership';
    private const string INSERT = 'INSERT INTO kadupul_writer_ownership (local_data_id, data_source_name, writer, claimed_at) VALUES (?, ?, ?, ?)';

    public function __construct(private Connection $database, private ClockInterface $clock) {}

    public function holds(StorageField $field): bool
    {
        $rows = $this->database->fetchAllAssociative(self::SELECT . ' WHERE local_data_id = ? AND data_source_name = ?', [$field->localDataId, $field->name->value]);
        foreach ($rows as $row) {
            self::field($row);
        }

        return $rows !== [];
    }

    public function record(StorageField $field): bool
    {
        try {
            $this->database->executeStatement(self::INSERT, [
                $field->localDataId,
                $field->name->value,
                WriterOwnership::ModelDriven->value,
                \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function all(): array
    {
        return array_map(self::field(...), $this->database->fetchAllAssociative(self::SELECT . ' ORDER BY local_data_id, data_source_name'));
    }

    /**
     * Only model-driven claims are ever written. Any other writer means the
     * row was changed by hand, and trusting it could hand a field to the
     * wrong writer, so it is reported instead.
     *
     * @param array<string, mixed> $row
     */
    private static function field(array $row): StorageField
    {
        if ($row['writer'] !== WriterOwnership::ModelDriven->value) {
            throw new StoredBindingUnreadable(sprintf('The kadupul_writer_ownership row for %s:%s names writer "%s"; only model_driven claims are recorded.', (string) $row['local_data_id'], (string) $row['data_source_name'], (string) $row['writer']));
        }
        try {
            return new StorageField((int) $row['local_data_id'], new RrdDataSourceName((string) $row['data_source_name']));
        } catch (InvalidBinding $e) {
            throw new StoredBindingUnreadable('A kadupul_writer_ownership row cannot be read: ' . $e->getMessage(), 0, $e);
        }
    }
}
