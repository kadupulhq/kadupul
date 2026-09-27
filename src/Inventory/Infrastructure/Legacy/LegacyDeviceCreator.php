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
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyDeviceCreator implements DeviceCreator
{
    public function __construct(private string $projectDir, private DatabaseConnection $database) {}

    public function create(int $userId, NewDevice $device): int
    {
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-device-create.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['correlation_id' => bin2hex(random_bytes(16)), 'actor' => $userId, 'fields' => $device->fields], JSON_THROW_ON_ERROR));
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
