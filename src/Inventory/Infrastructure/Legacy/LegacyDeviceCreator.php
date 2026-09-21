<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceCreator;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewDevice;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceCreator implements DeviceCreator
{
    public function __construct(private string $projectDir) {}

    public function create(int $userId, NewDevice $device): int
    {
        $process = new Process([PHP_BINDIR . '/php', $this->projectDir . '/bin/legacy-device-create.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['actor' => $userId, 'fields' => $device->fields], JSON_THROW_ON_ERROR));
        try {
            $process->run();
        } catch (\Throwable) {
            throw new \RuntimeException('Device creation outcome is uncertain. Check the device list before retrying.');
        }
        if (!preg_match('/KADUPUL_CREATE_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Device creation outcome is uncertain. Check the device list before retrying.');
        }
        try {
            $result = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Device creation outcome is uncertain. Check the device list before retrying.');
        }
        if (($result['status'] ?? '') === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if (($result['status'] ?? '') === 'invalid') {
            throw new \InvalidArgumentException('Device settings or selected references are no longer valid. Reload the form and check the configured credentials.');
        }
        if (!$process->isSuccessful() || ($result['status'] ?? '') !== 'ok' || !is_int($result['id'] ?? null) || $result['id'] < 1) {
            throw new \RuntimeException('Device creation outcome is uncertain. Check the device list before retrying.');
        }

        return $result['id'];
    }
}
