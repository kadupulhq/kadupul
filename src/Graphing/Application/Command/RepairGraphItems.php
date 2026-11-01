<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GraphItemRepairs;
use Kadupul\Graphing\Application\Port\RepairRrdTarget;

/**
 * Points graph items of one graph template back at their device's data source RRD item, as repair_graphs.php did.
 * With $execute false it reports the same findings and writes nothing.
 */
final readonly class RepairGraphItems
{
    private const string ACTION = 'graph.item.repair';

    public function __construct(private GraphCommandAccess $access, private GraphItemRepairs $repairs) {}

    /**
     * @param ?int $hostId limit the repair to one device
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param bool $execute false reads the same rows and changes none
     * @throws GraphManagementDenied
     */
    public function __invoke(int $graphTemplateId, int $dataTemplateId, ?int $hostId, bool $local, ?string $operator, bool $execute): GraphRepairReport
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, $execute);
        $graphs = $this->repairs->graphs($scope->database, $graphTemplateId, $hostId);
        $correlation = $this->access->correlation();
        $targets = [];
        $entries = [];
        $ok = false;
        try {
            foreach ($graphs as $graph) {
                // Graphs on one device share its data source, so it is read once per device.
                $target = array_key_exists($graph->hostId, $targets) ? $targets[$graph->hostId] : ($targets[$graph->hostId] = $this->repairs->rrdTarget($scope->database, $graph->hostId, $dataTemplateId));
                if (!$target instanceof RepairRrdTarget) {
                    continue;
                }
                if ($target->rrdId === null) {
                    $entries[] = GraphRepairEntry::withoutRrd($target->dataSourceId);

                    continue;
                }
                $wrong = $this->repairs->wrongItems($scope->database, $graph->id, $graphTemplateId, $dataTemplateId, $target->rrdId);
                if ($wrong === []) {
                    continue;
                }
                $entry = GraphRepairEntry::repoint($graph->hostId, $graph->id, $graphTemplateId, $target->rrdId, $wrong);
                $entries[] = $entry;
                if ($execute) {
                    $this->repairs->repoint($scope->database, $graph->id, $graphTemplateId, $target->rrdId, $entry->itemIds);
                }
            }
            $ok = true;
        } finally {
            if ($execute) {
                $this->access->change($correlation, $scope, self::ACTION, 'graph-template', (string) $graphTemplateId, $ok);
            }
        }

        return new GraphRepairReport($scope->database, count($graphs), $entries, $execute);
    }
}
