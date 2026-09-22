<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceRemovals;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceRemovals implements DeviceRemovals
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceStates $states, private string $projectDir) {}
    public function findVisible(int $actorId, array $ids): array
    {
        return array_map(fn($device) => DeviceRemovalSnapshot::read($this->database->get(), $device), $this->states->findVisible($actorId, $ids));
    }
    public function remove(int $actorId, DeviceSelection $selection, DeviceRemovalPolicy $policy): void
    {
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-device-remove.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['actor' => $actorId, 'selection' => $selection->revisions, 'policy' => $policy->value], JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/KADUPUL_REMOVE_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Device removal outcome is unknown.');
        }
        $status = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['status'] ?? '';
        if ($status === 'conflict') {
            throw new DeviceEditConflict('Selected devices or their graphs and data sources changed. Reload the confirmation before removing devices.');
        }
        if ($status === 'shared') {
            throw new DeviceEditConflict('Shared data sources or aggregate graphs must be detached before removing graphs and data sources.');
        }
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if ($status === 'missing') {
            throw new DevicesNotFound();
        }
        if (!$process->isSuccessful() || $status !== 'ok') {
            throw new \RuntimeException('Device removal could not be confirmed.');
        }
    }
}
