<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DevicePlacements;
use Kadupul\Inventory\Domain\DevicePlacement;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDevicePlacements implements DevicePlacements
{
    public function __construct(private DatabaseConnection $database, private string $projectDir) {}
    public function place(int $actorId, DeviceSelection $selection, DevicePlacement $placement): void
    {
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINARY;
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-device-placement.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['actor' => $actorId, 'selection' => $selection->revisions, 'kind' => $placement->kind, 'destination' => $placement->destination, 'timespan' => $placement->timespan, 'alignment' => $placement->alignment], JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/KADUPUL_PLACEMENT_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Placement outcome is unknown');
        }
        try {
            $status = json_decode($match[1], true, 8, JSON_THROW_ON_ERROR)['status'] ?? '';
        } catch (\JsonException $error) {
            throw new \RuntimeException('Placement outcome is unknown', 0, $error);
        }
        if ($status === 'conflict') {
            throw new DeviceEditConflict('Selected devices changed. Reload the confirmation before saving.');
        }
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if (!$process->isSuccessful() || $status !== 'ok') {
            throw new \RuntimeException('Placement could not be confirmed');
        }
    }
}
