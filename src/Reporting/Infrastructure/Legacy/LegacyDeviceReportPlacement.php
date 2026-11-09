<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Reporting\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Reporting\Contract\DeviceReportPlacement;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DeviceReportPlacement::class)]
final readonly class LegacyDeviceReportPlacement implements DeviceReportPlacement
{
    /** Device items (reports_items.item_type) added by the bulk device action. */
    private const DEVICE_ITEM = 5;

    public function __construct(private DatabaseConnection $database, private ResourceAccess $access) {}

    public function defaultTimespan(int $actorId): int
    {
        $query = $this->database->get()->prepare("SELECT value FROM settings_user WHERE user_id = ? AND name = 'default_timespan'");
        $query->execute([$actorId]);
        $value = filter_var($query->fetchColumn(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);

        return $value === false ? 7 : $value;
    }

    public function destinations(int $actorId): array
    {
        $reports = $this->database->get()->query('SELECT id, name, user_id FROM reports ORDER BY name, id');
        if ($reports === false) {
            throw new \RuntimeException('Report destinations unavailable');
        }
        $own = $this->access->canManageReport($actorId, $actorId);
        $others = $this->access->canManageReport($actorId, 0);
        $result = [];
        foreach (self::rows($reports) as $report) {
            $id = self::scalar($report['id']);
            if ((int) self::scalar($report['user_id']) === $actorId ? $own : $others) {
                $result[$id] = self::scalar($report['name']) . ' (#' . $id . ')';
            }
        }

        return $result;
    }

    public function place(int $actorId, array $deviceIds, int $reportId, int $timespan, int $alignment): array
    {
        $db = $this->database->get();
        if (!$db->inTransaction()) {
            throw new \RuntimeException('Report placement requires a transaction');
        }
        $query = $db->prepare('SELECT user_id FROM reports WHERE id = ? FOR UPDATE');
        $query->execute([$reportId]);
        $owner = $query->fetchColumn();
        if ($owner === false || !$this->access->canManageReport($actorId, (int) self::scalar($owner))) {
            throw new \RuntimeException('Report destination unavailable');
        }
        $existing = $db->prepare('SELECT id FROM reports_items WHERE report_id = ? AND host_id = ? AND item_type = ' . self::DEVICE_ITEM . ' AND timespan = ? FOR UPDATE');
        $sequence = $db->prepare('SELECT COALESCE(MAX(sequence), 0) + 1 FROM reports_items WHERE report_id = ?');
        $insert = $db->prepare('INSERT INTO reports_items (report_id, item_type, host_template_id, site_id, host_id, graph_template_id, local_graph_id, timespan, align, sequence, item_text)'
            . ' SELECT ?, ' . self::DEVICE_ITEM . ", host_template_id, site_id, id, -1, 0, ?, ?, ?, '' FROM host WHERE id = ? AND deleted = ''");
        foreach ($deviceIds as $deviceId) {
            $existing->execute([$reportId, $deviceId, $timespan]);
            // Existing placements retain their alignment and sequence, as before.
            if ($existing->fetchColumn() === false) {
                $sequence->execute([$reportId]);
                $next = (int) self::scalar($sequence->fetchColumn());
                if (!$insert->execute([$reportId, $timespan, $alignment, $next, $deviceId]) || $insert->rowCount() !== 1) {
                    throw new \RuntimeException('Report placement failed');
                }
            }
            $existing->execute([$reportId, $deviceId, $timespan]);
            if ($existing->fetchColumn() === false) {
                throw new \RuntimeException('Report placement could not be confirmed');
            }
        }

        return $this->records($deviceIds, $reportId, $timespan);
    }

    public function verify(int $actorId, array $deviceIds, int $reportId, int $timespan, int $alignment, array $expected): void
    {
        if ($this->records($deviceIds, $reportId, $timespan) !== $expected) {
            throw new \RuntimeException('Placement changed during callbacks');
        }
        $db = $this->database->get();
        $query = $db->prepare('SELECT user_id FROM reports WHERE id = ?');
        $query->execute([$reportId]);
        $owner = $query->fetchColumn();
        if ($owner === false || !$this->access->canManageReport($actorId, (int) self::scalar($owner))) {
            throw new \RuntimeException('Report destination changed');
        }
        $query = $db->prepare('SELECT COUNT(*) FROM reports_items WHERE report_id = ? AND host_id = ? AND item_type = ' . self::DEVICE_ITEM . ' AND timespan = ?');
        foreach ($deviceIds as $deviceId) {
            $query->execute([$reportId, $deviceId, $timespan]);
            if ((int) self::scalar($query->fetchColumn()) < 1) {
                throw new \RuntimeException('Report placement could not be confirmed');
            }
        }
    }

    /**
     * @param non-empty-list<int> $deviceIds
     * @return list<array<string, mixed>>
     */
    private function records(array $deviceIds, int $reportId, int $timespan): array
    {
        $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
        $query = $this->database->get()->prepare('SELECT id, report_id, item_type, host_id, host_template_id, site_id, graph_template_id, local_graph_id, timespan, align, sequence, item_text'
            . ' FROM reports_items WHERE report_id = ? AND timespan = ? AND item_type = ' . self::DEVICE_ITEM . " AND host_id IN ($placeholders) ORDER BY id");
        $query->execute([$reportId, $timespan, ...$deviceIds]);

        return self::rows($query);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(\PDOStatement $statement): array
    {
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException('Unexpected report row');
            }
            /** @var array<string, mixed> $row FETCH_ASSOC keys are column names. */
            $rows[] = $row;
        }

        return $rows;
    }

    /** Drivers return integers, floats, strings or NULL; NULL reads as '' as it did when concatenated or cast. */
    private static function scalar(mixed $value): string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \UnexpectedValueException('Unexpected report column value');
        }

        return (string) $value;
    }
}
