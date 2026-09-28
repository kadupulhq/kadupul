<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Collection\Application\Port\AutomationTemplateCatalog;
use Kadupul\Collection\Application\ReadModel\AutomationTemplatePage;
use Kadupul\Collection\Application\ReadModel\AutomationTemplateSummary;
use Kadupul\Collection\Domain\AutomationTemplateCriteria;

final readonly class DoctrineAutomationTemplateCatalog implements AutomationTemplateCatalog
{
    public function __construct(private Connection $database) {}

    public function list(AutomationTemplateCriteria $criteria): AutomationTemplatePage
    {
        $where = '';
        $parameters = [];
        if ($criteria->search !== '') {
            $where = ' WHERE ht.name LIKE ? OR at.sysDescr LIKE ? OR at.sysName LIKE ? OR at.sysOid LIKE ?';
            $pattern = '%' . $criteria->search . '%';
            $parameters = [$pattern, $pattern, $pattern, $pattern];
        }

        $sort = [
            'host_template' => 'ht.name',
            'availability' => 'at.availability_method',
            'sysDescr' => 'at.sysDescr',
            'sysName' => 'at.sysName',
            'sysOid' => 'at.sysOid',
            'sequence' => 'at.sequence',
        ][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $rows = $this->database->fetchAllAssociative("SELECT at.id, ht.name AS host_template_name, at.availability_method,
                at.sysDescr, at.sysName, at.sysOid, at.sequence
            FROM automation_templates at
            LEFT JOIN host_template ht ON ht.id = at.host_template
            $where
            ORDER BY $sort $direction, at.id $direction
            LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1), $parameters);

        $templates = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $templates[] = new AutomationTemplateSummary(
                (int) $row['id'],
                (string) ($row['host_template_name'] ?? ''),
                self::availabilityMethod((int) ($row['availability_method'] ?? -1)),
                (string) ($row['sysDescr'] ?? ''),
                (string) ($row['sysName'] ?? ''),
                (string) ($row['sysOid'] ?? ''),
                (int) ($row['sequence'] ?? 0)
            );
        }

        return new AutomationTemplatePage($templates, count($rows) > $criteria->pageSize);
    }

    private static function availabilityMethod(int $method): string
    {
        return match ($method) {
            0 => 'None',
            1 => 'Ping and SNMP Uptime',
            2 => 'SNMP Uptime',
            3 => 'Ping',
            4 => 'Ping or SNMP Uptime',
            5 => 'SNMP Desc',
            6 => 'SNMP getNext',
            default => 'Unknown',
        };
    }
}
