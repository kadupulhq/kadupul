<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Domain\BindingConflict;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingOrigin;
use Kadupul\Monitoring\Domain\BindingProvenance;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Monitoring\Domain\RrdDataSourceName;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * Metric bindings in kadupul_metric_binding. The primary key is the binding
 * key, so one row exists per key whatever runs concurrently. Pack name and
 * version are part of the definition, not the key: a key bound by two packs
 * is the duplicate RegisterBinding refuses. Database errors are not caught,
 * and a refused save changes nothing.
 */
final readonly class DbalBindingRepository implements BindingRepository
{
    private const string SELECT = 'SELECT device_id, metric, resource_kind, resource_identity, protocol, pack_name, pack_version,
        local_data_id, data_source_name, expected_type, status, origin, source_detail, recorded_at, last_verified_at
        FROM kadupul_metric_binding';
    private const string BY_KEY = ' WHERE device_id = ? AND metric = ? AND resource_kind = ? AND resource_identity = ? AND protocol = ?';
    private const string ORDER = ' ORDER BY device_id, metric, resource_kind, resource_identity, protocol';
    private const string INSERT = 'INSERT INTO kadupul_metric_binding (pack_name, pack_version, local_data_id, data_source_name, expected_type,
        status, origin, source_detail, recorded_at, last_verified_at, device_id, metric, resource_kind, resource_identity, protocol)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    private const string UPDATE = 'UPDATE kadupul_metric_binding SET pack_name = ?, pack_version = ?, local_data_id = ?, data_source_name = ?,
        expected_type = ?, status = ?, origin = ?, source_detail = ?, recorded_at = ?, last_verified_at = ?';
    // Column sizes in bytes, from cacti.sql. A longer value is refused here
    // because a non-strict session would truncate it silently.
    private const array WIDTHS = ['metric' => 255, 'resource kind' => 64, 'resource identity' => 255, 'pack name' => 64, 'pack version' => 64, 'source detail' => 65535];
    private const string TIME = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $database) {}

    public function matching(BindingKey $key): array
    {
        return $this->select(self::BY_KEY, self::key($key));
    }

    public function writingTo(StorageField $field): array
    {
        return $this->select(' WHERE local_data_id = ? AND data_source_name = ?', [$field->localDataId, $field->name->value]);
    }

    public function forDevice(int $deviceId): array
    {
        return $this->select(' WHERE device_id = ?', [$deviceId]);
    }

    public function save(MetricBinding $binding): void
    {
        $values = [...self::definition($binding), ...self::key($binding->key)];
        try {
            $this->database->transactional(function (Connection $database) use ($binding, $values): void {
                // The row lock makes the replacement check and the write one step.
                $stored = $database->fetchAllAssociative(self::SELECT . self::BY_KEY . ' FOR UPDATE', self::key($binding->key));
                if ($stored === []) {
                    $database->executeStatement(self::INSERT, $values);

                    return;
                }
                $binding->assertMayReplace(self::binding($stored[0]));
                $database->executeStatement(self::UPDATE . self::BY_KEY, $values);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BindingConflict(sprintf('Binding %s was stored by another caller at the same time; reload it and retry.', $binding->key->bindingId()), 0, $e);
        }
    }

    /**
     * @param list<int|string> $parameters
     * @return list<MetricBinding>
     */
    private function select(string $where, array $parameters): array
    {
        return array_map(self::binding(...), $this->database->fetchAllAssociative(self::SELECT . $where . self::ORDER, $parameters));
    }

    /** @return list<int|string> */
    private static function key(BindingKey $key): array
    {
        return [$key->deviceId, $key->metric->toString(), $key->resource->kind, $key->resource->identity, $key->protocol];
    }

    /** @return list<int|string|null> */
    private static function definition(MetricBinding $binding): array
    {
        foreach ([
            'metric' => $binding->key->metric->toString(),
            'resource kind' => $binding->key->resource->kind,
            'resource identity' => $binding->key->resource->identity,
            'pack name' => $binding->packName,
            'pack version' => $binding->packVersion,
            'source detail' => $binding->provenance->sourceDetail,
        ] as $name => $value) {
            if (strlen($value) > self::WIDTHS[$name]) {
                throw new InvalidBinding(sprintf('Binding %s has a %s of %d bytes; at most %d can be stored.', $binding->key->bindingId(), $name, strlen($value), self::WIDTHS[$name]));
            }
        }

        return [
            $binding->packName,
            $binding->packVersion,
            $binding->field->localDataId,
            $binding->field->name->value,
            $binding->expectedType->value,
            $binding->status->value,
            $binding->provenance->origin->value,
            $binding->provenance->sourceDetail,
            self::time($binding->provenance->recordedAt),
            $binding->lastVerifiedAt === null ? null : self::time($binding->lastVerifiedAt),
        ];
    }

    private static function time(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }

    /** @param array<string, mixed> $row */
    private static function binding(array $row): MetricBinding
    {
        try {
            return new MetricBinding(
                new BindingKey((int) $row['device_id'], new MetricId((string) $row['metric']), new ResourceId((string) $row['resource_kind'], (string) $row['resource_identity']), (string) $row['protocol']),
                (string) $row['pack_name'],
                (string) $row['pack_version'],
                new StorageField((int) $row['local_data_id'], new RrdDataSourceName((string) $row['data_source_name'])),
                RrdDataSourceType::from((string) $row['expected_type']),
                BindingStatus::from((string) $row['status']),
                new BindingProvenance(BindingOrigin::from((string) $row['origin']), (string) $row['source_detail'], self::stored((string) $row['recorded_at'])),
                $row['last_verified_at'] === null ? null : self::stored((string) $row['last_verified_at']),
            );
        } catch (\InvalidArgumentException|\ValueError $e) {
            throw new StoredBindingUnreadable(sprintf('A kadupul_metric_binding row for device %s cannot be read: %s', (string) $row['device_id'], $e->getMessage()), 0, $e);
        }
    }

    private static function stored(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!' . self::TIME, $value, new \DateTimeZone('UTC'))
            ?: throw new \InvalidArgumentException(sprintf('"%s" is not a stored time.', $value));
    }
}
