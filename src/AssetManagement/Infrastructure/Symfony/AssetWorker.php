<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Symfony;

use Kadupul\AssetManagement\Domain\AssetData;
use Doctrine\DBAL\Connection;
use Kadupul\AssetManagement\Application\Port\AssetWriter;
use Kadupul\AssetManagement\Domain\AssetError;
use Symfony\Component\Process\Process;

final readonly class AssetWorker implements AssetWriter
{
    public function __construct(private WebAssetAccess $access, private Connection $database, private string $projectDir) {}

    public function apply(array $command): array
    {
        $actor = $this->access->requireActor();
        $configured = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'path_php_binary'");
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $payload = json_encode(['actor_id' => $actor->id, 'actor_username' => $actor->username, 'command' => $command], JSON_THROW_ON_ERROR);
        if (strlen($payload) > 524288) {
            throw new AssetError('invalid_command');
        }
        $process = new Process([$binary, $this->projectDir . '/bin/console', 'assets:register', '--worker', '--no-interaction', '--no-ansi'], $this->projectDir, null, $payload, 60);
        try {
            $process->run();
            $result = json_decode(trim($process->getOutput()), true, 20, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AssetError('uncertain');
        }
        if (!is_array($result)) {
            throw new AssetError('uncertain');
        }
        if (!$process->isSuccessful() || ($result['status'] ?? null) !== 'ok') {
            throw new AssetError(is_string($result['reason'] ?? null) ? $result['reason'] : 'uncertain');
        }
        if (!is_array($result['result'] ?? null) || ($result['result']['request_id'] ?? null) !== ($command['request_id'] ?? null)) {
            throw new AssetError('uncertain');
        }
        return AssetData::result(AssetData::object($result['result']));
    }
}
