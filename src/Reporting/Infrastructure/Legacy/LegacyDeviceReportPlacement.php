<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Reporting\Infrastructure\Legacy;

use Kadupul\Reporting\Contract\DeviceReportPlacement;
use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;

final readonly class LegacyDeviceReportPlacement implements DeviceReportPlacement
{
    public function __construct(private DatabaseConnection $database, private ResourceAccess $access) {}
    public function destinations(int $actorId): array
    {
        $result = [];
        foreach ($this->database->get()->query('SELECT id, name, user_id FROM reports ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $report) {
            if ($this->access->canManageReport($actorId, (int) $report['user_id'])) {
                $result[(string) $report['id']] = $report['name'] . ' (#' . $report['id'] . ')';
            }
        }
        return $result;
    }
    public function place(int $actorId, array $deviceIds, int $reportId, int $timespan, int $alignment): void
    {
        $db = $this->database->get();
        if (!$db->inTransaction()) {
            throw new \RuntimeException('Report placement requires a transaction');
        }
        $query = $db->prepare('SELECT id, user_id FROM reports WHERE id = ? FOR UPDATE');
        $query->execute([$reportId]);
        $report = $query->fetch(PDO::FETCH_ASSOC);
        if (!$report || !$this->access->canManageReport($actorId, (int) $report['user_id'])) {
            throw new \RuntimeException('Report destination unavailable');
        }
        $query = $db->prepare('SELECT id FROM reports_items WHERE report_id = ? AND host_id = ? AND item_type = 5 AND timespan = ? FOR UPDATE');
        foreach ($deviceIds as $deviceId) {
            $query->execute([$reportId, $deviceId, $timespan]);
            // Existing placements retain their alignment and sequence, as before.
            if ($query->fetchColumn() === false) {
                $sequence = $db->prepare('SELECT COALESCE(MAX(sequence), 0) + 1 FROM reports_items WHERE report_id = ?');
                $sequence->execute([$reportId]);
                $insert = $db->prepare("INSERT INTO reports_items (report_id, item_type, host_template_id, site_id, host_id, graph_template_id, local_graph_id, timespan, align, sequence, item_text) SELECT ?, 5, host_template_id, site_id, id, -1, 0, ?, ?, ?, '' FROM host WHERE id = ? AND deleted = ''");
                if (!$insert->execute([$reportId, $timespan, $alignment, (int) $sequence->fetchColumn(), $deviceId]) || $insert->rowCount() !== 1) {
                    throw new \RuntimeException('Report placement failed');
                }
            }
            $query->execute([$reportId, $deviceId, $timespan]);
            if ($query->fetchColumn() === false) {
                throw new \RuntimeException('Report placement could not be confirmed');
            }
        }
    }
}
