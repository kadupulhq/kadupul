<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use PDO;
use Symfony\Component\Process\Process;

/** Configured executable and fail-closed protocol shared by assignment adapters. */
final class DeviceAssignmentProcess
{
    public static function run(PDO $database, string $projectDir, string $kind, array $command): void
    {
        if (!in_array($kind, ['collector', 'template', 'associations'], true)) {
            throw new \InvalidArgumentException('Unknown assignment worker.');
        }
        $label = ucfirst($kind);
        $configured = $database->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $projectDir . '/bin/legacy-device-' . $kind . '.php'], $projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode($command, JSON_THROW_ON_ERROR));
        $process->run();
        $marker = 'KADUPUL_' . strtoupper($kind) . '_RESULT';
        if (!preg_match('/' . $marker . '=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException($label . ' assignment outcome is unknown.');
        }
        try {
            $status = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['status'] ?? '';
        } catch (\JsonException) {
            throw new \RuntimeException($label . ' assignment outcome is unknown.');
        }
        if ($status === 'conflict') {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if ($status === 'invalid') {
            throw new \InvalidArgumentException('Select a valid device ' . $kind . '.');
        }
        if (!$process->isSuccessful() || $status !== 'ok') {
            throw new \RuntimeException($label . ' assignment could not be confirmed.');
        }
    }
}
