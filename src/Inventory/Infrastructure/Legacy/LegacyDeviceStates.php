<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceStates;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceStates implements DeviceStates, \Kadupul\Inventory\Application\Port\DeviceAutomation, \Kadupul\Inventory\Application\Port\DeviceSnmpSettings, \Kadupul\Inventory\Application\Port\DeviceBulkAssignments, \Kadupul\Inventory\Application\Port\DeviceOptions, \Kadupul\Inventory\Application\Port\DeviceStatistics, \Kadupul\Inventory\Application\Port\DeviceTemplateSynchronization
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private string $projectDir) {}
    public static function state(array $row): DeviceState
    {
        return new DeviceState((int) $row['id'], (string) $row['description'], (string) $row['hostname'], $row['disabled'] !== 'on', (int) $row['site_id'], (int) $row['poller_id'], (int) $row['host_template_id'], array_map(static fn($value): string => (string) $value, array_intersect_key($row, \Kadupul\Inventory\Domain\DeviceOptionsChange::DEFAULTS)), array_map(static fn($value): string => (string) $value, array_intersect_key($row, \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::PUBLIC_DEFAULTS)));
    }
    public function findVisible(int $actorId, array $ids): array
    {
        $ids = DeviceSelection::validateIds($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.hostname, h.disabled, h.site_id, h.poller_id, h.host_template_id, h.location, h.device_threads, h.snmp_port, h.snmp_timeout, h.max_oids, h.bulk_walk_size, h.availability_method, h.ping_method, h.ping_port, h.ping_timeout, h.ping_retries, h.snmp_version, h.snmp_auth_protocol, h.snmp_priv_protocol, h.snmp_context, h.snmp_engine_id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id IN ($placeholders) AND h.deleted = '' AND (" . $this->visibility->predicate($actorId) . ') ORDER BY h.id');
        $query->execute($ids);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new DevicesNotFound();
        }
        return array_map(self::state(...), $rows);
    }
    public function setEnabled(int $actorId, DeviceSelection $selection, bool $enabled): void
    {
        $this->run($actorId, $selection, ['enabled' => $enabled]);
    }
    public function clearStatistics(int $actorId, DeviceSelection $selection): void
    {
        $this->run($actorId, $selection, ['operation' => 'clear-statistics']);
    }
    public function synchronizeTemplates(int $actorId, DeviceSelection $selection): void
    {
        $this->run($actorId, $selection, ['operation' => 'sync-template']);
    }
    public function changeOptions(int $actorId, DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceOptionsChange $change): void
    {
        $this->run($actorId, $selection, ['operation' => 'options', 'changes' => $change->fields]);
    }
    public function assign(int $actorId, DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceBulkAssignment $assignment): void
    {
        $this->run($actorId, $selection, ['operation' => 'assign', 'kind' => $assignment->kind, 'target' => $assignment->targetId]);
    }
    public function changeSnmp(int $actorId, DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceSnmpChange $change): void
    {
        $this->run($actorId, $selection, ['operation' => 'snmp', 'changes' => $change->fields]);
    }
    public function applyRules(int $actorId, DeviceSelection $selection): void
    {
        $this->run($actorId, $selection, ['operation' => 'automation']);
    }
    private function run(int $actorId, DeviceSelection $selection, array $operation): void
    {
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-device-state.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['actor' => $actorId, 'selection' => $selection->revisions] + $operation, JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/KADUPUL_STATE_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Device state change outcome is unknown.');
        }
        try {
            $status = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['status'] ?? '';
        } catch (\JsonException $error) {
            throw new \RuntimeException('Device state change outcome is unknown.', 0, $error);
        }
        if ($status === 'snmp_invalid') {
            throw new \InvalidArgumentException('SNMP settings and stored credentials are incompatible. Replace credentials or review the selected settings.');
        }
        if ($status === 'conflict') {
            throw new DeviceEditConflict('Selected devices changed. Reload the confirmation before saving.');
        }
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if ($status === 'missing') {
            throw new DevicesNotFound();
        }
        if (!$process->isSuccessful() || $status !== 'ok') {
            throw new \RuntimeException('Device state change could not be confirmed.');
        }
    }
}
